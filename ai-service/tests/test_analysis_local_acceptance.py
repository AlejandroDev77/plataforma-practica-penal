from __future__ import annotations

import os

import pytest

from app.core.config import Settings
from app.modules.analysis.schemas import AnalysisBatchRequest
from app.modules.analysis.service import LocalAnalysisService

pytestmark = pytest.mark.skipif(
    os.environ.get("JURISSIM_EJECUTAR_OLLAMA_LOCAL") != "1",
    reason="La prueba de aceptación requiere habilitación explícita de Ollama local.",
)


def held_out_synthetic_case() -> AnalysisBatchRequest:
    return AnalysisBatchRequest(
        pages=[
            {
                "page_id": 900_731,
                "file_id": 900_731,
                "page_number": 1,
                "locator": "Folio sintético 1",
                "text": (
                    "Documento sintético JURISSIM-HELDOUT-731, sin caso real. "
                    "Persona Alfa declaró: Entregué un sobre verde a Persona Beta el 3 de junio "
                    "de 2025. El documento no indica la hora ni el contenido del sobre, y no "
                    "identifica delito imputado."
                ),
            }
        ]
    )


@pytest.mark.asyncio
async def test_unseen_synthetic_case_is_extracted_without_inventing_offenses() -> None:
    """Prueba de aceptación opt-in; nunca usa expedientes reales ni servicios externos."""
    settings = Settings(
        llm_provider="ollama",
        llm_model=os.environ.get("JURISSIM_MODELO_OLLAMA_LOCAL", "qwen3.5:2b-q4_K_M"),
    )
    result = await LocalAnalysisService(settings).analyze(held_out_synthetic_case())

    assert result.summary is not None
    assert result.facts
    assert result.offenses == []
    assert any(
        event.date_as_written == "3 de junio de 2025"
        and "3 de junio de 2025" in event.description
        and any(source.excerpt == event.description for source in event.sources)
        for event in result.chronology
    )
    assert any(
        any(term in gap.question.casefold() for term in ("hora", "contenido"))
        for gap in result.missing_information
    )
