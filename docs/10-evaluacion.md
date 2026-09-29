# Evaluación de prácticas de audiencia

## Alcance de este hito

El backend acepta una solicitud de evaluación únicamente cuando la simulación del usuario autenticado ya terminó, contiene al menos una intervención de la defensa y existe una sola rúbrica activa aplicable. Laravel elige la rúbrica en el servidor; el cliente no puede seleccionar rúbrica, modificar puntajes ni sustituir la transcripción.

Esta entrega incluye la API autenticada, el trabajo en cola, el contrato FastAPI/Ollama, la persistencia por criterio, la consulta del resultado y su interfaz en la pantalla de práctica finalizada. No incorpora una rúbrica jurídica precargada. Las rúbricas reales y sus versiones deben pasar por revisión profesional antes de activarse; las pruebas usan contenido sintético.

## Selección y alcance de la rúbrica

Se buscan rúbricas activas compatibles con el tipo de audiencia y el rol de la práctica (`abogado_defensor`). Una coincidencia específica prevalece sobre una rúbrica genérica. Si no hay una aplicable, tiene criterios vacíos o hay varias igualmente específicas, el sistema rechaza la solicitud; no elige una al azar.

Al crear la evaluación se guarda una instantánea de versión y criterios para que una revisión futura pueda reconstruir qué escala se aplicó. El catálogo no debe activarse con contenido jurídico sin revisión competente.

## API de la aplicación

Ambas rutas requieren la sesión Sanctum y limitan la simulación al usuario propietario:

```http
POST /api/v1/simulaciones/{simulacion}/evaluacion
GET  /api/v1/simulaciones/{simulacion}/evaluacion
GET  /api/v1/simulaciones/{simulacion}/evaluaciones/{evaluacion}
```

El `POST` no acepta cuerpo y limita nuevas solicitudes a tres por minuto. Devuelve `202 Accepted` y una evaluación `pendiente`; el `GET .../evaluacion` permite recuperar la evaluación más reciente después de recargar la página, y el cliente consulta esa ruta hasta recibir `procesado` o `error`. El `GET .../evaluaciones/{evaluacion}` conserva la consulta puntual por identificador. Una segunda solicitud mientras haya otra evaluación en curso responde conflicto. El resultado procesado contiene el puntaje ponderado normalizado de 0 a 100, resumen, fortalezas, errores, recomendaciones y observaciones por criterio.

Las fortalezas y errores deben citar texto literal de una intervención de `abogado_defensor` mediante su orden. FastAPI y Laravel vuelven a validar que las citas pertenezcan a la transcripción autorizada. Cada resultado incluye `requires_human_review: true`; la puntuación es orientativa, no determina la verdad de hechos ni sustituye asesoramiento jurídico.

## Procesamiento local

Laravel construye el contexto desde la base de datos y lo envía al servicio interno FastAPI con el token compartido. Solo se incluyen nombre del tipo de audiencia, versión y criterios de la rúbrica, turnos textuales de defensa, juez y fiscal, y los extractos ya verificados que la defensa adjuntó a sus propias intervenciones. No se envían el expediente completo, usuario, correo, propietario ni contenido suministrado por el navegador. Se limitan 40 intervenciones, 2.000 caracteres por intervención, diez extractos por intervención y 20.000 caracteres de contexto; la rúbrica admite hasta 20 criterios.

La ruta interna es `POST /api/v1/evaluations/evaluate`. Requiere el token del servicio y usa Ollama exclusivamente en `127.0.0.1:11434`. `LLM_PROVIDER=disabled` continúa siendo el valor predeterminado; no existe fallback a un proveedor externo. La respuesta JSON estricta debe puntuar todos y solo los criterios recibidos, respetar el máximo de cada uno, conservar evidencia literal verificable de la intervención de la defensa y exigir revisión humana. Laravel calcula el resultado ponderado y persiste las filas por criterio en una transacción. Los extractos adjuntos sirven como contexto de lo que la defensa citó; no certifican que una norma o un documento sustente jurídicamente su argumento.

Los errores al procesar no exponen transcripción ni detalles internos al cliente. Para reintentar una evaluación fallida se crea una solicitud nueva; una evaluación pendiente o procesándose bloquea duplicados.

## Interfaz y límites conocidos

La pantalla de una práctica finalizada permite solicitar una devolución, seguir su procesamiento, recuperar el estado tras recargar y consultar puntaje, criterios, observaciones, citas literales y sugerencias. Si todavía no existe una rúbrica aplicable, informa que el equipo académico debe revisarla y habilitarla. Una evaluación fallida se puede volver a solicitar. La interfaz no califica automáticamente como aprobado/reprobado.

## Verificación

- Suite Python: `ai-service` ejecuta pruebas de esquema, límites, token, contrato y rechazo de evidencia/puntajes inválidos.
- Suite Laravel: `backend` ejecuta sus pruebas con PostgreSQL `jurissim_pruebas`; no apuntar `RefreshDatabase` a `jurissim`.
- Aceptación local opcional: caso sintético inédito con `JURISSIM_EJECUTAR_OLLAMA_LOCAL=1` y `OLLAMA_NO_CLOUD=1`. No usa expedientes reales ni credenciales externas.

La prueba con `qwen3.5:2b-q4_K_M` verifica que un caso sintético con una fuente adjunta pueda producir salida estructurada y citas contrastables; no demuestra consistencia de puntajes ni calidad jurídica en casos variados. El MVP aún necesita una rúbrica jurídicamente revisada, una batería más amplia de transcripciones sintéticas y revisión humana de las salidas antes de cualquier uso formativo.
