from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class ExtractedPage(BaseModel):
    model_config = ConfigDict(extra="forbid")

    page_number: int = Field(ge=1)
    locator: str = Field(min_length=1, max_length=200)
    text: str
    used_ocr: bool
    confidence: float | None = Field(default=None, ge=0, le=1)
    is_readable: bool


class ExtractionWarning(BaseModel):
    model_config = ConfigDict(extra="forbid")

    code: Literal[
        "ocr_unavailable",
        "ocr_failed",
        "text_not_recognized",
        "pagination_unavailable",
    ]
    page_number: int | None = Field(default=None, ge=1)
    message: str = Field(min_length=1, max_length=300)


class DocumentExtractionResult(BaseModel):
    model_config = ConfigDict(extra="forbid")

    document_type: Literal["pdf", "docx", "image"]
    page_count: int | None = Field(default=None, ge=1)
    pages: list[ExtractedPage] = Field(min_length=1)
    warnings: list[ExtractionWarning] = Field(default_factory=list)
