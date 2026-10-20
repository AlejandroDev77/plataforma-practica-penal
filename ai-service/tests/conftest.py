from __future__ import annotations

import os

import httpx
import pytest

from app.core.config import Settings


@pytest.fixture(autouse=True)
def isolate_settings_and_real_http(monkeypatch: pytest.MonkeyPatch) -> None:
    """Las pruebas no leen credenciales locales ni consumen saldo de proveedores."""
    monkeypatch.setitem(Settings.model_config, "env_file", None)
    for field in Settings.model_fields:
        monkeypatch.delenv(field.upper(), raising=False)

    original = httpx.AsyncHTTPTransport.handle_async_request

    async def guarded(
        transport: httpx.AsyncHTTPTransport, request: httpx.Request
    ) -> httpx.Response:
        if (
            os.environ.get("JURISSIM_EJECUTAR_OLLAMA_LOCAL") == "1"
            and request.url.scheme == "http"
            and request.url.host == "127.0.0.1"
            and request.url.port == 11434
        ):
            return await original(transport, request)
        raise AssertionError("HTTP real bloqueado en pruebas: use MockTransport o ASGITransport.")

    def guarded_sync(_: httpx.HTTPTransport, __: httpx.Request) -> httpx.Response:
        raise AssertionError("HTTP real bloqueado en pruebas: use MockTransport.")

    monkeypatch.setattr(httpx.AsyncHTTPTransport, "handle_async_request", guarded)
    monkeypatch.setattr(httpx.HTTPTransport, "handle_request", guarded_sync)
