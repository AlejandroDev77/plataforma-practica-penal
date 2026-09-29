from __future__ import annotations

import json

import httpx
import pytest
from pydantic import ValidationError

from app.core.config import Settings
from app.modules.analysis.schemas import AnalysisBatchRequest, StructuredCaseAnalysis
from app.modules.analysis.service import (
    AnalysisServiceError,
    LocalAnalysisService,
    add_explicit_dates_to_chronology,
)


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


def test_explicit_dates_are_added_to_chronology_with_literal_source() -> None:
    request = AnalysisBatchRequest(
        pages=[
            {
                "page_id": 71,
                "file_id": 19,
                "page_number": 1,
                "locator": "Folio 1",
                "text": "Persona Alfa declaró: Entregué el sobre el 3 de junio de 2025.",
            }
        ]
    )

    result = add_explicit_dates_to_chronology(StructuredCaseAnalysis(), request)

    assert len(result.chronology) == 1
    assert result.chronology[0].date_as_written == "3 de junio de 2025"
    assert result.chronology[0].description == request.pages[0].text
    assert result.chronology[0].sources[0].excerpt == request.pages[0].text


def test_explicit_date_supplement_respects_chronology_limit() -> None:
    dated_sentences = "\n".join(
        f"Consta el 1 de enero de {year}." for year in range(1900, 2051)
    )
    request = AnalysisBatchRequest(
        pages=[
            {
                "page_id": 72,
                "file_id": 19,
                "page_number": 1,
                "locator": "Folio 1",
                "text": dated_sentences,
            }
        ]
    )

    result = add_explicit_dates_to_chronology(StructuredCaseAnalysis(), request)

    assert len(result.chronology) == 150


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

    with pytest.raises(ValidationError, match="caracteres no permitidos"):
        Settings(llm_provider="ollama", llm_model="modelo\r\nX-Injected: true")

    assert Settings(llm_model="").llm_model is None


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
    assert "summary" in body["format"]["required"]
    assert body["format"]["properties"]["summary"] == {"$ref": "#/$defs/Finding"}
    assert body["options"]["num_ctx"] == 16_384
    assert "no que el hecho sea verdadero" in body["messages"][0]["content"]
    assert "no devuelvas summary nulo" in body["messages"][0]["content"]
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


async def test_local_provider_rejects_analysis_without_summary() -> None:
    incomplete_result = sample_result()
    incomplete_result["summary"] = None
    service = LocalAnalysisService(
        Settings(llm_provider="ollama", llm_model="qwen3.5:2b-q4_K_M"),
        transport=httpx.MockTransport(
            lambda _: httpx.Response(
                200,
                json={"message": {"content": json.dumps(incomplete_result, ensure_ascii=False)}},
            )
        ),
    )

    with pytest.raises(AnalysisServiceError) as error:
        await service.analyze(sample_request())

    assert error.value.code == "local_model_incomplete_analysis"
    assert error.value.status_code == 502
    assert "aprehensión" not in error.value.message


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
