from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, FiniteFloat, model_validator


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)


class EvaluationCriterion(StrictModel):
    id: int = Field(gt=0)
    name: str = Field(min_length=1, max_length=255)
    description: str = Field(min_length=1, max_length=2_000)
    weight: FiniteFloat = Field(gt=0)
    max_score: FiniteFloat = Field(gt=0)


class EvaluationSource(StrictModel):
    kind: Literal["expediente", "juridica"]
    title: str = Field(min_length=1, max_length=300)
    locator: str = Field(min_length=1, max_length=200)
    excerpt: str = Field(min_length=1, max_length=1_000)


class EvaluationTurn(StrictModel):
    order: int = Field(gt=0)
    role: Literal["abogado_defensor", "juez", "fiscal"]
    content: str = Field(min_length=1, max_length=2_000)
    sources: list[EvaluationSource] = Field(default_factory=list, max_length=10)


class EvaluationRequest(StrictModel):
    hearing_type: str = Field(min_length=1, max_length=100)
    user_role: Literal["abogado_defensor"]
    rubric_name: str = Field(min_length=1, max_length=255)
    rubric_version: int = Field(gt=0)
    criteria: list[EvaluationCriterion] = Field(min_length=1, max_length=20)
    transcript: list[EvaluationTurn] = Field(min_length=1, max_length=40)

    @model_validator(mode="after")
    def validate_context(self) -> EvaluationRequest:
        criterion_ids = [criterion.id for criterion in self.criteria]
        orders = [turn.order for turn in self.transcript]
        if len(criterion_ids) != len(set(criterion_ids)):
            raise ValueError("Un criterio de rúbrica no puede repetirse.")
        if len(orders) != len(set(orders)):
            raise ValueError("Un orden de intervención no puede repetirse.")
        if not any(turn.role == "abogado_defensor" for turn in self.transcript):
            raise ValueError("La transcripción debe contener intervenciones de la defensa.")

        total = sum(
            len(turn.content)
            + sum(
                len(source.title) + len(source.locator) + len(source.excerpt)
                for source in turn.sources
            )
            for turn in self.transcript
        ) + sum(len(criterion.name) + len(criterion.description) for criterion in self.criteria)
        if total > 20_000:
            raise ValueError("El contexto de evaluación excede el límite permitido.")
        return self


class EvaluationEvidence(StrictModel):
    intervention_order: int = Field(gt=0)
    quote: str = Field(min_length=1, max_length=500)


class EvaluationPoint(StrictModel):
    content: str = Field(min_length=1, max_length=600)
    evidence: EvaluationEvidence


class EvaluationCriterionResult(StrictModel):
    criterion_id: int = Field(gt=0)
    score: FiniteFloat = Field(ge=0)
    feedback: str = Field(min_length=1, max_length=1_000)
    strengths: list[EvaluationPoint] = Field(default_factory=list, max_length=4)
    errors: list[EvaluationPoint] = Field(default_factory=list, max_length=4)
    recommendations: list[str] = Field(default_factory=list, max_length=4)


class EvaluationResult(StrictModel):
    summary: str = Field(min_length=1, max_length=1_000)
    criteria: list[EvaluationCriterionResult] = Field(min_length=1, max_length=20)
    requires_human_review: Literal[True]


class EvaluationEnvelope(StrictModel):
    data: EvaluationResult
