from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

Certainty = Literal["textual", "inferido", "incierto"]


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)


class SourcePage(StrictModel):
    page_id: int = Field(gt=0)
    file_id: int = Field(gt=0)
    page_number: int = Field(ge=1)
    locator: str = Field(min_length=1, max_length=200)
    text: str = Field(min_length=1, max_length=40_000)


class AnalysisBatchRequest(StrictModel):
    pages: list[SourcePage] = Field(min_length=1, max_length=10)

    @model_validator(mode="after")
    def validate_batch(self) -> AnalysisBatchRequest:
        page_ids = [page.page_id for page in self.pages]
        if len(page_ids) != len(set(page_ids)):
            raise ValueError("Una página fuente no puede repetirse en el mismo lote.")
        if sum(len(page.text) for page in self.pages) > 40_000:
            raise ValueError("El lote excede el límite de texto permitido.")
        return self


class SourceReference(StrictModel):
    page_id: int = Field(gt=0)
    excerpt: str = Field(min_length=1, max_length=1_000)


class Finding(StrictModel):
    text: str = Field(min_length=1, max_length=4_000)
    certainty: Certainty
    sources: list[SourceReference] = Field(min_length=1, max_length=10)


class ParticipantFinding(StrictModel):
    name_as_written: str = Field(min_length=1, max_length=255)
    role_as_written: str | None = Field(default=None, max_length=80)
    description: str | None = Field(default=None, max_length=2_000)
    certainty: Certainty
    sources: list[SourceReference] = Field(min_length=1, max_length=10)


class OffenseFinding(StrictModel):
    label_as_written: str = Field(min_length=1, max_length=255)
    article_as_written: str | None = Field(default=None, max_length=150)
    description: str | None = Field(default=None, max_length=2_000)
    certainty: Certainty
    sources: list[SourceReference] = Field(min_length=1, max_length=10)


class FactFinding(StrictModel):
    description: str = Field(min_length=1, max_length=4_000)
    kind: Literal["alegacion", "declaracion", "acto_procesal", "otro"]
    date_as_written: str | None = Field(default=None, max_length=200)
    certainty: Certainty
    sources: list[SourceReference] = Field(min_length=1, max_length=10)


class EvidenceFinding(StrictModel):
    name_as_written: str = Field(min_length=1, max_length=255)
    kind_as_written: str | None = Field(default=None, max_length=80)
    description: str | None = Field(default=None, max_length=2_000)
    status_as_written: str | None = Field(default=None, max_length=200)
    certainty: Certainty
    sources: list[SourceReference] = Field(min_length=1, max_length=10)


class ChronologyFinding(StrictModel):
    title: str = Field(min_length=1, max_length=255)
    description: str = Field(min_length=1, max_length=2_000)
    date_as_written: str | None = Field(default=None, max_length=200)
    certainty: Certainty
    sources: list[SourceReference] = Field(min_length=1, max_length=10)


class InformationGap(StrictModel):
    question: str = Field(min_length=1, max_length=1_000)
    relevance: str = Field(min_length=1, max_length=2_000)
    context_sources: list[SourceReference] = Field(default_factory=list, max_length=10)


class UncertaintyFinding(StrictModel):
    issue: str = Field(min_length=1, max_length=1_000)
    explanation: str = Field(min_length=1, max_length=2_000)
    sources: list[SourceReference] = Field(min_length=1, max_length=10)


class StructuredCaseAnalysis(StrictModel):
    schema_version: Literal["1.0"] = "1.0"
    summary: Finding | None = None
    procedural_stage: Finding | None = None
    participants: list[ParticipantFinding] = Field(default_factory=list, max_length=100)
    offenses: list[OffenseFinding] = Field(default_factory=list, max_length=100)
    facts: list[FactFinding] = Field(default_factory=list, max_length=150)
    evidence: list[EvidenceFinding] = Field(default_factory=list, max_length=100)
    chronology: list[ChronologyFinding] = Field(default_factory=list, max_length=150)
    missing_information: list[InformationGap] = Field(default_factory=list, max_length=100)
    uncertainties: list[UncertaintyFinding] = Field(default_factory=list, max_length=100)
