# Orquestación de audiencia: consulta técnica inicial

Estado: implementación local de solo lectura. No es todavía el ejecutor completo del procedimiento boliviano ni acredita revisión jurídica. No modifica catálogos ni prácticas existentes.

## Alcance

`GET /api/v1/simulaciones/{simulacion}/orquestacion` requiere `auth:sanctum`. Solo devuelve prácticas cuyo propietario y expediente pertenecen al usuario autenticado; una práctica ajena devuelve `404`. No acepta propietario, rol, instrucciones ni decisiones del navegador.

`OrquestadorAudiencia` reúne la etapa, la última revisión del análisis (fecha e ID como desempate), la instantánea de turnos, el historial de la etapa y los participantes. La secuencia debe ser una lista de hasta 100 turnos con órdenes enteros positivos crecientes y roles actualmente soportados: juez, fiscal y abogado defensor. Una secuencia malformada no se filtra ni repara silenciosamente. Otros intervinientes del procedimiento requieren soporte explícito posterior; no se sustituyen por agentes existentes.

El historial debe coincidir con los roles y el tipo de control de los turnos anteriores. El siguiente turno exige exactamente un participante activo compatible. Una propuesta de agente requiere además una instrucción no vacía. El cierre exige etapa final y secuencia completa; un avance exige una salida activa hacia una etapa activa del mismo tipo de audiencia. Varias salidas requieren elección: el modelo no selecciona una.

## Contrato

La respuesta está dentro de `data` y contiene:

| Campo | Significado |
| --- | --- |
| `simulation_id`, `read_only` | Práctica consultada; consulta sin escrituras del dominio. |
| `technical_status` | `bloqueada`, `esperando_usuario`, `esperando_agente`, `esperando_avance`, `esperando_destino`, `esperando_cierre` o `finalizada`. |
| `stage`, `current_turn`, `current_participant` | IDs, nombres/códigos y rol/control del contexto vigente; nullable si no puede resolverse. |
| `analysis` | ID del análisis y decisión de su última revisión, sin texto del expediente. |
| `technical_candidate_actions` | Candidatos técnicos: `submit_text_intervention`, `request_agent_proposal`, `advance_stage` o `finish_simulation`. No son autorizaciones. |
| `technical_blocking_reasons` | Códigos de inconsistencias o prerrequisitos ausentes. |
| `candidate_next_stages` | Destinos candidatos únicamente cuando es técnicamente posible un avance. |
| `requires_provider_check` | Una propuesta requeriría comprobar proveedor y autorización de tratamiento; no certifica disponibilidad ni permiso. |
| `legal_review_status`, `practice_ready`, `allowed_actions` | Siempre `no_acreditada`, `false`, `[]` en este hito. |
| `blocking_reasons` | Bloqueos técnicos y `revision_juridica_secuencia_no_acreditada`. |

Bloqueos técnicos posibles: `audiencia_no_activa`, `tipo_audiencia_no_disponible`, `etapa_no_disponible`, `analisis_no_aprobado`, `rol_usuario_no_soportado`, `secuencia_no_configurada`, `secuencia_invalida_o_rol_no_soportado`, `historial_incompatible_con_secuencia`, `participante_no_disponible`, `participante_ambiguo`, `instruccion_agente_ausente`, `etapa_sin_transicion_disponible`.

## Límites y seguridad

- No genera ni confirma intervenciones, no avanza etapas, no ejecuta jobs y no contacta Ollama, FastAPI ni OpenAI. No expone instrucciones, contenido documental, claves o rutas privadas. La autenticación puede actualizar sus propios metadatos según el mecanismo utilizado.
- Un análisis aprobado no equivale a una secuencia jurídicamente aprobada. Ni un turno activo, ni un administrador técnico, ni un flag `revision_juridica` en JSON acreditan esa aprobación.
- Es una fotografía de consulta, no un permiso de ejecución ni una reserva de turno. Ante concurrencia, los futuros comandos deben volver a resolver todos los prerrequisitos dentro de una transacción con bloqueo. No usar una respuesta previa como autoridad.
- Las rutas de mutación existentes no se cambian ni quedan protegidas por este diagnóstico. Integrarlas con un control común forma parte del siguiente hito; este endpoint no reemplaza sus validaciones actuales.
- Los turnos se leen de la instantánea existente; disponibilidad de etapas/transiciones se consulta en el catálogo actual. Aún faltan una versión completa e inmutable de reglas y transiciones, su aprobación jurídica verificable, soporte de intervinientes, incidencias y ejecución de comandos. No se afirma que el catálogo inicial reproduzca un procedimiento judicial vigente.
- No hay frontend nuevo ni activación automática. La matriz documental boliviana sigue siendo un borrador separado: no se importa a tablas activas.

## Verificación

`backend/tests/Feature/Api/OrquestacionAudienciaTest.php` utiliza escenarios sintéticos y bloquea HTTP externo. Antes de refrescar el esquema comprueba conexión PostgreSQL, base configurada y base efectiva `jurissim_pruebas`, ausencia de `DB_URL` y de configuración cacheada. Nunca ejecutarla contra la base habitual.

Se cubren aislamiento y autenticación, revisión ausente/revocada, secuencias inválidas, participantes ausentes/ambiguos, inconsistencias de historial, cierre, destinos múltiples/inactivos, flags sin autoridad y consultas repetidas sin mutaciones del dominio ni llamadas a proveedores.

Verificación local del 1 de octubre de 2026: suite de backend en la rama de trabajo, 111 pruebas y 735 aserciones aprobadas; las 20 pruebas nuevas (121 aserciones) también pasaron en la carpeta habitual con sus cambios previos. Pint y registro de ruta correctos. Sin migraciones nuevas ni ejecución de pruebas contra `jurissim`.

Siguiente paso: definir el registro y la autoridad de revisión jurídica de una versión de reglas, y después conectar comandos transaccionales al mismo coordinador. No activar reglas sin esa revisión.
