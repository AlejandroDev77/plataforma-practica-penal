from __future__ import annotations

import os

import pytest

from app.core.config import Settings
from app.modules.evaluation.schemas import EvaluationRequest
from app.modules.evaluation.service import LocalEvaluationService

pytestmark = pytest.mark.skipif(
    os.getenv("JURISSIM_EJECUTAR_OLLAMA_LOCAL") != "1",
    reason="La prueba requiere habilitación explícita de Ollama local.",
)


def held_out_synthetic_evaluation() -> EvaluationRequest:
    return EvaluationRequest(
        hearing_type="Audiencia sintética de práctica",
        user_role="abogado_defensor",
        rubric_name="Rúbrica sintética de argumentación",
        rubric_version=1,
        criteria=[
            {
                "id": 501,
                "name": "Claridad y conexión argumentativa",
                "description": (
                    "Expone una petición comprensible y explica cómo se relaciona con el "
                    "antecedente que menciona. No se evalúa la veracidad ni el resultado jurídico."
                ),
                "weight": 1,
                "max_score": 10,
            }
        ],
        transcript=[
            {
                "order": 1,
                "role": "abogado_defensor",
                "content": (
                    "Solicito que se valore el arraigo laboral. El certificado de trabajo "
                    "que mencioné acredita que la persona tiene una ocupación estable."
                ),
                "sources": [
                    {
                        "kind": "expediente",
                        "title": "Certificado laboral sintético",
                        "locator": "Página 1",
                        "excerpt": "La persona trabaja en Taller Ejemplo desde marzo de 2024.",
                    }
                ],
            },
            {
                "order": 2,
                "role": "juez",
                "content": "¿Qué relación plantea entre ese documento y su solicitud?",
            },
            {
                "order": 3,
                "role": "abogado_defensor",
                "content": "Lo vinculo con la posibilidad de mantener una dirección conocida.",
            },
        ],
    )


async def test_local_model_evaluates_only_synthetic_transcript_with_verifiable_quotes():
    settings = Settings(
        llm_provider="ollama",
        llm_model=os.getenv("LLM_MODEL", "qwen3.5:2b-q4_K_M"),
        ollama_base_url="http://127.0.0.1:11434",
        ollama_timeout_seconds=300,
    )
    result = await LocalEvaluationService(settings).evaluate(held_out_synthetic_evaluation())

    assert result.requires_human_review is True
    assert len(result.criteria) == 1
    assert result.criteria[0].criterion_id == 501
    assert 0 <= result.criteria[0].score <= 10
    for point in [*result.criteria[0].strengths, *result.criteria[0].errors]:
        assert point.evidence.quote
