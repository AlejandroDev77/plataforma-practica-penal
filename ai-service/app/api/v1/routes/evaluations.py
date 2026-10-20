from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Response

from app.api.v1.dependencies import require_service_token
from app.core.config import Settings
from app.modules.evaluation.openai_service import OpenAIEvaluationService
from app.modules.evaluation.schemas import EvaluationEnvelope, EvaluationRequest
from app.modules.evaluation.service import EvaluationServiceError, LocalEvaluationService

router = APIRouter(prefix="/evaluations", tags=["evaluations"])


@router.get("/provider")
async def evaluation_provider(
    settings: Annotated[Settings, Depends(require_service_token)],
) -> dict[str, object]:
    provider = settings.evaluation_generation_provider
    return {
        "data": {
            "provider": provider,
            "model": settings.evaluation_generation_model if provider != "disabled" else None,
            "requires_external_authorization": provider == "openai",
        }
    }


@router.post("/evaluate", response_model=EvaluationEnvelope)
async def evaluate_simulation(
    payload: EvaluationRequest,
    settings: Annotated[Settings, Depends(require_service_token)],
    response: Response,
) -> EvaluationEnvelope:
    try:
        provider = settings.evaluation_generation_provider
        if provider == "openai":
            if payload.external_authorized is not True:
                raise EvaluationServiceError(
                    "external_consent_required",
                    "Se requiere consentimiento explícito para enviar el contexto a OpenAI.",
                    403,
                )
            result = await OpenAIEvaluationService(settings).evaluate(payload)
        elif provider == "ollama":
            result = await LocalEvaluationService(settings).evaluate(payload)
        else:
            # Conserva el diagnóstico histórico y seguro del modo desactivado.
            result = await LocalEvaluationService(settings).evaluate(payload)
        response.headers["X-Jurissim-Evaluation-Provider"] = provider
        response.headers["X-Jurissim-Evaluation-Model"] = settings.evaluation_generation_model or ""
        return EvaluationEnvelope(data=result)
    except EvaluationServiceError as error:
        raise HTTPException(
            status_code=error.status_code,
            detail={"code": error.code, "message": error.message},
        ) from error
