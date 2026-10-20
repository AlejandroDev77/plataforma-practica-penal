# Fase 30 — Versiones y revisión interna de rúbricas

La activación técnica explícita y su conexión a la consulta de preparación se implementan en [fase 31](31-activacion-interna-rubricas.md). Esta fase 30 conserva el historial de revisión sin activar por sí sola una copia ni permitir puntajes.

## Alcance de datos

Se implementa el historial técnico para preparar copias inmutables de una rúbrica de defensa y registrar su habilitación profesional, revisión y retiro. Solo PostgreSQL local, modelos y comandos internos de Laravel. No hay endpoints nuevos, frontend, activación de rúbricas, evaluador nuevo ni llamadas a servicios de IA. No se precarga ninguna rúbrica jurídica ni se acredita a ninguna persona real.

La preparación y la revisión son actos distintos. Preparar una copia no acredita al preparador ni concede competencia al revisor. Una declaración humana de autoría y las referencias documentales guardadas son evidencia declarada, no una certificación automática de títulos, autoría real o vigencia de normas. La institución debe comprobarlas fuera de esta automatización y el revisor debe confirmar que examinó la autoría y la copia exacta.

## Persistencia

| Tabla | Propósito |
| --- | --- |
| versiones_rubricas_evaluacion | Copia de rúbrica/criterios, preparador, autores declarados, número y huella |
| autores_version_rubrica | Referencias normalizadas inmutables a los usuarios declarados; FK preserva la referencia incluso ante escrituras concurrentes |
| habilitaciones_revision_rubricas | Evidencia de acreditación verificada por un administrador, limitada a una copia concreta y a un revisor |
| retiros_habilitacion_rubricas | Retiro irreversible de la habilitación, conservando la evidencia |
| revisiones_rubricas_evaluacion | Decisión independiente, copia examinada, fuentes, vigencia y revisión previa esperada |
| retiros_revision_rubricas | Retiro de una decisión, sin recuperar aprobaciones anteriores |

Campos y modelos propios en español. Relaciones de historial con eliminación restringida, sin cascadas destructivas. PostgreSQL y los modelos impiden UPDATE/DELETE; PostgreSQL también rechaza TRUNCATE. Un rollback ordinario solo puede retirar estas tablas si todas están vacías. No desactivar estos controles para editar historial.

## Sellado y contratos

Un administrador vigente declara de uno a diez autores por identificadores nativos distintos y confirma expresamente que la declaración es completa. Se distingue `id_usuario_preparador` de los autores originales declarados, porque el catálogo anterior no conserva esa autoría. La relación normalizada impide borrar o cambiar el identificador de un autor referenciado. El preparador tampoco puede eliminarse por su FK.

La base bloquea la rúbrica y reconstruye el contenido desde rúbrica y criterios en una sentencia MVCC. No confía en contenido, número, hash, tipo o fecha enviados por quien inserta. El número se serializa por rúbrica. La huella SHA-256 se calcula sobre la representación JSONB de PostgreSQL, incluyendo contenido, autores y preparador; no depende de la serialización JSON de PHP. Leer/revisar verifica esa huella. Cambiar el catálogo no cambia una copia sellada y exige preparar otra copia para revisar el cambio.

El circuito nuevo admite únicamente rúbricas con tipo de audiencia específico y rol `abogado_defensor`; las rúbricas genéricas siguen existiendo en el circuito anterior, pero no se convierten silenciosamente. Versión positiva, de uno a veinte criterios, orden positivo y único, pesos/máximos positivos y finitos; nombres hasta 255 y descripción de criterio hasta 2.000 caracteres. Los criterios suman como máximo 20.000 caracteres y la copia ocupa como máximo 100.000 bytes JSONB. Se rechazan textos vacíos o constituidos únicamente por espacios, incluidos espacios Unicode. No se agregan criterios, escalas ni valores por defecto.

## Habilitación, revisión y retiro

La habilitación exige un administrador actual distinto del revisor, alcance explícito, referencia y huella de acreditación, confirmación booleana nativa de verificación y vencimiento futuro. No puede haber dos habilitaciones vigentes para el mismo revisor y copia. No hereda permisos de revisión de reglas de audiencia, de otra rúbrica ni de otra copia.

Revisar exige esa habilitación vigente, no retirada, sobre la copia exacta y el mismo revisor. El preparador y cualquiera de los autores declarados quedan excluidos. La decisión conserva la huella revisada y la última revisión esperada: dos solicitudes con la misma lectura previa no agregan dos decisiones. La revisión guarda observación, entre una y veinte referencias con huella, fecha de comprobación no futura y confirmaciones humanas de revisión y autoría. Aprobar necesita vencimiento futuro no posterior al de la habilitación; `requiere_cambios` no puede tener vencimiento.

