from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Response

from app.api.v1.dependencies import require_service_token
from app.core.config import Settings
from app.modules.evaluation.schemas import EvaluationEnvelope, EvaluationRequest
from app.modules.evaluation.service import EvaluationServiceError, LocalEvaluationService

router = APIRouter(prefix="/evaluations", tags=["evaluations"])


@router.post("/evaluate", response_model=EvaluationEnvelope)
async def evaluate_simulation(
    payload: EvaluationRequest,
    settings: Annotated[Settings, Depends(require_service_token)],
    response: Response,
) -> EvaluationEnvelope:
    try:
        result = await LocalEvaluationService(settings).evaluate(payload)
        response.headers["X-Jurissim-Evaluation-Provider"] = settings.llm_provider
        response.headers["X-Jurissim-Evaluation-Model"] = settings.llm_model or ""
        return EvaluationEnvelope(data=result)
    except EvaluationServiceError as error:
        raise HTTPException(
            status_code=error.status_code,
            detail={"code": error.code, "message": error.message},
        ) from error
