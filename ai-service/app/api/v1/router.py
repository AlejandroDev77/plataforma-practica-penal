from fastapi import APIRouter

from app.api.v1.routes import analysis, documents, evaluations, health, simulations

api_router = APIRouter(prefix="/api/v1")
api_router.include_router(health.router)
api_router.include_router(documents.router)
api_router.include_router(analysis.router)
api_router.include_router(simulations.router)
api_router.include_router(evaluations.router)
