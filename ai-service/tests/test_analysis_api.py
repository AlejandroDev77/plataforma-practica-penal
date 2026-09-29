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
        lambda: Settings(service_token=SecretStr("local-internal-secret")),
    )
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post(
            "/api/v1/analysis/analyze",
            headers={"Authorization": "Bearer local-internal-secret"},
            json=sample_request(),
        )

    assert response.status_code == 503
    assert response.json()["detail"]["code"] == "local_model_not_configured"


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
