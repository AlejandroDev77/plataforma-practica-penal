from __future__ import annotations

import json

import httpx
import pytest
from pydantic import SecretStr, ValidationError

from app.core.config import Settings
from app.modules.simulation.openai_service import OpenAISimulationService, proposal_schema
from app.modules.simulation.schemas import SimulationProposalRequest
from app.modules.simulation.service import SimulationService, SimulationServiceError
from tests.test_simulation_service import model_response, sample_request


async def test_suite_blocks_real_openai_http_even_if_a_test_forgets_its_mock() -> None:
    async with httpx.AsyncClient() as client:
        with pytest.raises(AssertionError, match="HTTP real bloqueado"):
            await client.get("https://api.openai.com/v1/responses")


def settings(**overrides: object) -> Settings:
    return Settings(
        _env_file=None,
        **{
            "simulation_provider": "openai",
            "openai_api_key": SecretStr("clave-ficticia"),
            "openai_model": "gpt-4o-mini",
            **overrides,
        },
    )


def request_context(**overrides: object) -> SimulationProposalRequest:
    return SimulationProposalRequest.model_validate(
        {
            **sample_request().model_dump(),
            "allow_external_processing": True,
            **overrides,
        }
    )


def envelope(proposal: dict[str, object] | None = None) -> dict[str, object]:
    return {
        "status": "completed",
        "output": [
            {
                "type": "message",
                "role": "assistant",
                "status": "completed",
                "content": [
                    {"type": "output_text", "text": json.dumps(proposal or model_response([17]))}
                ],
            }
        ],
    }


@pytest.mark.parametrize("consent", [False, None])
async def test_no_request_can_leave_the_device_without_explicit_consent(
    consent: bool | None,
) -> None:
    data = sample_request().model_dump()
    if consent is not None:
        data["allow_external_processing"] = consent
    context = SimulationProposalRequest.model_validate(data)

    def forbidden(_: httpx.Request) -> httpx.Response:
        pytest.fail("No debe haber tráfico externo sin autorización.")

    with pytest.raises(SimulationServiceError) as error:
        await SimulationService(settings(), httpx.MockTransport(forbidden)).propose(context)
    assert error.value.code == "external_processing_not_authorized"
    assert error.value.status_code == 422


@pytest.mark.parametrize("value", ["true", 1, "1", [], {}])
def test_consent_is_a_strict_boolean(value: object) -> None:
    with pytest.raises(ValidationError):
        request_context(allow_external_processing=value)


async def test_openai_receives_only_the_bounded_context_and_strict_schema_once() -> None:
    calls: list[httpx.Request] = []

    def handle(request: httpx.Request) -> httpx.Response:
        calls.append(request)
        assert str(request.url) == "https://api.openai.com/v1/responses"
        assert request.headers["Authorization"] == "Bearer clave-ficticia"
        return httpx.Response(200, json=envelope())

    result = await SimulationService(settings(), httpx.MockTransport(handle)).propose(
        request_context()
    )
    assert result.used_source_ids == [17]
    assert len(calls) == 1
    body = json.loads(calls[0].content)
    assert body["model"] == "gpt-4o-mini"
    assert body["store"] is False
    assert body["max_output_tokens"] == 1200
    assert "No inventes nombres, fechas, pruebas" in body["input"][0]["content"]
    context = json.loads(body["input"][1]["content"])
    assert set(context) == {
        "actor_role",
        "phase",
        "turn_instruction",
        "visible_facts",
        "sources",
        "transcript",
    }
    assert "sim-42" not in json.dumps(body)
    assert "participant-3" not in json.dumps(body)
    assert "clave-ficticia" not in json.dumps(body)
    assert body["text"]["format"]["strict"] is True
    schema = body["text"]["format"]["schema"]
    assert schema["additionalProperties"] is False
    assert set(schema["required"]) == set(schema["properties"])
    assert schema["properties"]["speaker_role"]["enum"] == ["juez"]
    assert schema["properties"]["requires_human_review"]["enum"] == [True]
    assert schema["properties"]["used_source_ids"]["items"]["enum"] == [17]


def test_request_without_sources_produces_an_empty_reference_schema() -> None:
    request = request_context(sources=[], visible_facts=[])
    assert proposal_schema(request)["properties"]["used_source_ids"]["maxItems"] == 0


@pytest.mark.parametrize("key", [None, SecretStr(" ")])
async def test_missing_key_is_reported_before_any_network_call(key: SecretStr | None) -> None:
    def forbidden(_: httpx.Request) -> httpx.Response:
        pytest.fail("No debe haber tráfico externo sin clave.")

    with pytest.raises(SimulationServiceError) as error:
        await OpenAISimulationService(
            settings(openai_api_key=key), httpx.MockTransport(forbidden)
        ).propose(request_context())
    assert error.value.code == "openai_api_key_not_configured"


