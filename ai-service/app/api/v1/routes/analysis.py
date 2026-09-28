from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException

from app.api.v1.dependencies import require_service_token
from app.core.config import Settings
from app.modules.analysis.schemas import AnalysisBatchRequest, StructuredCaseAnalysis
from app.modules.analysis.service import AnalysisServiceError, LocalAnalysisService

router = APIRouter(prefix="/analysis", tags=["analysis"])


@router.post("/analyze", response_model=StructuredCaseAnalysis)
async def analyze_batch(
    request: AnalysisBatchRequest,
    settings: Annotated[Settings, Depends(require_service_token)],
) -> StructuredCaseAnalysis:
    try:
        return await LocalAnalysisService(settings).analyze(request)
    except AnalysisServiceError as error:
        raise HTTPException(
            status_code=error.status_code,
            detail={"code": error.code, "message": error.message},
        ) from error
