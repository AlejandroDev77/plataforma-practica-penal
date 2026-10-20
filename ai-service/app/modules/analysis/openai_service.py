from __future__ import annotations

import json

import httpx
from pydantic import ValidationError

from app.core.config import Settings
from app.modules.analysis.grounding import validate_source_grounding
from app.modules.analysis.schemas import AnalysisBatchRequest, StructuredCaseAnalysis
from app.modules.analysis.service import (
    AnalysisServiceError,
    LocalAnalysisService,
    add_explicit_dates_to_chronology,
    analysis_generation_schema,
)

OPENAI_RESPONSES_URL = "https://api.openai.com/v1/responses"


def strict_analysis_schema() -> dict[str, object]:
    schema = json.loads(json.dumps(analysis_generation_schema()))

    def require_all_properties(node: object) -> None:
        if not isinstance(node, dict):
            return

        node.pop("default", None)
        node.pop("title", None)
        properties = node.get("properties")
        if isinstance(properties, dict):
            node["required"] = list(properties)
            node["additionalProperties"] = False
            for property_schema in properties.values():
                require_all_properties(property_schema)

        for definitions_key in ("$defs", "definitions"):
            definitions = node.get(definitions_key)
            if isinstance(definitions, dict):
                for definition_schema in definitions.values():
                    require_all_properties(definition_schema)

        for schema_key in ("items", "additionalProperties", "not"):
            require_all_properties(node.get(schema_key))

        for schema_key in ("anyOf", "oneOf", "allOf", "prefixItems"):
            alternatives = node.get(schema_key)
            if isinstance(alternatives, list):
                for alternative_schema in alternatives:
                    require_all_properties(alternative_schema)

    require_all_properties(schema)
    return schema


def response_text(payload: object) -> str | None:
    if not isinstance(payload, dict) or not isinstance(payload.get("output"), list):
        return None

    for item in payload["output"]:
        if not isinstance(item, dict) or item.get("type") != "message":
            continue
        content = item.get("content")
        if not isinstance(content, list):
            continue
        for block in content:
            if not isinstance(block, dict):
                continue
            if block.get("type") == "refusal":
                raise AnalysisServiceError(
                    "openai_refusal", "OpenAI no pudo completar la lectura solicitada.", 422
                )
            if block.get("type") == "output_text" and isinstance(block.get("text"), str):
                return block["text"]

    return None


def remap_source_page_ids(
    result: StructuredCaseAnalysis,
    page_ids_by_alias: dict[int, int],
) -> None:
    findings = [result.summary, result.procedural_stage]
    findings.extend(result.participants)
    findings.extend(result.offenses)
    findings.extend(result.facts)
    findings.extend(result.evidence)
    findings.extend(result.chronology)
    findings.extend(result.missing_information)
    findings.extend(result.uncertainties)

    for finding in findings:
        if finding is None:
            continue
        sources = getattr(finding, "sources", None)
        if sources is None:
            sources = getattr(finding, "context_sources", [])
        for source in sources:
            original_page_id = page_ids_by_alias.get(source.page_id)
            if original_page_id is None:
                raise ValueError("La respuesta citó una página que no se envió al proveedor.")
            source.page_id = original_page_id


class OpenAIAnalysisService:
    def __init__(
        self,
        settings: Settings,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        self.settings = settings
        self.transport = transport

    async def analyze(self, request: AnalysisBatchRequest) -> StructuredCaseAnalysis:
        api_key = self.settings.openai_api_key
        if api_key is None or not api_key.get_secret_value().strip():
            raise AnalysisServiceError(
                "openai_api_key_not_configured",
                "Falta configurar una clave nueva de OpenAI en el servicio local.",
                503,
            )

        page_ids_by_alias = {
            index: page.page_id for index, page in enumerate(request.pages, start=1)
        }
        payload = {
            "model": self.settings.openai_model,
            "store": False,
            "max_output_tokens": 4096,
            "input": [
                {"role": "system", "content": LocalAnalysisService._system_prompt()},
                {
                    "role": "user",
                    "content": json.dumps(
                        {
                            "pages": [
                                {"page_id": alias, "text": page.text}
                                for alias, page in enumerate(request.pages, start=1)
                            ]
                        },
                        ensure_ascii=False,
                    ),
                },
            ],
            "text": {
                "format": {
                    "type": "json_schema",
                    "name": "analisis_estructurado_expediente",
                    "strict": True,
                    "schema": strict_analysis_schema(),
                }
            },
        }

        try:
            async with httpx.AsyncClient(
                timeout=self.settings.openai_timeout_seconds,
                transport=self.transport,
                follow_redirects=False,
            ) as client:
                response = await client.post(
                    OPENAI_RESPONSES_URL,
                    headers={
                        "Authorization": f"Bearer {api_key.get_secret_value()}",
                        "Content-Type": "application/json",
                    },
                    json=payload,
                )
                response.raise_for_status()
            content = response_text(response.json())
            if content is None:
                raise AnalysisServiceError(
                    "openai_invalid_response",
                    "OpenAI no devolvió una lectura estructurada completa.",
                    502,
                )
            result = StructuredCaseAnalysis.model_validate_json(content)
        except AnalysisServiceError:
            raise
        except httpx.ConnectError as error:
            raise AnalysisServiceError(
                "openai_unavailable",
                "No se pudo conectar con OpenAI para completar la lectura.",
                503,
            ) from error
        except httpx.TimeoutException as error:
            raise AnalysisServiceError(
                "openai_timeout",
                "OpenAI excedió el tiempo permitido para completar la lectura.",
                504,
            ) from error
        except httpx.HTTPStatusError as error:
            if error.response.status_code in (401, 403):
                code, message, status_code = (
                    "openai_authentication_failed",
                    "La clave o los permisos del proyecto de OpenAI no permiten esta solicitud.",
                    503,
                )
            elif error.response.status_code == 429:
                code, message, status_code = (
                    "openai_rate_limited",
                    "OpenAI rechazó la solicitud por límite de uso o facturación.",
                    429,
                )
            else:
                code, message, status_code = (
                    "openai_request_failed",
                    "OpenAI no pudo completar la solicitud de análisis.",
                    502,
                )
            raise AnalysisServiceError(code, message, status_code) from error
        except (httpx.HTTPError, KeyError, TypeError, ValueError, ValidationError) as error:
            raise AnalysisServiceError(
                "openai_invalid_response",
                "La respuesta de OpenAI no cumplió el contrato estructurado de JURISSIM.",
                502,
            ) from error

        if result.summary is None:
            raise AnalysisServiceError(
                "analysis_incomplete", "La lectura no incluyó un resumen verificable.", 422
            )

        try:
            remap_source_page_ids(result, page_ids_by_alias)
            validate_source_grounding(result, request)
            result = add_explicit_dates_to_chronology(result, request)
            return validate_source_grounding(result, request)
        except ValueError as error:
            raise AnalysisServiceError(
                "analysis_unverifiable_citations",
                "La respuesta incluyó citas que no coinciden con las páginas seleccionadas.",
                422,
            ) from error
