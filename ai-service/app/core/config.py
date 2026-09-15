from functools import lru_cache
from typing import Literal

from pydantic import SecretStr
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
    llm_provider: str | None = None
    llm_model: str | None = None
    llm_api_key: SecretStr | None = None


@lru_cache
def get_settings() -> Settings:
    return Settings()
