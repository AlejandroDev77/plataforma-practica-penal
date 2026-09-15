from typing import Any

from fastapi import APIRouter, HTTPException, status
from pydantic import BaseModel, Field

router = APIRouter(prefix="/simulations", tags=["simulations"])


class SimulationProposalRequest(BaseModel):
    simulation_id: str
    actor_id: str
    phase: str
    visible_facts: list[str] = Field(default_factory=list)
    transcript: list[dict[str, Any]] = Field(default_factory=list)


@router.post("/proposals")
async def propose_action(payload: SimulationProposalRequest) -> dict[str, object]:
    del payload
    raise HTTPException(
        status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
        detail={
            "code": "simulation_engine_not_configured",
            "message": "El motor de simulación aún no tiene un proveedor configurado.",
        },
    )
