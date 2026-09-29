from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

SimulationRole = Literal["abogado_defensor", "juez", "fiscal"]
AgentRole = Literal["juez", "fiscal"]
FactKind = Literal["alegacion", "declaracion", "acto_procesal", "otro"]
Certainty = Literal["textual", "inferido", "incierto"]


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)


class TranscriptMessage(StrictModel):
    role: SimulationRole
    content: str = Field(min_length=1, max_length=2_000)


class SourceExcerpt(StrictModel):
    id: int = Field(gt=0)
    kind: Literal["expediente", "juridica"]
    title: str = Field(min_length=1, max_length=300)
    locator: str = Field(min_length=1, max_length=200)
    excerpt: str = Field(min_length=1, max_length=1_500)


class VisibleFact(StrictModel):
    text: str = Field(min_length=1, max_length=800)
    kind: FactKind
    certainty: Certainty
    attribution: str | None = Field(default=None, max_length=200)
    source_ids: list[int] = Field(default_factory=list, max_length=8)

    @model_validator(mode="after")
    def validate_source_ids(self) -> VisibleFact:
        if len(self.source_ids) != len(set(self.source_ids)):
            raise ValueError("Una fuente de la afirmación no puede repetirse.")
        return self


class SimulationProposalRequest(StrictModel):
    simulation_id: str = Field(min_length=1, max_length=80)
    actor_id: str = Field(min_length=1, max_length=80)
    actor_role: AgentRole
    phase: str = Field(min_length=1, max_length=100)
    turn_instruction: str = Field(min_length=1, max_length=1_500)
    visible_facts: list[VisibleFact] = Field(default_factory=list, max_length=20)
    sources: list[SourceExcerpt] = Field(default_factory=list, max_length=8)
    transcript: list[TranscriptMessage] = Field(default_factory=list, max_length=12)

    @model_validator(mode="after")
    def validate_context(self) -> SimulationProposalRequest:
        source_ids = [source.id for source in self.sources]
        if len(source_ids) != len(set(source_ids)):
            raise ValueError("Una referencia fuente no puede repetirse.")

        known_source_ids = set(source_ids)
        if any(not set(fact.source_ids).issubset(known_source_ids) for fact in self.visible_facts):
            raise ValueError("Una afirmación refiere una fuente fuera del contexto autorizado.")

        total = (
            len(self.turn_instruction)
            + sum(len(fact.text) + len(fact.attribution or "") for fact in self.visible_facts)
            + sum(
                len(source.excerpt) + len(source.title) + len(source.locator)
                for source in self.sources
            )
            + sum(len(message.content) for message in self.transcript)
        )
        if total > 20_000:
            raise ValueError("El contexto de la simulación excede el límite permitido.")

        return self


class SimulationProposal(StrictModel):
    speaker_role: AgentRole
    content: str = Field(min_length=1, max_length=3_000)
    used_source_ids: list[int] = Field(default_factory=list, max_length=8)
    requires_human_review: Literal[True]

    @model_validator(mode="after")
    def validate_source_ids(self) -> SimulationProposal:
        if len(self.used_source_ids) != len(set(self.used_source_ids)):
            raise ValueError("Una referencia fuente no puede repetirse en la propuesta.")
        return self


class SimulationProposalEnvelope(StrictModel):
    data: SimulationProposal
