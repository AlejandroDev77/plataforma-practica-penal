from __future__ import annotations

import json

import httpx
import pytest
from pydantic import ValidationError

from app.core.config import Settings
from app.modules.analysis.schemas import AnalysisBatchRequest
from app.modules.analysis.service import AnalysisServiceError, LocalAnalysisService


def sample_request() -> AnalysisBatchRequest:
    return AnalysisBatchRequest(
        pages=[
            {
                "page_id": 45,
                "file_id": 12,
                "page_number": 3,
                "locator": "Página 3",
                "text": "El informe policial señala que la aprehensión ocurrió el 12 de mayo.",
            }
        ]
    )


def sample_result() -> dict[str, object]:
    return {
        "schema_version": "1.0",
        "summary": {
            "text": "El informe refiere una aprehensión el 12 de mayo.",
            "certainty": "textual",
            "sources": [{"page_id": 45, "excerpt": "la aprehensión ocurrió el 12 de mayo"}],
        },
        "procedural_stage": None,
        "participants": [],
        "offenses": [],
        "facts": [],
        "evidence": [],
        "chronology": [],
        "missing_information": [],
        "uncertainties": [],
    }


def test_local_provider_is_disabled_by_default() -> None:
    settings = Settings()

    assert settings.llm_provider == "disabled"
    assert settings.llm_model is None


def test_local_provider_requires_model_and_loopback_url() -> None:
    with pytest.raises(ValidationError, match="LLM_MODEL es obligatorio"):
        Settings(llm_provider="ollama")

    with pytest.raises(ValidationError, match="127.0.0.1"):
        Settings(
            llm_provider="ollama",
            llm_model="qwen3.5:2b-q4_K_M",
            ollama_base_url="https://ollama.com",
        )

    with pytest.raises(ValidationError):
        Settings(llm_provider="openai", llm_model="some-model")


async def test_disabled_provider_never_makes_a_request() -> None:
    service = LocalAnalysisService(Settings())

    with pytest.raises(AnalysisServiceError) as error:
        await service.analyze(sample_request())

    assert error.value.status_code == 503
    assert error.value.code == "local_model_not_configured"


async def test_ollama_receives_only_bounded_source_pages_and_schema() -> None:
    captured: dict[str, object] = {}

    def handle(request: httpx.Request) -> httpx.Response:
        captured["url"] = str(request.url)
        captured["body"] = json.loads(request.content)
        return httpx.Response(
            200,
            json={"message": {"content": json.dumps(sample_result(), ensure_ascii=False)}},
        )

    service = LocalAnalysisService(
        Settings(llm_provider="ollama", llm_model="qwen3.5:2b-q4_K_M"),
        transport=httpx.MockTransport(handle),
    )
    result = await service.analyze(sample_request())

    assert result.summary is not None
    assert captured["url"] == "http://127.0.0.1:11434/api/chat"
    body = captured["body"]
    assert isinstance(body, dict)
    assert body["model"] == "qwen3.5:2b-q4_K_M"
    assert body["stream"] is False
    assert body["think"] is False
    assert body["format"]["type"] == "object"
    assert body["options"]["num_ctx"] == 16_384
    assert "no que el hecho sea verdadero" in body["messages"][0]["content"]
    assert body["messages"][1]["content"].find("aprehensión ocurrió") >= 0
    assert "instructions" not in body["messages"][1]["content"]
    assert "Authorization" not in body


async def test_local_provider_rejects_citations_that_do_not_match_the_source() -> None:
    invalid_result = sample_result()
    invalid_result["summary"] = {
        "text": "El juez absolvió a la persona.",
        "certainty": "textual",
        "sources": [{"page_id": 45, "excerpt": "el juez dictó sentencia absolutoria"}],
    }

    service = LocalAnalysisService(
        Settings(llm_provider="ollama", llm_model="qwen3.5:2b-q4_K_M"),
        transport=httpx.MockTransport(
            lambda _: httpx.Response(
                200,
                json={"message": {"content": json.dumps(invalid_result, ensure_ascii=False)}},
            )
        ),
    )

    with pytest.raises(AnalysisServiceError) as error:
        await service.analyze(sample_request())

    assert error.value.code == "local_model_unverifiable_citations"
    assert error.value.status_code == 502


async def test_unavailable_ollama_is_reported_without_exposing_source_text() -> None:
    service = LocalAnalysisService(
        Settings(llm_provider="ollama", llm_model="qwen3.5:2b-q4_K_M"),
        transport=httpx.MockTransport(
            lambda _: (_ for _ in ()).throw(httpx.ConnectError("offline"))
        ),
    )

    with pytest.raises(AnalysisServiceError) as error:
        await service.analyze(sample_request())

    assert error.value.code == "local_model_unavailable"
    assert "aprehensión" not in error.value.message
