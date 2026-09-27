import hmac
from pathlib import PurePosixPath
from typing import Annotated

from fastapi import APIRouter, Depends, File, HTTPException, UploadFile, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from starlette.concurrency import run_in_threadpool

from app.core.config import Settings, get_settings
from app.modules.ingestion.schemas import DocumentExtractionResult
from app.modules.ingestion.service import DocumentExtractionError, DocumentExtractor

router = APIRouter(prefix="/documents", tags=["documents"])
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
                "message": "El servicio documental no tiene una credencial interna configurada.",
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


@router.post("/extract", response_model=DocumentExtractionResult)
async def extract_document(
    file: Annotated[UploadFile, File()],
    settings: Annotated[Settings, Depends(require_service_token)],
) -> DocumentExtractionResult:
    safe_name = PurePosixPath((file.filename or "").replace("\\", "/")).name
    if not safe_name:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={"code": "filename_required", "message": "Falta el nombre del archivo."},
        )

    content = await file.read(settings.document_max_bytes + 1)
    await file.close()

    try:
        return await run_in_threadpool(DocumentExtractor(settings).extract, safe_name, content)
    except DocumentExtractionError as error:
        raise HTTPException(
            status_code=error.status_code,
            detail={"code": error.code, "message": error.message},
        ) from error