Cada comando toma primero el bloqueo de la rúbrica; los lectores no reservan permisos ni estados. El reloj de PostgreSQL es autoritativo para inserción y consultas, con fecha jurídica en America/La_Paz. No hay reintentos automáticos ni proveedor involucrado.

Retirar una habilitación o revisión solo corresponde al propio revisor o a un administrador vigente. El retiro conserva el registro original y no se borra ni se revierte. La consulta usa la última decisión de esa copia, aunque esté retirada: nunca vuelve a una aprobación más antigua. Los estados de consulta son sin_version, no_acreditada, integridad_incompatible, aprobado, requiere_cambios, revision_retirada, habilitacion_retirada, revision_vencida y habilitacion_vencida. La consulta mantiene `read_only=true`, `execution_authorized=false` y `allowed_actions=[]`.

La aprobación registrada no activa la rúbrica, no cambia su campo `activo`, no concede permisos de reglas de audiencia, no crea evaluaciones ni habilita la ruta anterior. La comprobación HTTP de fase 29 mantiene su contrato y `rubric_review_status=no_verificable` hasta conectar explícitamente este historial; no lee estas tablas todavía.

## Archivos y operación

- Migración: backend/database/migrations/2026_10_06_000001_versionar_revision_rubricas.php.
- Modelos: RegistroRubricaInmutable, VersionRubricaEvaluacion, AutorVersionRubrica, HabilitacionRevisionRubrica, RevisionRubricaEvaluacion, RetiroHabilitacionRubrica y RetiroRevisionRubrica.
- Comandos internos: backend/app/Services/Evaluaciones/GestionarRevisionRubricas.php (sellar, habilitar, revisar, retirarHabilitacion, retirarRevision y estado).
- Pruebas: backend/tests/Feature/RevisionRubricasTest.php.
- Este documento y enlace desde la fase 29.

El servicio no es un endpoint público; cuando se conecte a una API, obtener el actor de la sesión y aplicar autorización de lectura/escritura. No pasar un usuario responsable suministrado por el navegador. Los errores esperados de integridad/autorización se traducen sin SQL, parámetros ni detalles privados.

Para desplegar exclusivamente este cambio, desde backend: `php artisan migrate --path=database/migrations/2026_10_06_000001_versionar_revision_rubricas.php`. No ejecutar migrate:fresh ni db:wipe en la base habitual. Las pruebas usan únicamente `jurissim_pruebas`, con guardia de conexión efectiva/configurada, sin DB_URL ni caché de configuración y sin HTTP inesperado. No cambian timestamps deshabilitando triggers para probar vencimientos: esperan el reloj real con registros sintéticos de vida corta. La prueba de bloqueo usa otra conexión PDO, comprueba la base efectiva y aplica un timeout de 75 ms.

## Verificación y despliegue local (6 de octubre de 2026)

- Checkout de trabajo: `RevisionRubricasTest`, 18 pruebas y 157 aserciones correctas. Incluye vencimientos con reloj real, bloqueo de sellos, integridad JSON, autoría referenciada, borrado concurrente y preservación del historial.
- Carpeta habitual: regresión conjunta de `RevisionRubricasTest`, `PreparacionEvaluacionRegladaTest`, `EvaluacionSimulacionTest` y `EvaluarSimulacionJobTest`, 37 pruebas y 582 aserciones correctas, exclusivamente en `jurissim_pruebas`.
- Pint verificó los diez archivos PHP de la fase. Las copias de esta fase en ambos checkouts coinciden y `git diff --check` no detectó errores de espacios.
- Se aplicó únicamente la migración de esta fase en `jurissim`, tras comprobar PostgreSQL local efectivo, ausencia de DB_URL y de caché de configuración, y funciones requeridas de fases anteriores.
- Los conteos de las dieciocho tablas anteriores comprobadas se conservaron. Las seis tablas nuevas quedaron vacías: no se acreditó a una persona real, no se preparó ni aprobó una rúbrica y no se crearon evaluaciones.
- No hubo llamadas a proveedores ni publicación en GitHub. La preparación HTTP de fase 29 sigue sin habilitar ejecución.

## Siguiente paso

Conectar la elección explícita de una copia revisada y su activación técnica al contexto inmutable de evaluación, antes de habilitar generación y pantalla de resultados. Revisar la evidencia profesional real con la institución; no crear usuarios acreditados ficticios ni convertir el fixture en una rúbrica vigente.
