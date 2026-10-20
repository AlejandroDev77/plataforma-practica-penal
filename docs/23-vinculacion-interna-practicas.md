# Vinculación interna de prácticas nuevas a reglas exactas

Continuación de la fase 22. Este hito prepara el contexto inmutable que necesitarán los comandos del motor. No conecta todavía la creación pública, el orquestador, los avances ni las intervenciones a esta representación. No modifica el procedimiento jurídico boliviano ni concede autoridad profesional a ninguna cuenta.

## Creación interna, sin migración retrospectiva

`CrearPracticaConReglas::ejecutar(actor, idExpediente, idAnalisis, idTipo, idActivacion)` crea una práctica nueva en `preparando`, sin etapa actual, inicio, cierre ni intervenciones. El actor debe proceder del servidor autenticado. Su expediente y análisis se reconsultan con alcance de propietario; el análisis debe estar procesado y su última revisión humana debe ser `aprobado`.

El ID de activación es explícito. No se selecciona otra activación cuando la solicitada quedó retirada, vencida o sustituida. El tipo debe corresponder y permanecer activo, la revisión exacta debe ser la última de su versión, cubrir el contrato completo y conservar una habilitación vigente. Se comprueban perfil, contrato y SHA-256 del JSONB completo.

La configuración se proyecta únicamente desde las propuestas e instrucciones de la versión sellada. Cada turno conserva ID de propuesta, orden, rol e instrucción literal, incluidos espacios y saltos de línea; no copia una descripción como instrucción ni consulta turnos del catálogo editable. Se crean los participantes de los roles presentes en la copia y la defensa del usuario, sin invocar agentes o proveedores.

`vinculaciones_reglas_practica` conserva un registro único por práctica: activación, versión, tipo, responsable del servidor, huella, perfil y fecha PostgreSQL. La creación y la vinculación son atómicas. El servicio no acepta una práctica existente: no convierte audiencias históricas ni reasigna sus reglas. El trigger valida el estado vacío y compatible al insertar, pero no prueba criptográficamente que una fila SQL haya nacido en esa misma transacción. No se ofrece un comando para vincular prácticas anteriores.

## Vigencia orientativa y privacidad

`ConsultarVinculacionPractica::ejecutar(actor, idSimulacion)` exige la propiedad tanto de la simulación como del expediente. No revela instrucciones, contenido del expediente o evidencia de acreditación. Devuelve identificadores, huella y estado del vínculo, siempre con `read_only=true`, `execution_authorized=false` y `requires_command_integration=true`.

La función PostgreSQL de vigencia vuelve a consultar dependencias y utiliza el reloj de la base. Estados: `sin_activacion`, `vigente`, `activacion_sustituida`, `retirada`, `revision_sustituida`, `revision_retirada`, `revision_no_aprobada`, `habilitacion_retirada`, `habilitacion_vencida`, `revision_vencida`, `tipo_inactivo` e `integridad_incompatible`. La consulta de práctica añade `sin_vinculacion` y `analisis_no_aprobado`. La sustitución administrativa se informa sin cambiar el ID histórico del vínculo ni recuperar una activación anterior.

Esta lectura no reserva vigencia ni sustituye controles de escritura. Las consultas de dependencias pueden observar cambios concurrentes y no constituyen permiso para ejecutar. En una siguiente fase, cada comando deberá revalidar dentro de su transacción el vínculo exacto, análisis, reglas, turno y fuentes.

## Bloqueos y protección de historial

La creación normal bloquea expediente y análisis; luego tipo con `FOR NO KEY UPDATE`, habilitación y versión, en ese orden. El trigger repite las comprobaciones con aislamiento `READ COMMITTED` y bloqueos de dependencias y práctica. La función de vigencia es `VOLATILE` para volver a leer tras esperas, no reutilizar una fotografía de inicio del comando. El uso de `NO KEY UPDATE` sobre tipo conserva compatibilidad con los `KEY SHARE` de claves foráneas.

Modelos y triggers impiden actualizar, borrar y truncar vínculos. Las claves foráneas restringen eliminaciones de sus dependencias: una práctica vinculada y su expediente/usuario no se eliminan en cascada borrando este historial. Eso es retención técnica de evidencia, no una política completa de conservación de datos.

