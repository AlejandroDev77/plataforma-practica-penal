from __future__ import annotations

import json

import httpx
import pytest
from pydantic import SecretStr

from app.core.config import Settings
from app.modules.evaluation.openai_service import OpenAIEvaluationService, result_schema
from app.modules.evaluation.schemas import EvaluationRequest
from app.modules.evaluation.service import EvaluationServiceError


def request_data(**extra: object) -> EvaluationRequest:
    return EvaluationRequest.model_validate(
        {
            "external_authorized": True,
            "hearing_type": "Práctica sintética",
            "user_role": "abogado_defensor",
            "rubric_name": "Criterio sintético",
            "rubric_version": 1,
            "criteria": [
                {
                    "id": 31,
                    "name": "Claridad",
                    "description": "Explica la petición.",
                    "weight": 1,
                    "max_score": 10,
                }
            ],
            "transcript": [
                {
                    "order": 1,
                    "role": "abogado_defensor",
                    "content": "Solicito revisar este punto concreto.",
                }
            ],
            **extra,
        }
    )


def valid_output() -> dict[str, object]:
    return {
        "summary": "La intervención es comprensible.",
        "criteria": [
            {
                "criterion_id": 31,
                "score": 7,
                "feedback": "La petición es clara.",
                "strengths": [
                    {
                        "content": "Formula una petición directa.",
                        "evidence": {
                            "intervention_order": 1,
                            "quote": "Solicito revisar este punto",
                        },
                    }
                ],
                "errors": [],
                "recommendations": ["Desarrolla el fundamento."],
            }
        ],
        "requires_human_review": True,
    }


def settings(**kwargs: object) -> Settings:
    return Settings(
        evaluation_provider="openai", openai_api_key=SecretStr("sk-test-not-real"), **kwargs
    )


async def test_openai_refuses_to_send_without_explicit_consent():
    sent = False

    def handle(_: httpx.Request) -> httpx.Response:
        nonlocal sent
        sent = True
        return httpx.Response(500)

    service = OpenAIEvaluationService(settings(), httpx.MockTransport(handle))
    with pytest.raises(EvaluationServiceError) as error:
        await service.evaluate(request_data(external_authorized=False))
    assert error.value.code == "external_consent_required"
    assert sent is False


async def test_openai_receives_only_consented_context_and_store_is_disabled():
    captured: dict[str, object] = {}

    def handle(request: httpx.Request) -> httpx.Response:
        captured["url"] = str(request.url)
        captured["authorization"] = request.headers.get("Authorization")
        captured["body"] = json.loads(request.content)
        return httpx.Response(
            200,
            json={
                "status": "completed",
                "output": [
                    {
                        "type": "message",
                        "role": "assistant",
                        "status": "completed",
                        "content": [
                            {
                                "type": "output_text",
                                "text": json.dumps(valid_output(), ensure_ascii=False),
                            }
                        ],
                    }
                ],
            },
        )

    result = await OpenAIEvaluationService(settings(), httpx.MockTransport(handle)).evaluate(
        request_data()
    )
    body = captured["body"]
    assert result.requires_human_review is True
    assert captured["url"] == "https://api.openai.com/v1/responses"
    assert captured["authorization"] == "Bearer sk-test-not-real"
    assert isinstance(body, dict) and body["store"] is False
    prompt = json.dumps(body["input"], ensure_ascii=False)
    assert "Solicito revisar este punto concreto." in prompt
    assert "external_authorized" not in prompt
    assert body["text"]["format"]["strict"] is True


@pytest.mark.parametrize(
    "mutation",
    [
        lambda value: value["criteria"][0].update(score=11),
        lambda value: value["criteria"][0]["strengths"][0]["evidence"].update(
            quote="cita inventada"
        ),
    ],
)
async def test_openai_rejects_scores_or_evidence_outside_the_snapshot(mutation):
    output = valid_output()
    mutation(output)
    service = OpenAIEvaluationService(
        settings(),
        httpx.MockTransport(
            lambda _: httpx.Response(
                200,
                json={
                    "status": "completed",
                    "output": [
                        {
                            "type": "message",
                            "role": "assistant",
                            "status": "completed",
                            "content": [{"type": "output_text", "text": json.dumps(output)}],
                        }
                    ],
                },
            )
        ),
    )
    with pytest.raises(EvaluationServiceError) as error:
        await service.evaluate(request_data())
    assert error.value.code == "openai_unverifiable_result"


def test_response_schema_limits_evidence_to_defense_turns_and_score_to_rubric():
    schema = result_schema(request_data())
    encoded = json.dumps(schema)
    assert '"maximum": 10.0' in encoded
    assert '"enum": [1]' in encoded
