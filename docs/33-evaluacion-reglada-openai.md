# Fase 33 — Evaluación reglada con consentimiento por contexto

## Alcance

Integra el proveedor OpenAI de fase 32 con las prácticas regladas ya cerradas, únicamente cuando exista una activación vigente de una rúbrica copiada, revisada y aprobada. No activa, aprueba ni crea rúbricas jurídicas reales. La autorización académica de la rúbrica permanece bajo revisión profesional competente.

El propietario consulta `GET /api/v1/practicas-regladas/{practica}/contexto-evaluacion` para ver el texto exacto que se enviaría: rúbrica activada y transcripción acotada, con referencias de defensa. La respuesta es privada y `no-store`; no devuelve extractos de análisis del expediente ni acepta contenido proporcionado por el cliente. Cualquier cambio de acta, usuario, rúbrica o activación cambia la huella y requiere una nueva revisión/confirmación.

`POST /api/v1/practicas-regladas/{practica}/evaluacion` admite solo `confirmar_envio: true` y la huella mostrada. Laravel vuelve a construir el contexto dentro de una transacción y persiste una copia inmutable con la versión, revisión, activación, identidad de quien consiente, proveedor, fecha y consentimiento explícito. El trigger PostgreSQL comprueba las relaciones y estados actuales; no se deriva consentimiento de una preferencia global ni de la clave configurada. La operación crea la evaluación y despacha un solo trabajo. Las evaluaciones regladas no escriben resultados contra los criterios mutables del catálogo: guardan los criterios evaluados dentro del resultado de la evaluación.

El worker vuelve a verificar estado final, vínculo reglado, contexto sellado, hash, consentimiento, activación y revisión antes de contactar al servicio local. Solo la ruta nueva `/api/v1/evaluations/evaluate-reglada` puede usar OpenAI y requiere `EVALUATION_PROVIDER=openai` y consentimiento explícito en el cuerpo interno. No hay fallback a Ollama ni reintento automático para solicitudes externas; un estado incierto requiere consultar el resultado antes de una nueva acción. El cliente del servicio restringe Laravel a FastAPI local; OpenAI solo es contactado por FastAPI.

La UI presenta el proveedor y destino, permite revisar la rúbrica y cada intervención antes de enviar, solicita una confirmación explícita por huella, muestra estado/resultado con aviso de revisión humana y no vuelve a enviar automáticamente. El usuario propietario no puede consultar resultados ajenos. El texto seleccionado sale del equipo al proveedor configurado una vez confirmado; deben usarse datos sintéticos en pruebas hasta establecer un tratamiento institucional de expedientes reales.

## Límites y configuración

- `ai-service/.env` local: `EVALUATION_PROVIDER=openai`, `OPENAI_API_KEY` y `OPENAI_MODEL`; nunca confirmar ni imprimir la clave.
- Reiniciar FastAPI tras cambiar proveedor/clave. Laravel no recibe la clave del proveedor.
- No se hace llamada real a OpenAI en pruebas locales o CI.
- Errores públicos no muestran prompt, expediente, token ni detalles de facturación.
- La activación de prueba no representa acreditación profesional real ni autoriza el uso de expedientes auténticos.
- Una tarea de cola no debe repetirse automáticamente tras error de proveedor para evitar cargos duplicados.

## Verificación

FastAPI debe probar el enrutamiento separado, bloqueo sin consentimiento/proveedor y respuesta estructurada mediante HTTP simulado. Laravel debe probar aislamiento por propietario, rechazo de huella/consentimiento inválidos o cambiados, persistencia/trigger inmutable, despacho único y bloqueo de llamada HTTP antes del worker. Frontend: build, lint, pruebas de reglas y estados de revisión de la UI. Las pruebas destructivas de Laravel solo pueden utilizar PostgreSQL `jurissim_pruebas`.

## Resultado

La fase habilita un flujo técnico completo, no certifica el contenido jurídico de rúbricas ni reemplaza supervisión profesional. Antes de casos auténticos se necesita validar la política de tratamiento de datos, privacidad, seguridad, retención y revisión competente.
