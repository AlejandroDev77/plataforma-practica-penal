from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Response

from app.api.v1.dependencies import require_service_token
from app.core.config import Settings
from app.modules.simulation.schemas import (
    SimulationProposalEnvelope,
    SimulationProposalRequest,
)
from app.modules.simulation.service import SimulationService, SimulationServiceError

router = APIRouter(prefix="/simulations", tags=["simulations"])


@router.get("/provider")
async def generation_provider(
    settings: Annotated[Settings, Depends(require_service_token)],
) -> dict[str, object]:
    provider = settings.simulation_generation_provider
    return {
        "data": {
            "provider": provider,
            "model": settings.simulation_generation_model if provider != "disabled" else None,
            "requires_external_authorization": provider == "openai",
        }
    }


@router.post("/proposals", response_model=SimulationProposalEnvelope)
async def propose_action(
    payload: SimulationProposalRequest,
    settings: Annotated[Settings, Depends(require_service_token)],
    response: Response,
) -> SimulationProposalEnvelope:
    try:
        proposal = await SimulationService(settings).propose(payload)
        response.headers["X-Jurissim-Simulation-Provider"] = settings.simulation_generation_provider
        response.headers["X-Jurissim-Simulation-Model"] = settings.simulation_generation_model or ""
        return SimulationProposalEnvelope(data=proposal)
    except SimulationServiceError as error:
        raise HTTPException(
            status_code=error.status_code,
            detail={"code": error.code, "message": error.message},
        ) from error