@pytest.mark.parametrize(
    "status,code,returned_status",
    [
        (401, "openai_authentication_failed", 503),
        (403, "openai_authentication_failed", 503),
        (429, "openai_rate_limited", 429),
        (500, "openai_request_failed", 502),
        (302, "openai_request_failed", 502),
    ],
)
async def test_http_failures_are_safe_and_never_retry_or_follow_redirects(
    status: int,
    code: str,
    returned_status: int,
) -> None:
    calls: list[str] = []

    def handle(request: httpx.Request) -> httpx.Response:
        calls.append(str(request.url))
        return httpx.Response(
            status,
            headers={"Location": "https://otro-servidor.invalid"},
            json={"error": "clave-ficticia contenido-privado"},
        )

    with pytest.raises(SimulationServiceError) as error:
        await SimulationService(settings(), httpx.MockTransport(handle)).propose(request_context())
    assert error.value.code == code
    assert error.value.status_code == returned_status
    assert "clave-ficticia" not in error.value.message
    assert "contenido-privado" not in error.value.message
    assert calls == ["https://api.openai.com/v1/responses"]


@pytest.mark.parametrize(
    "exception,code,status",
    [
        (httpx.ConnectError("contenido-privado"), "openai_unavailable", 503),
        (httpx.ReadTimeout("contenido-privado"), "openai_timeout", 504),
    ],
)
async def test_connection_errors_are_sanitized(
    exception: Exception, code: str, status: int
) -> None:
    calls = 0

    def handle(_: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        raise exception

    with pytest.raises(SimulationServiceError) as error:
        await SimulationService(settings(), httpx.MockTransport(handle)).propose(request_context())
    assert error.value.code == code
    assert error.value.status_code == status
    assert "contenido-privado" not in error.value.message
    assert calls == 1


@pytest.mark.parametrize(
    "mutation,code",
    [
        ({"speaker_role": "fiscal"}, "openai_role_mismatch"),
        ({"used_source_ids": [999]}, "openai_unverifiable_sources"),
        ({"used_source_ids": [17, 17]}, "openai_invalid_response"),
        ({"requires_human_review": False}, "openai_invalid_response"),
        ({"content": ""}, "openai_invalid_response"),
        ({"content": "x" * 3001}, "openai_invalid_response"),
        ({"extra": "instrucción inyectada"}, "openai_invalid_response"),
    ],
)
async def test_generated_output_must_respect_the_role_sources_and_review_contract(
    mutation: dict[str, object],
    code: str,
) -> None:
    payload = envelope({**model_response([17]), **mutation})
    with pytest.raises(SimulationServiceError) as error:
        await SimulationService(
            settings(), httpx.MockTransport(lambda _: httpx.Response(200, json=payload))
        ).propose(request_context())
    assert error.value.code == code


@pytest.mark.parametrize("status", ["incomplete", "failed", "queued", "in_progress"])
async def test_even_parseable_output_is_rejected_if_the_response_is_incomplete(status: str) -> None:
    payload = {**envelope(), "status": status}
    with pytest.raises(SimulationServiceError) as error:
        await SimulationService(
            settings(), httpx.MockTransport(lambda _: httpx.Response(200, json=payload))
        ).propose(request_context())
    assert error.value.code == "openai_incomplete_response"


async def test_refusal_cannot_be_hidden_after_a_valid_json_block() -> None:
    payload = envelope()
    payload["output"][0]["content"].append({"type": "refusal", "refusal": "contenido-privado"})
    with pytest.raises(SimulationServiceError) as error:
        await SimulationService(
            settings(), httpx.MockTransport(lambda _: httpx.Response(200, json=payload))
        ).propose(request_context())
    assert error.value.code == "openai_refusal"
    assert "contenido-privado" not in error.value.message


async def test_malformed_or_multiple_messages_are_rejected() -> None:
    for payload in (
        {"status": "completed", "output": []},
        {**envelope(), "output": envelope()["output"] * 2},
    ):
        with pytest.raises(SimulationServiceError) as error:
            await SimulationService(
                settings(),
                httpx.MockTransport(
                    lambda _, response_payload=payload: httpx.Response(200, json=response_payload)
                ),
            ).propose(request_context())
        assert error.value.code == "openai_invalid_response"


async def test_explicitly_disabled_provider_does_not_use_an_existing_openai_key() -> None:
    def forbidden(_: httpx.Request) -> httpx.Response:
        pytest.fail("El proveedor está desactivado.")

    with pytest.raises(SimulationServiceError) as error:
        await SimulationService(
            settings(simulation_provider="disabled"), httpx.MockTransport(forbidden)
        ).propose(request_context())
    assert error.value.code == "local_model_not_configured"


def test_provider_configuration_does_not_change_other_local_services() -> None:
    configured = settings(llm_provider="ollama", llm_model="local-prueba")
    assert configured.simulation_generation_provider == "openai"
    assert configured.llm_provider == "ollama"
    assert (
        settings(simulation_provider=None, llm_provider="disabled").simulation_generation_provider
        == "disabled"
    )
    assert Settings(_env_file=None).simulation_generation_provider == "disabled"


def test_invalid_provider_model_and_timeout_are_rejected() -> None:
    for overrides in (
        {"simulation_provider": "externo-desconocido"},
        {"openai_model": "modelo\nprivado"},
        {"openai_timeout_seconds": 0},
        {"simulation_provider": "ollama", "llm_model": None},
    ):
        with pytest.raises(ValidationError):
            settings(**overrides)
