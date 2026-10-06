# Fase 31 — Activación interna de copias revisadas

## Alcance

Se permite elegir administrativamente una copia inmutable de rúbrica de defensa cuya revisión exacta esté aprobada y vigente. La comprobación privada de preparación de evaluación identifica esa copia. No se añade generación, contexto de evaluación persistido, trabajo de cola, frontend, endpoints nuevos ni llamadas a proveedores. No se cargan rúbricas jurídicas ni acreditaciones reales.

Una activación técnica no certifica la calidad jurídica: depende de la evidencia profesional declarada y comprobada fuera de esta automatización, según fase 30. Tampoco sustituye el consentimiento para un futuro envío del contexto a un proveedor.

## Historial y selección

- `activaciones_rubricas`: conserva tipo, rúbrica, copia, revisión, habilitación, responsable, huella, perfil técnico, motivo, confirmación y activación previa esperada.
- `retiros_activaciones_rubricas`: retiro administrativo irreversible, con motivo y responsable; no edita ni borra la activación original.

La base y los modelos impiden UPDATE/DELETE; PostgreSQL también impide TRUNCATE. Un rollback ordinario solo elimina las dos tablas si están vacías. Las migraciones anteriores se revierten después de retirar las posteriores vacías, nunca eliminando evidencia para posibilitar un rollback.

La elección es explícita, única por tipo de audiencia en el perfil `mvp-evaluacion-defensa-v1`; únicamente abogado defensor. No se elige automáticamente la rúbrica más reciente ni una rúbrica genérica. Se usa la última activación de ese tipo aunque esté retirada, vencida o invalidada: nunca se vuelve a una activación anterior.

Activar exige un administrador vigente, booleano nativo true, huella exacta de la copia, identificador nativo de la última revisión aprobada y activación previa esperada (nula solo si nunca hubo una). Versión, revisión, habilitación, rúbrica y tipo deben pertenecer al mismo ámbito. El tipo debe estar activo. La revisión profesional y su habilitación deben conservar vigencia e independencia. Cada revisión solo admite una activación: después de retirar o sustituirla, reactivar necesita una nueva revisión profesional, no repetir la anterior.

PostgreSQL comprueba estos requisitos también ante INSERT directo. Todos los comandos de esta fase bloquean primero el tipo con NO KEY UPDATE y después la rúbrica con FOR UPDATE, compatible con el bloqueo de revisión y retiros de fase 30. Se exige read committed; la fecha se fija con el reloj de PostgreSQL después de obtener los bloqueos. Una lectura previa obsoleta o envío repetido no crea otra elección. No hay reintentos automáticos.

## Vigencia y catálogo

Una revisión nueva, incluso aprobada, invalida la activación anterior hasta que un administrador seleccione expresamente esa revisión. Retirar la revisión o su habilitación, vencer sus plazos, desactivar el tipo o retirar la activación impide usar la copia. Los estados internos son `sin_activacion`, `sustituida`, `retirada`, `tipo_inactivo`, `integridad_incompatible`, `revision_sustituida`, `revision_no_vigente` y `vigente`.

La rúbrica y criterios usados se leen de la copia sellada, no del catálogo editable. Cambiar nombre, rol, tipo, versión, criterios o pesos del catálogo no cambia una activación; preparar otra copia requiere revisión y elección nueva. No se actualiza `rubricas.activo`, para no habilitar indirectamente el evaluador antiguo. Si el contenido sellado aprobado contiene un error, se retira la revisión o activación y se prepara otra copia; no se edita el historial.

## Consulta privada existente

`GET /api/v1/practicas-regladas/{practica}/preparacion-evaluacion` conserva autenticación, ambas propiedades (práctica y expediente), rechazo de campos adicionales y `Cache-Control: no-store, private`. No añade POST ni permite que el navegador elija una rúbrica.

Después de comprobar cierre y contexto reglado vigente:

