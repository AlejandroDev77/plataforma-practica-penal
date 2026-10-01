from __future__ import annotations

import httpx
from pydantic import ValidationError

from app.core.config import Settings
from app.modules.simulation.schemas import SimulationProposal, SimulationProposalRequest
from app.modules.simulation.service import (
    LocalSimulationService,
    SimulationServiceError,
    validate_proposal,
)

OPENAI_RESPONSES_URL = "https://api.openai.com/v1/responses"


def proposal_schema(request: SimulationProposalRequest) -> dict[str, object]:
    """Esquema estricto acotado al rol y referencias de esta solicitud."""
    source_ids = [source.id for source in request.sources]
    return {
        "type": "object",
        "additionalProperties": False,
        "required": ["speaker_role", "content", "used_source_ids", "requires_human_review"],
        "properties": {
            "speaker_role": {"type": "string", "enum": [request.actor_role]},
            "content": {"type": "string", "minLength": 1, "maxLength": 3000},
            "used_source_ids": {
                "type": "array",
                "maxItems": len(source_ids),
                "items": {"type": "integer", **({"enum": source_ids} if source_ids else {})},
            },
            "requires_human_review": {"type": "boolean", "enum": [True]},
        },
    }


def response_text(payload: object) -> str:
    if not isinstance(payload, dict) or payload.get("status") != "completed":
        raise SimulationServiceError(
            "openai_incomplete_response", "OpenAI no completó el borrador solicitado.", 502
        )
    if payload.get("error") or payload.get("incomplete_details"):
        raise SimulationServiceError(
            "openai_incomplete_response", "OpenAI no completó el borrador solicitado.", 502
        )
    output = payload.get("output")
    if not isinstance(output, list):
        raise ValueError("Falta la salida estructurada.")
    texts: list[str] = []
    for item in output:
        if not isinstance(item, dict) or item.get("type") != "message":
            continue
        if item.get("role") != "assistant" or item.get("status") != "completed":
            raise ValueError("Mensaje incompleto o fuera de rol.")
        blocks = item.get("content")
        if not isinstance(blocks, list):
            raise ValueError("Falta el contenido del mensaje.")
        for block in blocks:
            if not isinstance(block, dict):
                raise ValueError("Bloque fuera de contrato.")
            if block.get("type") == "refusal":
                raise SimulationServiceError(
                    "openai_refusal", "OpenAI no pudo generar el borrador de esta práctica.", 422
                )
            if block.get("type") != "output_text" or not isinstance(block.get("text"), str):
                raise ValueError("Bloque fuera de contrato.")
            texts.append(block["text"])
    if len(texts) != 1 or not texts[0].strip():
        raise ValueError("Se requiere un único borrador estructurado.")
    return texts[0]


class OpenAISimulationService:
    def __init__(
        self, settings: Settings, transport: httpx.AsyncBaseTransport | None = None
    ) -> None:
        self.settings = settings
        self.transport = transport

    async def propose(self, request: SimulationProposalRequest) -> SimulationProposal:
        if self.settings.simulation_generation_provider != "openai":
            raise SimulationServiceError(
                "openai_not_configured", "OpenAI no está habilitado para las audiencias.", 503
            )
        if not request.allow_external_processing:
            raise SimulationServiceError(
                "external_processing_not_authorized",
                "Autorice el envío del contexto de este borrador a OpenAI antes de generarlo.",
                422,
            )
        key = self.settings.openai_api_key
        if key is None or not key.get_secret_value().strip():
            raise SimulationServiceError(
                "openai_api_key_not_configured",
                "Falta la clave de OpenAI en el servicio local.",
                503,
            )
        body = {
            "model": self.settings.openai_model,
            "store": False,
            "max_output_tokens": 1200,
            "input": [
                {"role": "system", "content": LocalSimulationService._system_prompt()},
                {"role": "user", "content": LocalSimulationService._user_prompt(request)},
            ],
            "text": {
                "format": {
                    "type": "json_schema",
                    "name": "borrador_intervencion_audiencia",
                    "strict": True,
                    "schema": proposal_schema(request),
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
                    headers={"Authorization": f"Bearer {key.get_secret_value()}"},
                    json=body,
                )
                response.raise_for_status()
            result = SimulationProposal.model_validate_json(response_text(response.json()))
            return validate_proposal(result, request, "openai")
        except SimulationServiceError:
            raise
        except httpx.TimeoutException as error:
            raise SimulationServiceError(
                "openai_timeout",
                "OpenAI excedió el tiempo permitido para preparar el borrador.",
                504,
            ) from error
        except httpx.HTTPStatusError as error:
            status = error.response.status_code
            if status in (401, 403):
                code, message, status = (
                    "openai_authentication_failed",
                    "Revise la clave y los permisos de OpenAI.",
                    503,
                )
            elif status == 429:
                code, message = (
                    "openai_rate_limited",
                    "OpenAI rechazó la solicitud por límite de uso o saldo.",
                )
            else:
                code, message, status = (
                    "openai_request_failed",
                    "OpenAI no pudo completar la solicitud del borrador.",
                    502,
                )
            raise SimulationServiceError(code, message, status) from error
        except httpx.ConnectError as error:
            raise SimulationServiceError(
                "openai_unavailable",
                "No se pudo conectar con OpenAI para preparar el borrador.",
                503,
            ) from error
        except (httpx.HTTPError, KeyError, TypeError, ValueError, ValidationError) as error:
            raise SimulationServiceError(
                "openai_invalid_response",
                "La respuesta de OpenAI no cumplió el contrato del borrador.",
                502,
            ) from error
