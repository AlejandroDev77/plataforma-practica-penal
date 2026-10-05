# Fase 27 — API y orquestación de prácticas regladas

## Alcance

Conecta los comandos de fases 23–26 mediante ocho rutas privadas. No cambia las audiencias antiguas, su controlador ni su orquestador. No añade normas, aprobaciones, activaciones o acreditaciones profesionales reales. No modifica PostgreSQL habitual ni requiere una migración nueva. La conexión de la interfaz pertenece a la fase siguiente.

Todas las rutas parten de `/api/v1/practicas-regladas`, requieren Sanctum y responden `Cache-Control: no-store, private`, incluso en errores de autenticación, validación, conflicto y límite de solicitudes. El propietario se obtiene de la sesión; también se verifica la propiedad vigente del expediente. No se aceptan campos adicionales en comandos ni identificadores de propietario, participante, modelo o proveedor del cliente.

## Contrato HTTP

| Método | Ruta relativa | Datos / resultado |
| --- | --- | --- |
| POST | /expedientes/{expediente} | `id_analisis`, `id_tipo_audiencia`, `id_activacion`; crea en preparación, HTTP 201 |
| GET | /{practica}/orquestacion | Copia sellada, estado, turno, precondiciones y destinos candidatos; no llama al proveedor |
| POST | /{practica}/movimientos | `accion` iniciar/avanzar/finalizar, `estado_esperado`, `id_etapa_esperada` presente (null para inicio), `id_destino` opcional |
| POST | /{practica}/defensa | Contexto esperado, `contenido` (hasta 2.000 caracteres), `ids_fragmento` opcional (hasta 10) |
| GET | /{practica}/proveedor | Solo proveedor, modelo y necesidad de autorización externa; consulta técnica sin documentos ni generación |
| POST | /{practica}/borradores | Contexto esperado, `ids_fragmento` opcional (hasta 8), `autorizar_externo` booleano JSON opcional, false por defecto; HTTP 201 |
| GET | /{practica}/borradores/{borrador} | Texto, fuentes efectivamente enviadas, procedencia, vencimiento y eventual intervención confirmada |
| POST | /{practica}/borradores/{borrador}/confirmacion | Únicamente `confirmacion_humana: true` booleano JSON; HTTP 200 incluso al repetir una confirmación |

El contexto de defensa y generación exige `id_movimiento_esperado`, `id_etapa_esperada`, `id_turno_esperado` y `orden_esperado`. Se obtiene de la última consulta de orquestación; no se sustituye automáticamente cuando cambia. Los fragmentos deben ser enteros JSON positivos, distintos y contenidos en una lista. No se aceptan valores como la cadena "true", 1 o "yes" para consentimiento/confirmación. Las citas privadas y normas siguen las comprobaciones PostgreSQL anteriores.

Los comandos exitosos devuelven identificadores y resultado mínimo. No realizan una consulta adicional después del commit que pueda aparentar fallo de una acción ya aplicada. Reconsultar la orquestación después de cada acción. Si una respuesta se pierde, no reenviar generación automáticamente: puede haber coste o un borrador ya guardado. La confirmación del mismo borrador sí es idempotente con revalidación de dependencias.

## Orquestación: consulta, no permiso

`OrquestadorPracticaReglada` consulta el vínculo privado y vuelve a verificar contexto bajo el mismo orden de bloqueo PostgreSQL que los comandos. Solo proyecta nombres, instrucciones y destinos de la versión sellada. La consulta no genera textos, registra intervenciones, cambia etapas, reserva turnos ni otorga revisión jurídica.

Estados de consulta: preparando, esperando_agente, esperando_defensa, esperando_avance, esperando_destino, esperando_cierre, finalizada y bloqueada. Devuelve el movimiento, etapa, propuesta y orden esperados para una solicitud posterior. Comprueba correspondencia entre recibos e intervenciones, orden y roles; los comandos realizan las comprobaciones completas autoritativas al ejecutarse.

`technical_candidate_actions` son posibilidades técnicas observadas, nunca permisos. `read_only=true`, `execution_authorized=false` y `allowed_actions=[]` se mantienen siempre. `requires_command_integration=false` identifica este contrato nuevo ya conectado a comandos. El diagnóstico interno de fase 23 conserva su contrato anterior. Una práctica antigua sin vínculo queda bloqueada en el circuito nuevo; no se convierte ni acredita por consultarse. La ruta anterior `/simulaciones/{id}/orquestacion`, si existe en una instalación, no cambia de significado.

Al retirar/sustituir/vencer revisión o activación, rechazar el análisis, cambiar propiedad o perder integridad, no se ofrecen comandos candidatos. Las consultas de borradores históricos son privadas y no otorgan autorización de confirmación. Su vencimiento se calcula con PostgreSQL; confirmar revalida también fuentes, contexto y participante.

## Revisión humana y proveedor

