from __future__ import annotations

from functools import lru_cache
from typing import Literal
from urllib.parse import urlsplit

from pydantic import SecretStr, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    app_env: Literal["local", "testing", "staging", "production"] = "local"
    app_host: str = "127.0.0.1"
    app_port: int = 8100
    log_level: str = "INFO"
    service_token: SecretStr | None = None
    database_url: SecretStr | None = None
    ocr_language: str = "spa+eng"
    ocr_dpi: int = 200
    ocr_tesseract_path: str | None = None
    ocr_tessdata_path: str | None = None
    document_max_bytes: int = 52_428_800
    document_max_pages: int = 500
    page_ocr_threshold_characters: int = 24
    page_max_pixels: int = 40_000_000
    llm_provider: Literal["disabled", "ollama"] = "disabled"
    llm_model: str | None = None
    ollama_base_url: str = "http://127.0.0.1:11434"
    ollama_context_size: int = 16_384
    ollama_timeout_seconds: int = 300

    @field_validator("ollama_base_url")
    @classmethod
    def restrict_ollama_to_loopback(cls, value: str) -> str:
        parsed = urlsplit(value)
        if (
            parsed.scheme != "http"
            or parsed.hostname != "127.0.0.1"
            or parsed.port != 11434
            or parsed.username is not None
            or parsed.password is not None
            or parsed.path not in ("", "/")
            or parsed.query
            or parsed.fragment
        ):
            raise ValueError("Ollama debe quedar enlazado a http://127.0.0.1:11434.")

        return value.rstrip("/")

    @model_validator(mode="after")
    def validate_local_model_configuration(self) -> Settings:
        if self.llm_provider == "ollama" and not self.llm_model:
            raise ValueError("LLM_MODEL es obligatorio cuando LLM_PROVIDER=ollama.")
        if not 2_048 <= self.ollama_context_size <= 32_768:
            raise ValueError("OLLAMA_CONTEXT_SIZE debe estar entre 2048 y 32768 tokens.")
        if not 1 <= self.ollama_timeout_seconds <= 600:
            raise ValueError("OLLAMA_TIMEOUT_SECONDS debe estar entre 1 y 600 segundos.")
        return self


@lru_cache
def get_settings() -> Settings:
    return Settings()
