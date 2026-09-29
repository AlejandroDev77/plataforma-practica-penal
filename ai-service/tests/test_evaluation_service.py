from __future__ import annotations

import json

import httpx
import pytest
from pydantic import ValidationError

from app.core.config import Settings
from app.modules.evaluation.schemas import EvaluationRequest
from app.modules.evaluation.service import EvaluationServiceError, LocalEvaluationService


def sample_request() -> EvaluationRequest:
    return EvaluationRequest(
        hearing_type="Medidas cautelares",
        user_role="abogado_defensor",
        rubric_name="Rúbrica sintética",
        rubric_version=1,
        criteria=[
            {
                "id": 31,
                "name": "Claridad argumentativa",
                "description": "Expone una petición comprensible y ordenada.",
                "weight": 1,
                "max_score": 10,
            }
        ],
        transcript=[
            {
                "order": 1,
                "role": "abogado_defensor",
                "content": "Solicito que se valore esta petición por arraigo demostrado.",
                "sources": [
                    {
                        "kind": "expediente",
                        "title": "Certificado laboral",
                        "locator": "Página 2",
                        "excerpt": "La persona trabaja en Taller Ejemplo desde marzo de 2024.",
                    }
                ],
            },
            {
                "order": 2,
                "role": "juez",
                "content": "¿Qué elemento menciona para respaldar su solicitud?",
            },
        ],
    )


def model_response() -> dict[str, object]:
    return {
        "summary": "La defensa formuló una petición breve que puede desarrollar "
        "con mayor precisión.",
        "criteria": [
            {
                "criterion_id": 31,
                "score": 7,
                "feedback": "La solicitud se entiende, aunque requiere conectar "
                "mejor su fundamento.",
                "strengths": [
                    {
                        "content": "La petición se formula de manera directa.",
                        "evidence": {
                            "intervention_order": 1,
                            "quote": "Solicito que se valore esta petición",
                        },
                    }
                ],
                "errors": [],
                "recommendations": ["Explica cómo el elemento citado sustenta la petición."],
            }
        ],
        "requires_human_review": True,
    }


def local_settings() -> Settings:
    return Settings(llm_provider="ollama", llm_model="qwen3.5:2b-q4_K_M")


def test_evaluation_request_rejects_duplicate_criteria_unknown_fields_and_missing_defense():
    data = sample_request().model_dump()
    data["prompt"] = "instrucción suministrada por el cliente"
    with pytest.raises(ValidationError):
        EvaluationRequest.model_validate(data)

    data = sample_request().model_dump()
    data["criteria"] = [data["criteria"][0], data["criteria"][0]]
    with pytest.raises(ValidationError, match="no puede repetirse"):
        EvaluationRequest.model_validate(data)

    data = sample_request().model_dump()
    data["transcript"] = [data["transcript"][1]]
    with pytest.raises(ValidationError, match="intervenciones de la defensa"):
        EvaluationRequest.model_validate(data)


def test_evaluation_request_caps_context_size():
    data = sample_request().model_dump()
    data["transcript"] = [
        {"order": order, "role": "abogado_defensor", "content": "x" * 2_000}
        for order in range(1, 11)
    ]
    with pytest.raises(ValidationError, match="excede el límite"):
        EvaluationRequest.model_validate(data)


async def test_local_evaluation_stays_disabled_by_default():
    service = LocalEvaluationService(Settings())
    with pytest.raises(EvaluationServiceError) as error:
        await service.evaluate(sample_request())

    assert error.value.status_code == 503
    assert error.value.code == "local_model_not_configured"


async def test_ollama_receives_bounded_rubric_and_transcript_and_strict_schema():
    captured: dict[str, object] = {}

    def handle(request: httpx.Request) -> httpx.Response:
        captured["url"] = str(request.url)
        captured["body"] = json.loads(request.content)
        return httpx.Response(
            200,
            json={"message": {"content": json.dumps(model_response(), ensure_ascii=False)}},
        )

    service = LocalEvaluationService(local_settings(), transport=httpx.MockTransport(handle))
    result = await service.evaluate(sample_request())

    assert result.requires_human_review is True
    assert result.criteria[0].score == 7
    assert captured["url"] == "http://127.0.0.1:11434/api/chat"
    body = captured["body"]
    assert isinstance(body, dict)
    assert body["model"] == "qwen3.5:2b-q4_K_M"
    assert body["stream"] is False
    assert body["format"]["type"] == "object"
    assert "solo las intervenciones de la defensa" in body["messages"][0]["content"]
    assert '"rubric_name": "Rúbrica sintética"' in body["messages"][1]["content"]
    assert '"title": "Certificado laboral"' in body["messages"][1]["content"]
    assert "Authorization" not in json.dumps(body)


@pytest.mark.parametrize(
    ("mutate", "expected_code"),
    [
        (
            lambda body: body["criteria"].append(body["criteria"][0]),
            "local_model_incomplete_evaluation",
        ),
        (lambda body: body["criteria"][0].update(score=11), "local_model_score_out_of_range"),
        (
            lambda body: body["criteria"][0]["strengths"][0]["evidence"].update(
                quote="texto que no aparece"
            ),
            "local_model_unverifiable_evidence",
        ),
    ],
)
async def test_local_evaluation_rejects_unverifiable_model_output(mutate, expected_code):
    invalid = model_response()
    mutate(invalid)
    service = LocalEvaluationService(
        local_settings(),
        transport=httpx.MockTransport(
            lambda _: httpx.Response(
                200,
                json={"message": {"content": json.dumps(invalid, ensure_ascii=False)}},
            )
        ),
    )

    with pytest.raises(EvaluationServiceError) as error:
        await service.evaluate(sample_request())

    assert error.value.code == expected_code
    assert error.value.status_code == 502


async def test_local_evaluation_hides_transcript_when_ollama_is_unavailable():
    service = LocalEvaluationService(
        local_settings(),
        transport=httpx.MockTransport(
            lambda _: (_ for _ in ()).throw(httpx.ConnectError("private transcript"))
        ),
    )

    with pytest.raises(EvaluationServiceError) as error:
        await service.evaluate(sample_request())

    assert error.value.code == "local_model_unavailable"
    assert "private transcript" not in error.value.message
