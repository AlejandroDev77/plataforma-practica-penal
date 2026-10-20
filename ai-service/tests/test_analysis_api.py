from __future__ import annotations

from httpx import ASGITransport, AsyncClient
from pydantic import SecretStr

from app.api.v1 import dependencies
from app.api.v1.routes import analysis as analysis_route
from app.core.config import Settings
from app.main import app
from app.modules.analysis.schemas import AnalysisBatchRequest, StructuredCaseAnalysis


def sample_request() -> dict[str, object]:
    return {
        "pages": [
            {
                "page_id": 45,
                "file_id": 12,
                "page_number": 3,
                "locator": "Página 3",
                "text": "El informe señala una aprehensión el 12 de mayo.",
            }
        ]
    }


async def test_internal_analysis_endpoint_requires_service_token(
    monkeypatch,
) -> None:
    monkeypatch.setattr(
        dependencies,
        "get_settings",
        lambda: Settings(service_token=SecretStr("local-internal-secret")),
    )
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post("/api/v1/analysis/analyze", json=sample_request())

    assert response.status_code == 401
    assert response.json()["detail"]["code"] == "unauthorized"


async def test_internal_analysis_endpoint_stays_disabled_without_provider(
    monkeypatch,
) -> None:
    monkeypatch.setattr(
        dependencies,
        "get_settings",
        lambda: Settings(
            service_token=SecretStr("local-internal-secret"),
            analysis_provider="disabled",
        ),
    )
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post(
            "/api/v1/analysis/analyze",
            headers={"Authorization": "Bearer local-internal-secret"},
            json=sample_request(),
        )

    assert response.status_code == 503
    assert response.json()["detail"]["code"] == "analysis_provider_not_configured"


async def test_openai_analysis_requires_consent_before_calling_provider(monkeypatch) -> None:
    monkeypatch.setattr(
        dependencies,
        "get_settings",
        lambda: Settings(
            service_token=SecretStr("local-internal-secret"),
            analysis_provider="openai",
            openai_api_key=SecretStr("synthetic-test-key"),
        ),
    )

    class ForbiddenCall:
        def __init__(self, settings: Settings) -> None:
            pass

        async def analyze(self, request: AnalysisBatchRequest) -> StructuredCaseAnalysis:
            raise AssertionError("El proveedor no debe ejecutarse sin consentimiento.")

    monkeypatch.setattr(analysis_route, "OpenAIAnalysisService", ForbiddenCall)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post(
            "/api/v1/analysis/analyze",
            headers={"Authorization": "Bearer local-internal-secret"},
            json=sample_request(),
        )

    assert response.status_code == 403
    assert response.json()["detail"]["code"] == "external_processing_not_authorized"


async def test_openai_analysis_returns_provider_metadata_after_explicit_consent(
    monkeypatch,
) -> None:
    monkeypatch.setattr(
        dependencies,
        "get_settings",
        lambda: Settings(
            service_token=SecretStr("local-internal-secret"),
            analysis_provider="openai",
            openai_api_key=SecretStr("synthetic-test-key"),
        ),
    )

    class StubAnalysisService:
        def __init__(self, settings: Settings) -> None:
            self.settings = settings

        async def analyze(self, request: AnalysisBatchRequest) -> StructuredCaseAnalysis:
            assert request.allow_external_processing is True
            return StructuredCaseAnalysis()

    monkeypatch.setattr(analysis_route, "OpenAIAnalysisService", StubAnalysisService)
    payload = sample_request()
    payload["allow_external_processing"] = True
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post(
            "/api/v1/analysis/analyze",
            headers={"Authorization": "Bearer local-internal-secret"},
            json=payload,
        )

    assert response.status_code == 200
    assert response.headers["X-Jurissim-Analysis-Provider"] == "openai"
    assert response.headers["X-Jurissim-Analysis-Model"] == "gpt-4o-mini"


async def test_internal_analysis_endpoint_returns_local_model_metadata(monkeypatch) -> None:
    monkeypatch.setattr(
        dependencies,
        "get_settings",
        lambda: Settings(
            service_token=SecretStr("local-internal-secret"),
            llm_provider="ollama",
            llm_model="qwen3.5:2b-q4_K_M",
        ),
    )

    class StubAnalysisService:
        def __init__(self, settings: Settings) -> None:
            self.settings = settings

        async def analyze(self, request: AnalysisBatchRequest) -> StructuredCaseAnalysis:
            return StructuredCaseAnalysis()

    monkeypatch.setattr(analysis_route, "LocalAnalysisService", StubAnalysisService)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post(
            "/api/v1/analysis/analyze",
            headers={"Authorization": "Bearer local-internal-secret"},
            json=sample_request(),
        )

    assert response.status_code == 200
    assert response.headers["X-Jurissim-Analysis-Provider"] == "ollama"
    assert response.headers["X-Jurissim-Analysis-Model"] == "qwen3.5:2b-q4_K_M"
