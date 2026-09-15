from fastapi import FastAPI

from app.api.v1.router import api_router

app = FastAPI(
    title="Praxis Penal Intelligence Service",
    version="0.1.0",
    docs_url="/docs",
    redoc_url=None,
)
app.include_router(api_router)
