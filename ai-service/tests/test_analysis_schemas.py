import pytest
from pydantic import ValidationError

from app.modules.analysis.grounding import validate_source_grounding
from app.modules.analysis.schemas import AnalysisBatchRequest, StructuredCaseAnalysis


def sample_request() -> AnalysisBatchRequest:
    return AnalysisBatchRequest(
        pages=[
            {
                "page_id": 45,
                "file_id": 12,
                "page_number": 3,
                "locator": "Página 3",
                "text": "El informe policial señala que la aprehensión ocurrió el 12 de mayo.",
            },
        ]
    )


def sample_result() -> StructuredCaseAnalysis:
    return StructuredCaseAnalysis(
        summary={
            "text": "El informe refiere una aprehensión el 12 de mayo.",
            "certainty": "textual",
            "sources": [{"page_id": 45, "excerpt": "la aprehensión ocurrió el 12 de mayo"}],
        },
        facts=[
            {
                "description": "El documento refiere la aprehensión el 12 de mayo.",
                "kind": "acto_procesal",
                "date_as_written": "12 de mayo",
                "certainty": "textual",
                "sources": [{"page_id": 45, "excerpt": "la aprehensión ocurrió el 12 de mayo"}],
            }
        ],
        missing_information=[
            {
                "question": "¿Se identifica el año de la aprehensión?",
                "relevance": "El documento citado solo consigna día y mes.",
            }
        ],
    )


def test_analysis_contract_requires_grounded_quotes_and_allows_explicit_gaps() -> None:
    result = validate_source_grounding(sample_result(), sample_request())

    assert result.schema_version == "1.0"
    assert result.facts[0].certainty == "textual"
    assert result.missing_information[0].context_sources == []


def test_analysis_rejects_a_page_outside_the_authorized_batch() -> None:
    result = StructuredCaseAnalysis.model_validate(
        {
            **sample_result().model_dump(),
            "summary": {
                "text": "Dato atribuido.",
                "certainty": "textual",
                "sources": [{"page_id": 999, "excerpt": "aprehensión"}],
            },
        }
    )

    with pytest.raises(ValueError, match="página ajena"):
        validate_source_grounding(result, sample_request())


def test_analysis_rejects_quotes_not_present_in_the_cited_page() -> None:
    result = StructuredCaseAnalysis.model_validate(
        {
            **sample_result().model_dump(),
            "summary": {
                "text": "Dato sin respaldo.",
                "certainty": "textual",
                "sources": [{"page_id": 45, "excerpt": "el juez dictó sentencia absolutoria"}],
            },
        }
    )

    with pytest.raises(ValueError, match="no coincide"):
        validate_source_grounding(result, sample_request())


def test_batch_rejects_duplicate_pages_and_excessive_text() -> None:
    request = sample_request().model_dump()
    with pytest.raises(ValidationError, match="no puede repetirse"):
        AnalysisBatchRequest(pages=[request["pages"][0], request["pages"][0]])

    with pytest.raises(ValidationError, match="límite de texto"):
        AnalysisBatchRequest(
            pages=[
                {
                    **request["pages"][0],
                    "text": "x" * 25_000,
                },
                {
                    **request["pages"][0],
                    "page_id": 46,
                    "text": "x" * 16_000,
                },
            ]
        )


def test_analysis_schema_rejects_unrecognized_fields_and_uncited_findings() -> None:
    with pytest.raises(ValidationError):
        StructuredCaseAnalysis.model_validate({"unexpected": "free text"})

    with pytest.raises(ValidationError):
        StructuredCaseAnalysis.model_validate(
            {"facts": [{"description": "Hecho", "kind": "alegacion", "certainty": "textual"}]}
        )
