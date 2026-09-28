from __future__ import annotations

import hmac
from typing import Annotated

from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app.core.config import Settings, get_settings

bearer_scheme = HTTPBearer(auto_error=False)
BearerCredentials = Annotated[HTTPAuthorizationCredentials | None, Depends(bearer_scheme)]


def require_service_token(credentials: BearerCredentials) -> Settings:
    settings = get_settings()
    expected_token = settings.service_token

    if expected_token is None or not expected_token.get_secret_value():
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={
                "code": "document_service_not_configured",
                "message": "El servicio interno no tiene una credencial configurada.",
            },
        )

    expected = expected_token.get_secret_value()
    provided = credentials.credentials if credentials else ""
    if not hmac.compare_digest(provided, expected):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail={"code": "unauthorized", "message": "Credencial interna no válida."},
            headers={"WWW-Authenticate": "Bearer"},
        )

    return settings