Para que los comandos antiguos no ejecuten una representación que todavía no comprenden, PostgreSQL bloquea cambios de contexto, configuración, estado, etapa y fechas de ejecución de una práctica vinculada; también bloquea sus nuevas intervenciones. La fecha de actualización puede cambiar. Las prácticas sin vínculo siguen usando el motor anterior, sin modificación automática ni una nueva garantía jurídica. Este hito no habilita ni promete su ejecución bajo reglas revisadas.

El rollback de esta migración se niega si existe cualquier vínculo; si está vacío, retira únicamente sus tablas, triggers y funciones. Las pruebas de rollback anteriores retiran primero esta dependencia y la reinstalan al final. `CREATE OR REPLACE FUNCTION` permite reinstalar funciones que PostgreSQL conserva después de un refresco de tablas en la base de pruebas. Un propietario SQL con permisos DDL puede omitir protecciones: la huella no es firma digital ni barrera frente a ese propietario.

## Migración y verificación

Migración aditiva: `2026_10_04_000002_vincular_practicas_reglas.php`. No introduce credenciales, SQLite, Docker, endpoints o dependencias. Tras verificar la base efectiva habitual y las migraciones previas, el comando específico es:

```powershell
php artisan migrate --path=database/migrations/2026_10_04_000002_vincular_practicas_reglas.php
```

Nunca ejecutar `migrate:fresh` en la base habitual. Las pruebas destructivas usan exclusivamente `jurissim_pruebas`, guardando base configurada y efectiva, PostgreSQL, ausencia de `DB_URL` y configuración cacheada. Las credenciales se suministran solo al proceso, sin imprimirlas ni guardarlas. Todos los expedientes, acreditaciones, revisiones e instrucciones de prueba son sintéticos; HTTP externo está bloqueado.

La batería nueva comprueba creación exacta, propietario, aprobación de análisis, retiros, sustituciones, vencimiento con reloj PostgreSQL, catálogo editable, legado intacto, privacidad, inmutabilidad ORM/SQL, cascadas, rechazo del motor antiguo, inserciones SQL falsificadas, aislamiento incompatible y rollback con evidencia. No es una prueba de carga concurrente ni una revisión normativa profesional.

Verificación del 4 de octubre de 2026: suite completa de la rama con 292 pruebas y 1.735 aserciones aprobadas, incluidas 20 pruebas nuevas; carpeta habitual con 221 pruebas de reglas y orquestación y 1.242 aserciones aprobadas. Pint y revisión de espacios correctos. La primera regresión detectó funciones persistentes tras refrescar tablas; se corrigió su reinstalación antes de las suites finales. No hubo llamadas a proveedores.

Se aplicó únicamente esta migración en PostgreSQL habitual `jurissim`, comprobando conexión efectiva local `127.0.0.1`, ausencia de `DB_URL` y configuración cacheada y presencia de la migración anterior. Los conteos de usuarios, expedientes, análisis, prácticas, intervenciones, versiones, revisiones, habilitaciones, activaciones y retiros permanecieron iguales. La tabla nueva está vacía: no se otorgaron permisos ni se crearon vínculos o aprobaciones reales. No se cambió el entorno configurado de la aplicación.

Archivos propios de esta fase: `VinculacionReglasPractica`, `CrearPracticaConReglas`, `ConsultarVinculacionPractica`, migración y pruebas de vinculación, ajustes de orden de rollback en `VersionReglasAudienciaTest` y `RevisionJuridicaReglasTest`, este documento y enlace desde la fase 22. Se conservan cambios ajenos del backend, frontend y servicio de inteligencia. Desarrollo y commit locales en la rama existente de versiones, dependiente de las fases anteriores; sin push, PR, merge a develop/main ni cambios en protecciones.

## Siguiente fase

Integrar comandos de inicio, avance e intervención con esta base: etapas y transiciones desde la versión inmutable, revalidación bajo bloqueo por cada mutación y rechazo de contexto obsoleto. Solo después exponer creación y diagnóstico al flujo público y su interfaz. No tomar el catálogo vivo o una consulta anterior como autoridad de ejecución.

El comando interno de inicio, avance y cierre se incorpora en [24-movimientos-internos-practicas.md](24-movimientos-internos-practicas.md), mediante movimientos inmutables y actualización atómica. Las intervenciones y el flujo público siguen pendientes; este documento conserva el alcance histórico de la fase 23.
