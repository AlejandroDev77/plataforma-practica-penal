from __future__ import annotations

import json

import httpx
import pytest

from app.core.config import Settings
from app.modules.analysis.openai_service import OpenAIAnalysisService
from app.modules.analysis.schemas import AnalysisBatchRequest
from app.modules.analysis.service import AnalysisServiceError


def request() -> AnalysisBatchRequest:
    return AnalysisBatchRequest(
        pages=[
            {
                "page_id": 451,
                "file_id": 120,
                "page_number": 3,
                "locator": "Folio 3",
                "text": "Ana compareció ante la autoridad.",
            },
        ],
        allow_external_processing=True,
    )


def result() -> dict[str, object]:
    return {
        "schema_version": "1.0",
        "summary": {
            "text": "El documento refiere una comparecencia de Ana.",
            "certainty": "textual",
            "sources": [{"page_id": 1, "excerpt": "Ana compareció ante la autoridad."}],
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


async def test_openai_receives_only_selected_text_and_uses_strict_nonstored_output() -> None:
    captured: dict[str, object] = {}

    def handle(http_request: httpx.Request) -> httpx.Response:
        captured["url"] = str(http_request.url)
        captured["body"] = json.loads(http_request.content)
        captured["authorization"] = http_request.headers.get("Authorization")
        return httpx.Response(
            200,
            json={
                "output": [
                    {
                        "type": "message",
                        "content": [
                            {
                                "type": "output_text",
                                "text": json.dumps(result(), ensure_ascii=False),
                            }
                        ],
                    }
                ]
            },
        )

    service = OpenAIAnalysisService(
        Settings(analysis_provider="openai", openai_api_key="synthetic-test-key"),
        transport=httpx.MockTransport(handle),
    )
    analyzed = await service.analyze(request())

    assert analyzed.summary is not None
    assert analyzed.summary.sources[0].page_id == 451
    assert captured["url"] == "https://api.openai.com/v1/responses"
    assert captured["authorization"] == "Bearer synthetic-test-key"
    body = captured["body"]
    assert isinstance(body, dict)
    assert body["store"] is False
    assert body["text"]["format"]["strict"] is True
    assert body["text"]["format"]["schema"]["properties"]["summary"] == {
        "$ref": "#/$defs/Finding"
    }
    prompt = json.dumps(body["input"], ensure_ascii=False)
    assert "Ana compareció ante la autoridad." in prompt
    assert "451" not in prompt
    assert "Folio 3" not in prompt
    assert "file_id" not in prompt


async def test_openai_missing_key_fails_without_network() -> None:
    service = OpenAIAnalysisService(Settings(analysis_provider="openai"))

    with pytest.raises(AnalysisServiceError) as error:
        await service.analyze(request())

    assert error.value.status_code == 503
    assert error.value.code == "openai_api_key_not_configured"
