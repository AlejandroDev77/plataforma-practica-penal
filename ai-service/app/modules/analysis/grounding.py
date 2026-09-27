from __future__ import annotations

import unicodedata

from app.modules.analysis.schemas import (
    AnalysisBatchRequest,
    SourceReference,
    StructuredCaseAnalysis,
)


def validate_source_grounding(
    result: StructuredCaseAnalysis,
    request: AnalysisBatchRequest,
) -> StructuredCaseAnalysis:
    pages = {page.page_id: page for page in request.pages}

    for reference in iter_references(result):
        page = pages.get(reference.page_id)
        if page is None:
            raise ValueError("El análisis cita una página ajena al lote autorizado.")
        if normalize_for_match(reference.excerpt) not in normalize_for_match(page.text):
            raise ValueError("La cita del análisis no coincide con el texto de su página fuente.")

    return result


def iter_references(result: StructuredCaseAnalysis) -> list[SourceReference]:
    references: list[SourceReference] = []

    for field in ("summary", "procedural_stage"):
        finding = getattr(result, field)
        if finding is not None:
            references.extend(finding.sources)

    for field in (
        "participants",
        "offenses",
        "facts",
        "evidence",
        "chronology",
        "uncertainties",
    ):
        for finding in getattr(result, field):
            references.extend(finding.sources)

    for gap in result.missing_information:
        references.extend(gap.context_sources)

    return references


def normalize_for_match(text: str) -> str:
    normalized = unicodedata.normalize("NFC", text).casefold()
    return " ".join(normalized.split())
