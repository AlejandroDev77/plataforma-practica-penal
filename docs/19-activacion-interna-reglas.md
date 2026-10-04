# Activación interna de contratos de audiencia

Estado: persistencia, comandos internos y diagnóstico implementados. Continúa la preparación de `18-preparacion-activacion-reglas.md`. No hay nuevos endpoints, interfaz de activación ni vinculación con simulaciones. No se conceden habilitaciones jurídicas reales ni se generan instrucciones o revisiones mediante un proveedor.

Se conserva `funcionalidad/versiones-reglas-audiencia`: sus dependencias previas todavía son commits locales pendientes de publicación. No se integra mediante un merge local ni se omiten checks.

## Instrucciones dentro de la versión sellada

`SellarVersionReglasAudiencia::ejecutar(actor, tipo, motivo, instrucciones?, confirmacionInstrucciones)` mantiene su uso anterior: sin instrucciones crea únicamente la copia histórica. El parámetro adicional exige el valor booleano exacto `true`; no acepta cadenas o números como confirmación.

Para crear una copia con contrato, la estructura debe superar el perfil técnico anterior. Cada propuesta requiere exactamente una instrucción explícita, identificada por `id_propuesta_turno`, con texto no vacío de hasta 5.000 caracteres. No se aceptan propuestas ajenas, duplicadas, IDs coercionados, roles, etapas ni campos adicionales enviados como parte de las instrucciones. No se copian descripciones o instrucciones del catálogo.

El campo adicional `contrato_ejecucion` queda dentro del JSONB inmutable antes de calcular su SHA-256. Mantiene el formato histórico de la copia y contiene su propio formato 1, perfil `mvp-defensa-aciclico-ejecucion-v1`, rol de usuario `abogado_defensor` e instrucciones ordenadas según las propuestas de la copia. Etapa, orden, rol y autoría proceden del snapshot del servidor. El conjunto completo sigue limitado a 2.000.000 bytes de JSONB.

Esto es compatibilidad técnica del MVP, no certificación del procedimiento boliviano ni de la suficiencia jurídica de un recorrido. No elige automáticamente una bifurcación. Los ciclos continúan rechazados hasta contar con un diseño específico de ejecución e historial.

## Revisión explícita del contrato completo

Una copia que contiene instrucciones exige un quinto parámetro explícito en `GestionarRevisionJuridicaReglas::registrar`: la huella exacta del contenido completo que el revisor confirmó haber examinado. Se conserva como `huella_ejecucion_revisada`; un trigger también exige ese dato y valida el contrato. Siguen vigentes la independencia del revisor, acreditación, fuentes, confirmación humana y vencimiento del mecanismo anterior.

Una revisión histórica sin contrato no adquiere esa cobertura retrospectivamente. No se edita una versión ni una revisión para añadir instrucciones. Hay que sellar una nueva copia completa y revisarla.

**La API y la interfaz actuales no presentan todavía este contrato para revisión.** No pasan el quinto parámetro: aunque un cliente envíe una huella adicional, no podrán aprobar una copia ejecutable mediante el formulario anterior. Esto se verifica con una prueba HTTP. La próxima fase deberá mostrar todas las instrucciones y obtener una confirmación específica antes de habilitar este flujo público.

La huella es control de integridad, no firma digital ni prueba automática de idoneidad profesional. La acreditación real y la revisión normativa siguen siendo responsabilidad humana.

## Activación administrativa separada

`GestionarActivacionReglas::activar(actor, idVersion, datos)` requiere administración de plataforma reconsultada, motivo, confirmación estricta y tres precondiciones: `huella_version_esperada`, `id_revision_esperada` e `id_activacion_previa` presente, incluso cuando sea `null` en la primera activación.

Dentro de la transacción se serializa el destino por tipo con `FOR NO KEY UPDATE`, después se bloquean habilitación y versión, en ese orden. El bloqueo de tipo permite los `KEY SHARE` de las claves foráneas de una revisión en curso; usar `FOR UPDATE` allí podría formar un ciclo de espera con habilitación/versión. Se comprueban nuevamente preparación, huella, revisión exacta más reciente, cobertura de instrucciones y activación previa. El trigger exige aislamiento `READ COMMITTED`, reloj PostgreSQL, tipo actualmente activo y revisión/habilitación sin retiro ni vencimiento.

El registro fija versión, revisión, habilitación, hash, perfil, responsable del servidor, motivo, confirmación, activación previa y fecha del servidor. Solo puede utilizarse una vez cada revisión: reactivar una versión retirada requiere una nueva revisión vigente y una nueva activación explícita. No se admite un ID de activación menor o igual al anterior, incluso por inserción SQL, para impedir que una asignación de secuencia anterior o un ID manipulado esconda el nuevo registro.

