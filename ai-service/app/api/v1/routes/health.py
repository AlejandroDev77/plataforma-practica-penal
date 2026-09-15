from fastapi import APIRouter

router = APIRouter(tags=["system"])


@router.get("/health")
async def health() -> dict[str, object]:
    return {
        "data": {
            "service": "praxis-penal-intelligence",
            "status": "available",
            "version": "v1",
        }
    }