- Sin historial de activación, conserva el diagnóstico anterior de candidatas del catálogo, `rubric_review_status=no_verificable` y ningún permiso de generación. Esa candidata no es una copia autorizada ni una alternativa ejecutable.
- Con activación vigente, informa `rubric_review_status=aprobado`, `rubric_activation_status=vigente` y los identificadores de copia, revisión y activación, además de versión de catálogo sellada y cantidad de criterios. Cuenta caracteres de los criterios sellados, no de los editables.
- Con historial no vigente, informa su estado y no ofrece rúbrica ni fallback al catálogo. La preparación técnica se bloquea.

La consulta interna bloquea tipo y rúbrica durante la lectura. La preparación pública conserva su transacción y bloqueos de acta. No reserva vigencia después de terminar ni congela contexto; la ejecución futura deberá verificar de nuevo y persistirlo en una operación autorizada. No se exponen huellas, criterios, referencias de acreditación, responsables, textos del acta o extractos.

Incluso con preparación técnica completa y copia activada, se mantiene `read_only=true`, `execution_authorized=false`, `allowed_actions=[]`. Persisten los bloqueos `contexto_inmutable_no_integrado` y `ejecucion_no_integrada`. El evaluador y la cola anteriores siguen rechazando prácticas regladas.

## Archivos y operación

- Migración: backend/database/migrations/2026_10_06_000002_activar_copias_rubricas.php.
- Modelos: ActivacionRubrica y RetiroActivacionRubrica.
- Comandos internos: GestionarActivacionRubricas (activar y retirar); no son endpoints públicos. El actor se recibe de código de servidor autorizado, nunca del cuerpo del navegador.
- Consulta interna: ConsultarRubricaActivada; solo debe utilizarse después de autorizar el recurso que necesita esa información.
- Integración de lectura: ConsultarPreparacionEvaluacionReglada, sin modificar rutas ni controladores.
- Pruebas nuevas: ActivacionRubricasTest. Se actualiza el orden de rollback en pruebas de versiones y revisiones anteriores.

Aplicar únicamente esta migración desde backend: `php artisan migrate --path=database/migrations/2026_10_06_000002_activar_copias_rubricas.php`. No ejecutar migrate:fresh ni db:wipe contra `jurissim`. Las pruebas destructivas requieren conexión configurada y efectiva a PostgreSQL `jurissim_pruebas`, sin DB_URL ni caché de configuración. Usan evidencia y prácticas sintéticas, pasarela simulada, HTTP inesperado bloqueado y reloj real para vencimientos; no deshabilitan triggers ni usan credenciales de proveedor.

## Verificación local (6 de octubre de 2026)

- Checkout de trabajo: 45 pruebas y 674 aserciones correctas, incluyendo activación, revisión de rúbricas, preparación de evaluación y rollback de migraciones anteriores en orden inverso.
- Carpeta habitual: 50 pruebas y 709 aserciones correctas, incluyendo los circuitos nuevos de rúbricas/preparación y el evaluador anterior. Ambas ejecuciones utilizaron exclusivamente `jurissim_pruebas`; la pasarela y la cola de los casos de integración fueron simuladas.
- Pint aprobado en los diez archivos PHP de la fase; comprobación de espacios aprobada. Los trece archivos propios se copiaron a la carpeta habitual preservando el resto de cambios.
- Se aplicó únicamente esta migración en `jurissim` tras comprobar PostgreSQL local configurado y efectivo, sin DB_URL ni caché de configuración y con los requisitos de fase 30 presentes.
- Los conteos de las 24 tablas anteriores comprobadas no cambiaron; las dos tablas nuevas quedaron vacías. Se verificaron seis triggers habilitados y nueve claves foráneas en las tablas nuevas.
- No se activó una rúbrica real, no se concedió acreditación ni se crearon evaluaciones. Sin llamadas a proveedores ni publicación en GitHub.

## Siguiente paso

Preparar y conservar el contexto inmutable de evaluación de la práctica cerrada con la activación exacta vigente. Definir la política de fuentes históricas y el consentimiento específico antes de conectar generación, persistencia de resultados y su interfaz. No cargar contenido normativo ni conceder competencia profesional automáticamente.