`retirar(actor, idActivacion, motivo)` agrega un retiro inmutable. Un reintento autorizado devuelve el retiro original sin reemplazar su motivo. Los modelos y triggers impiden actualizar, borrar o truncar este historial; las claves foráneas restringen eliminaciones de sus dependencias.

## Consulta, no permiso para el motor

`ConsultarActivacionReglas::ejecutar(actor, idTipo)` es administrativa y de solo lectura. Una única consulta PostgreSQL examina la última activación del tipo y sus dependencias. Estados: `sin_activacion`, `vigente`, `retirada`, `revision_sustituida`, `revision_retirada`, `habilitacion_retirada`, `habilitacion_vencida`, `revision_vencida`, `tipo_inactivo` o `integridad_incompatible`.

No recupera una activación anterior cuando la última queda bloqueada. Una revisión nueva invalida la activación ligada a la anterior; renovar una habilitación no revive una revisión retirada. Los vencimientos se detectan al consultar, sin depender de un worker.

**Siempre devuelve `execution_authorized=false` y `requires_simulation_binding=true`, incluso si `activation_current=true`.** No reserva vigencia, no autoriza una intervención y no modifica prácticas o turnos existentes. El orquestador habitual sigue sin cambios. La siguiente fase de motor deberá capturar la activación exacta en nuevas prácticas y revalidar sus dependencias dentro de cada mutación, no reutilizar este diagnóstico como permiso.

## Migración y límites de seguridad

`2026_10_04_000001_registrar_activaciones_reglas.php` añade el campo de cobertura a las revisiones y crea `activaciones_reglas_audiencia` y `revocaciones_activaciones_reglas`. Es aditiva: no cambia expedientes, usuarios o prácticas ni crea registros de aprobación. PostgreSQL conserva las validaciones esenciales aun con inserciones SQL directas.

El rollback se niega si hay activaciones, retiros, revisiones con cobertura o cualquier contrato ejecutable sellado, aunque aún no tenga revisión. No se borran evidencias para permitirlo. Un propietario de PostgreSQL con permisos DDL puede omitir triggers; no se presenta este mecanismo como barrera frente al propietario de la base ni como firma criptográfica del revisor.

Tras verificar el destino habitual y sus migraciones previas, instalar únicamente esta migración desde `backend`:

```powershell
php artisan migrate --path=database/migrations/2026_10_04_000001_registrar_activaciones_reglas.php
```

Nunca usar `migrate:fresh` en la base habitual. Las pruebas refrescan exclusivamente `jurissim_pruebas`, con guardia de base configurada y efectiva, conexión PostgreSQL, ausencia de `DB_URL` y de configuración cacheada. Las credenciales y `APP_KEY` de pruebas se suministran al proceso, sin guardarlas ni imprimirlas. Los escenarios usan exclusivamente datos sintéticos y bloquean solicitudes HTTP externas.

## Archivos de esta fase

- Modelos `ActivacionReglasAudiencia` y `RevocacionActivacionReglas`.
- Servicios `ContratoEjecucionReglas`, `GestionarActivacionReglas` y `ConsultarActivacionReglas`.
- Extensiones compatibles del sellado y revisión internos; sin cambios a controladores o rutas.
- Migración del 4 de octubre y `ActivacionReglasAudienciaTest`.
- Pruebas previas de versiones/revisiones ajustadas para revertir y reinstalar las dependencias en orden, solo en la base de pruebas.
- Este documento y enlace desde la preparación.

Verificación del 4 de octubre de 2026: suite completa de la rama, 242 pruebas y 1.317 aserciones aprobadas; en la carpeta habitual, versiones, revisiones, preparación, nueva activación, API de gobierno y orquestador existente, 171 pruebas y 824 aserciones aprobadas. Pint correcto. Archivos propios trasladados conservando cambios ajenos. La migración se aplicó únicamente por su ruta en PostgreSQL `jurissim`, tras comprobar la base efectiva y la ausencia de configuración cacheada/`DB_URL`: se conservaron los conteos de usuarios, expedientes, simulaciones, intervenciones, versiones, revisiones y habilitaciones. Las dos tablas nuevas están vacías; no hubo aprobaciones, habilitaciones ni activaciones jurídicas reales, llamadas a proveedores o publicación remota.

Las pruebas de aislamiento usan una segunda conexión; las de precondiciones son secuenciales, no una prueba de carga concurrente. No se afirma haber realizado estrés concurrente. La primera ejecución detectó defectos de sintaxis/alias en la migración, corregidos antes de las suites finales y antes de aplicarla en la base habitual.
