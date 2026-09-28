from pathlib import PurePosixPath
from typing import Annotated

from fastapi import APIRouter, Depends, File, HTTPException, UploadFile, status
from starlette.concurrency import run_in_threadpool

from app.api.v1.dependencies import require_service_token
from app.core.config import Settings
from app.modules.ingestion.schemas import DocumentExtractionResult
from app.modules.ingestion.service import DocumentExtractionError, DocumentExtractor

router = APIRouter(prefix="/documents", tags=["documents"])


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
