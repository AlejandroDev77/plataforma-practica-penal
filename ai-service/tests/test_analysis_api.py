from __future__ import annotations

from httpx import ASGITransport, AsyncClient
from pydantic import SecretStr

from app.api.v1 import dependencies
from app.core.config import Settings
from app.main import app


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
