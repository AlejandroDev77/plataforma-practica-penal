from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Response

from app.api.v1.dependencies import require_service_token
from app.core.config import Settings
from app.modules.analysis.openai_service import OpenAIAnalysisService
from app.modules.analysis.schemas import AnalysisBatchRequest, StructuredCaseAnalysis
from app.modules.analysis.service import AnalysisServiceError, LocalAnalysisService

router = APIRouter(prefix="/analysis", tags=["analysis"])


@router.post("/analyze", response_model=StructuredCaseAnalysis)
async def analyze_batch(
    request: AnalysisBatchRequest,
    settings: Annotated[Settings, Depends(require_service_token)],
    response: Response,
) -> StructuredCaseAnalysis:
    provider = settings.analysis_generation_provider
    try:
        if provider == "openai":
            if not request.allow_external_processing:
                raise AnalysisServiceError(
                    "external_processing_not_authorized",
                    "Autoriza expresamente el envío de las páginas seleccionadas para continuar.",
                    403,
                )
            result = await OpenAIAnalysisService(settings).analyze(request)
            model = settings.openai_model
        elif provider == "ollama":
            result = await LocalAnalysisService(settings).analyze(request)
            model = settings.llm_model or ""
        else:
            raise AnalysisServiceError(
                "analysis_provider_not_configured",
                "No hay un proveedor de análisis configurado.",
                503,
            )

        response.headers["X-Jurissim-Analysis-Provider"] = provider
        response.headers["X-Jurissim-Analysis-Model"] = model
        return result
    except AnalysisServiceError as error:
        raise HTTPException(
            status_code=error.status_code,
            detail={"code": error.code, "message": error.message},
        ) from error
