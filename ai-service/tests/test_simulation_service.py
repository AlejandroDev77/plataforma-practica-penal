from __future__ import annotations

import json

import httpx
import pytest
from pydantic import ValidationError

from app.core.config import Settings
from app.modules.simulation.schemas import SimulationProposalRequest
from app.modules.simulation.service import LocalSimulationService, SimulationServiceError


def sample_request() -> SimulationProposalRequest:
    return SimulationProposalRequest(
        simulation_id="sim-42",
        actor_id="participant-3",
        actor_role="juez",
        phase="DEBATE",
        turn_instruction="Solicite a las partes que aclaren el punto discutido.",
        visible_facts=[
            {
                "text": "El informe policial refiere una aprehensión el 12 de mayo.",
                "kind": "declaracion",
                "certainty": "textual",
                "attribution": "Informe policial",
                "source_ids": [17],
            }
        ],
        sources=[
            {
                "id": 17,
                "kind": "expediente",
                "title": "Informe policial",
                "locator": "Página 2",
                "excerpt": "La aprehensión ocurrió el 12 de mayo.",
            }
        ],
        transcript=[{"role": "abogado_defensor", "content": "Solicito que se precise la hora."}],
    )


def model_response(source_ids: list[int] | None = None) -> dict[str, object]:
    return {
        "speaker_role": "juez",
        "content": "Juez: ¿Puede precisar qué antecedente respalda la hora indicada?",
        "used_source_ids": source_ids or [],
        "requires_human_review": True,
    }


def local_settings() -> Settings:
    return Settings(llm_provider="ollama", llm_model="qwen3.5:2b-q4_K_M")


def test_simulation_request_rejects_unknown_fields_and_duplicate_sources() -> None:
    data = sample_request().model_dump()
    data["system_prompt"] = "instrucción inyectada"
    with pytest.raises(ValidationError):
        SimulationProposalRequest.model_validate(data)

    data = sample_request().model_dump()
    data["sources"] = [data["sources"][0], data["sources"][0]]
    with pytest.raises(ValidationError, match="no puede repetirse"):
        SimulationProposalRequest.model_validate(data)


def test_simulation_request_caps_total_context() -> None:
    data = sample_request().model_dump()
    data["visible_facts"] = [
        {
            "text": "x" * 800,
            "kind": "declaracion",
            "certainty": "textual",
            "source_ids": [17],
        }
        for _ in range(20)
    ]
    data["transcript"] = [{"role": "abogado_defensor", "content": "y" * 2_000} for _ in range(3)]

    with pytest.raises(ValidationError, match="excede el límite"):
        SimulationProposalRequest.model_validate(data)


def test_simulation_facts_cannot_reference_unprovided_sources() -> None:
    data = sample_request().model_dump()
    data["visible_facts"][0]["source_ids"] = [999]

    with pytest.raises(ValidationError, match="fuera del contexto autorizado"):
        SimulationProposalRequest.model_validate(data)


async def test_generation_stays_disabled_by_default() -> None:
    service = LocalSimulationService(Settings())

    with pytest.raises(SimulationServiceError) as error:
        await service.propose(sample_request())

    assert error.value.status_code == 503
    assert error.value.code == "local_model_not_configured"


async def test_ollama_receives_bounded_role_scoped_context_and_strict_schema() -> None:
    captured: dict[str, object] = {}

    def handle(request: httpx.Request) -> httpx.Response:
        captured["url"] = str(request.url)
        captured["body"] = json.loads(request.content)
        return httpx.Response(
            200,
            json={"message": {"content": json.dumps(model_response([17]), ensure_ascii=False)}},
        )

    service = LocalSimulationService(local_settings(), transport=httpx.MockTransport(handle))
    proposal = await service.propose(sample_request())

    assert proposal.used_source_ids == [17]
    assert captured["url"] == "http://127.0.0.1:11434/api/chat"
    body = captured["body"]
    assert isinstance(body, dict)
    assert body["model"] == "qwen3.5:2b-q4_K_M"
    assert body["stream"] is False
    assert body["think"] is False
    assert body["format"]["type"] == "object"
    assert body["options"]["num_ctx"] == 16_384
    assert "No inventes nombres, fechas, pruebas" in body["messages"][0]["content"]
    assert "no que sea verdad ni esté probado" in body["messages"][0]["content"]
    assert '"actor_role": "juez"' in body["messages"][1]["content"]
    assert "sim-42" not in body["messages"][1]["content"]
    assert "participant-3" not in body["messages"][1]["content"]
    assert "Authorization" not in json.dumps(body)


async def test_generation_rejects_source_ids_not_in_authorized_context() -> None:
    service = LocalSimulationService(
        local_settings(),
        transport=httpx.MockTransport(
            lambda _: httpx.Response(
                200,
                json={"message": {"content": json.dumps(model_response([999]))}},
            )
        ),
    )

    with pytest.raises(SimulationServiceError) as error:
        await service.propose(sample_request())

    assert error.value.code == "local_model_unverifiable_sources"
    assert error.value.status_code == 502


async def test_generation_rejects_role_mismatch() -> None:
    invalid = model_response()
    invalid["speaker_role"] = "fiscal"
    service = LocalSimulationService(
        local_settings(),
        transport=httpx.MockTransport(
            lambda _: httpx.Response(
                200,
                json={"message": {"content": json.dumps(invalid)}},
            )
        ),
    )

    with pytest.raises(SimulationServiceError) as error:
        await service.propose(sample_request())

    assert error.value.code == "local_model_role_mismatch"
    assert error.value.status_code == 502


async def test_generation_rejects_output_without_mandatory_review_flag() -> None:
    invalid = model_response()
    del invalid["requires_human_review"]
    service = LocalSimulationService(
        local_settings(),
        transport=httpx.MockTransport(
            lambda _: httpx.Response(
                200,
                json={"message": {"content": json.dumps(invalid)}},
            )
        ),
    )

    with pytest.raises(SimulationServiceError) as error:
        await service.propose(sample_request())

    assert error.value.code == "local_model_invalid_response"
    assert error.value.status_code == 502


async def test_generation_does_not_expose_context_when_ollama_is_unavailable() -> None:
    service = LocalSimulationService(
        local_settings(),
        transport=httpx.MockTransport(
            lambda _: (_ for _ in ()).throw(httpx.ConnectError("private transcript"))
        ),
    )

    with pytest.raises(SimulationServiceError) as error:
        await service.propose(sample_request())

    assert error.value.code == "local_model_unavailable"
    assert "private transcript" not in error.value.message
