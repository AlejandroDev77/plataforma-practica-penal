# Fase 32 — Proveedor OpenAI para evaluación

## Alcance de esta entrega

FastAPI incorpora un proveedor OpenAI para el contrato de evaluación estructurada. La selección se configura de forma independiente con `EVALUATION_PROVIDER`; el ejemplo local indica `openai`. Ollama y OpenAI no se usan como fallback entre sí. El modelo usa `OPENAI_MODEL` y la credencial permanece exclusivamente en `OPENAI_API_KEY` del entorno servidor.

La ruta interna `GET /api/v1/evaluations/provider` informa el proveedor/modelo sin exponer credenciales. La ruta anterior `POST /api/v1/evaluations/evaluate` conserva Ollama local y no cambia su comportamiento. La nueva `POST /api/v1/evaluations/evaluate-reglada` requiere el token entre servicios, proveedor OpenAI configurado y `external_authorized: true`; no hace fallback. El servicio comprueba el consentimiento antes de cualquier solicitud HTTP, envía solo la rúbrica/transcripción entregadas, usa Responses API con esquema estricto y `store: false`, y valida otra vez criterios, máximos y citas literales de la defensa. Errores y logs no incluyen el texto ni el token. Las pruebas usan transporte HTTP simulado; no hacen llamadas facturables.

## Límites actuales

La integración Laravel de la fase 33 se documenta en [fase 33](33-evaluacion-reglada-openai.md). El evaluador anterior mantiene sus rechazos de prácticas regladas. No se creó ni aprobó una rúbrica real, ni se simuló una competencia profesional.

Para activar OpenAI en un entorno local, configura `EVALUATION_PROVIDER=openai`, `OPENAI_API_KEY` y `OPENAI_MODEL` en el `.env` privado del servicio FastAPI, y reinicia FastAPI. No escribas la clave en el repositorio ni en tickets. Una ruta interna disponible no equivale a autorización para enviar datos: el consumidor debe obtener consentimiento explícito para cada evaluación.

## Verificación

- `pytest`: pruebas del proveedor con `httpx.MockTransport`; sin llamadas reales.
- `ruff`: lint del servicio y pruebas.
- No se ejecutó el proveedor con expedientes reales ni se integró un flujo Laravel de evaluación reglada.

## Configuración de proveedor

Para activar OpenAI en el entorno local, configura `EVALUATION_PROVIDER=openai`, `OPENAI_API_KEY` y `OPENAI_MODEL` en el `.env` privado del servicio FastAPI, y reinicia FastAPI. No escribas la clave en el repositorio ni en tickets. Una ruta interna disponible no equivale a autorización para enviar datos: Laravel exige el consentimiento de cada contexto concreto.
