from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Response

from app.api.v1.dependencies import require_service_token
from app.core.config import Settings
from app.modules.analysis.schemas import AnalysisBatchRequest, StructuredCaseAnalysis
from app.modules.analysis.service import AnalysisServiceError, LocalAnalysisService

router = APIRouter(prefix="/analysis", tags=["analysis"])


@router.post("/analyze", response_model=StructuredCaseAnalysis)
async def analyze_batch(
    request: AnalysisBatchRequest,
    settings: Annotated[Settings, Depends(require_service_token)],
    response: Response,
) -> StructuredCaseAnalysis:
    try:
        result = await LocalAnalysisService(settings).analyze(request)
        response.headers["X-Jurissim-Analysis-Provider"] = settings.llm_provider
        response.headers["X-Jurissim-Analysis-Model"] = settings.llm_model or ""
        return result
    except AnalysisServiceError as error:
        raise HTTPException(
            status_code=error.status_code,
            detail={"code": error.code, "message": error.message},
        ) from error