Generar no consume el turno. La respuesta expone el texto y los extractos seleccionados efectivamente enviados (hasta 1.500 caracteres por fuente), no la preparación completa, análisis privado, rutas ni huellas internas. No se declara verificación semántica de citas ni corrección profesional por conservar IDs de fuentes. Confirmar exige revisión humana explícita; no permite cambiar el texto en esa petición.

OpenAI requiere autorización nueva y explícita en cada solicitud. La pasarela comprueba la declaración del proveedor y la coincidencia de proveedor/modelo de respuesta. Mantiene el contrato de fase 26: solo selección, rol, etapa e instrucción; sin enviar análisis ni historial completos. El servicio FastAPI debe exponer `/api/v1/simulations/provider` y respetar `allow_external_processing`; un servicio antiguo se rechaza, sin fallback silencioso. El frontend y la configuración real del proveedor todavía requieren aceptación conjunta antes de afirmar un recorrido real.

## Errores y límites

401 sesión ausente, 404 recurso privado inexistente/ajeno, 422 contrato de entrada o creación inválidos, 409 contexto/turno/proveedor no verificables, 429 límite y 503 servicio inesperadamente indisponible. El manejador de excepciones se limita a este prefijo: evita SQL, parámetros, trazas y cuerpos del proveedor aun con APP_DEBUG activo. No registra automáticamente excepciones crudas de estas rutas, porque pueden contener documentos; la auditoría de comandos confirmados sigue siendo inmutable. Las rutas antiguas conservan su manejo de errores.

Límites independientes por usuario: generación 5/minuto, comandos 10/minuto (compartidos entre crear, mover, defensa y confirmar), consulta del proveedor 10/minuto. No se comparte accidentalmente la cuota de generación con las consultas de orquestación. No hay reintentos automáticos ni avance automático.

## Verificación y siguiente fase

Pruebas con PostgreSQL exclusivamente `jurissim_pruebas`, guardias de conexión efectiva/configurada, sin DB_URL ni configuración cacheada; transacciones reales para respetar la generación fuera de transacciones. Pasarelas simuladas y HTTP externo bloqueado: no consumen saldo. El fixture sintético común se comparte con las pruebas de fase 26 sin modificar el comportamiento de sus comandos. La prueba de vencimiento cambia fechas sintéticas retirando únicamente el trigger de inmutabilidad correspondiente y lo restaura de inmediato.

La fase siguiente conectará la interfaz a este contrato: creación con activación explícita, inicio, selección/consentimiento, revisión de borradores, defensa y cierre. Después corresponde aceptación con un revisor profesional acreditado para reglas bolivianas, sin convertir ejemplos sintéticos en autorización jurídica.

## Evidencia de entrega

La suite completa de la rama pasó con 387 pruebas y 2.626 aserciones, incluidas 17 pruebas del contrato HTTP. Se verificaron el recorrido juez/fiscal/defensa/cierre, confirmación repetida, autenticación, propiedad, selección, consentimiento, precondiciones, retiro, copia sellada, privacidad, vencimiento y cuotas. La primera ejecución detectó cuotas compartidas y errores renderizados antes del middleware envolvente; ambos se corrigieron mediante límites nombrados independientes y un manejador restringido al prefijo nuevo. Pint pasó.

En la instalación habitual, frontend, Laravel y FastAPI respondieron HTTP 200; una consulta sin sesión al orquestador nuevo devolvió 401 con no-store/private. La consulta no generativa de configuración a FastAPI declaró OpenAI, modelo gpt-4o-mini y autorización externa requerida. Esto no prueba validez de la clave, saldo ni una generación real; no se enviaron documentos o instrucciones al modelo.

Los catorce conteos consultados de dominio/registros habituales coinciden con la entrega anterior: un usuario, dos expedientes, dos análisis, una simulación antigua, sin intervenciones, borradores, vínculos o movimientos reglados ni registros de aprobación jurídica. No se ejecutaron migraciones ni comandos mutantes contra jurissim.

En la carpeta habitual pasaron 275 pruebas seleccionadas y 1.742 aserciones, incluyendo el circuito HTTP nuevo, propuestas/orquestación antiguas y las capas previas de reglas, vínculos, movimientos y defensa.

Tras mover el registro de cuotas al proveedor de servicios, pasaron además las 17 pruebas HTTP (446 aserciones) con route:cache en la rama y 58 pruebas (709 aserciones) del circuito nuevo junto con propuestas/orquestación antiguas con rutas cacheadas en la carpeta habitual. Se usó un archivo de caché temporal separado por proceso, sin cambiar la caché habitual, y se retiró al terminar.

Se trasladaron únicamente los doce archivos propios tras comprobar originales y equivalencia final, conservando rutas, orquestador antiguo, controladores existentes, frontend, FastAPI y configuración ajenos. Las cuotas se registran en AppServiceProvider, no en el archivo de rutas, para permanecer disponibles al usar route:cache. Se trabaja en la rama funcional local existente, con develop actualizado; sin push, PR, merge a develop/main ni modificación de protecciones.
