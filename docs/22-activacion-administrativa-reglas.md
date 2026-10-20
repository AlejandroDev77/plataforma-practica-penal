# Activación administrativa desde el panel

Continuación de la fase 21. Se expone la activación interna de la fase 19 mediante API y un panel en el detalle administrativo de una versión. No se vinculan simulaciones ni se modifica el motor, la secuencia de turnos o las reglas jurídicas bolivianas.

Se conserva la rama de trabajo con sus dependencias locales. Se comprobó el estado y se actualizó develop con pull --ff-only; no hay publicación, PR, merge a develop/main ni cambios de protecciones.

## Acceso y operaciones

Todas las rutas usan autenticación Sanctum y el middleware privado no-store de reglas. Solo administración de plataforma, reconsultada en el servidor, puede consultar o registrar una activación. Una habilitación jurídica por sí sola no da ese acceso. El control administrativo se realiza antes de consultar IDs, también cuando no existen.

Rutas bajo /api/v1/reglas-audiencia:

- GET /versiones/{version}/preparacion-activacion: condiciones orientativas de la copia, bloqueos, precondición exacta y diagnóstico de la última activación del tipo.
- GET /tipos/{tipo}/activaciones: historial paginado, máximo 50 registros por página, orden descendente por ID; no indica vigencia de registros históricos.
- POST /versiones/{version}/activaciones: motivo, confirmacion_humana estrictamente true, huella_version_esperada, id_revision_esperada e id_activacion_previa presente (null en la primera).
- POST /activaciones/{activacion}/revocaciones: motivo y confirmacion_humana estrictamente true. El retiro es idempotente y conserva su motivo original.

Las escrituras y la preparación tienen límite de diez solicitudes por minuto. Responsable, fecha, perfil, contenido y permisos nunca se toman del cliente. Las respuestas proyectan campos permitidos; no incluyen acreditación, secretos ni instrucciones completas en el historial. DomainException y conflictos SQL siguen pasando por la respuesta segura del controlador, sin SQL o bindings.

## Preparación no equivale a autorización

PrepararActivacionAdministrativa reutiliza preparación estructural, diagnóstico jurídico y contrato de instrucciones. Añade tipo actualmente activo, cobertura de instrucciones por la revisión exacta y ausencia de una activación previa que ya utilizó esa revisión. Las copias históricas sin instrucciones no se presentan como activables. La consulta no crea, modifica ni reserva registros.

La preparación combina varias lecturas orientativas; no promete una fotografía atómica entre condiciones e historial ni garantiza vigencia futura. El comando existente GestionarActivacionReglas vuelve a comprobar roles, revisión, habilitación, huella y activación previa bajo bloqueo; los triggers PostgreSQL refuerzan las condiciones esenciales. Un cambio entre lectura y confirmación rechaza la solicitud. No se reintenta automáticamente.

La activación actual procede del diagnóstico existente: solo examina el registro más reciente del tipo, nunca restaura una activación anterior. Retiro, sustitución de revisión, vencimiento o pérdida de habilitación mantienen su historial. Una revisión no puede reutilizarse para reactivar una copia retirada.

Todas las respuestas conservan execution_authorized=false y requires_simulation_binding=true. La consulta agrega read_only=true. Puede haber activation_current=true sin permiso para intervenir: una activación administrativa no habilita turnos ni cambia prácticas existentes.

## Interfaz y límites

El panel aparece únicamente en /configuracion/reglas/versiones/{id}, no en el espacio de revisión jurídica. Muestra el estado del tipo, distingue esta copia de otra versión y ofrece preparar activación solamente cuando la consulta completa es compatible. Los bloqueos se traducen a indicaciones humanas; no se presenta JSON ni códigos internos como operación principal.

La confirmación conserva la huella, revisión y activación previa consultadas. Motivo obligatorio, confirmación restablecida al editar, cerrojo contra doble envío y diálogo no cerrable durante una escritura. Un conflicto o una respuesta incierta conserva la entrada pero bloquea reenvíos; debe cerrarse y consultarse de nuevo. El foco vuelve a una región estable porque el botón anterior puede desaparecer o quedar ocupado.

El retiro se refiere al ID seleccionado, no implícitamente a la última activación. El historial es desplegable, con motivo, responsable, revisión, anterior y retiro; su presentación no atribuye vigencia a un registro sin retiro.

Las guías frontend-design, frontend-ui-standards y ui-ux orientaron la separación entre revisión, activación y ejecución, la reutilización de Modal/CampoRegla/estados/tokens papel-tinta y la recuperación de foco. Sin bibliotecas, navegación general o estilos alternativos nuevos. El estado de la sesión permanece en memoria y no se persiste la evidencia privada.

## Verificación y alcance

Pruebas destructivas exclusivamente en PostgreSQL jurissim_pruebas, con guardia sobre base configurada y efectiva, sin DB_URL ni configuración cacheada. Credenciales solo en el entorno del proceso, nunca en archivos o salidas. Escenarios sintéticos, HTTP externo bloqueado; el navegador intercepta toda la API sin cuentas reales.

La suite completa de la rama pasó con 272 pruebas y 1.628 aserciones, incluidas ocho pruebas nuevas de API (115 aserciones). Los 29 casos de modelo frontend pasaron. TypeScript estricto, build, lint y Pint correctos. Continúa el aviso de Vite del chunk principal mayor a 500 kB; no se declara una mejora de rendimiento.

Los 32 escenarios de navegador pasaron tanto en la rama como en la carpeta habitual, con toda la API interceptada. Incluyen confirmación y motivo, edición que restablece confirmación, doble envío, diálogo ocupado, conflicto sin reenvío, retiro exacto, recuperación explícita, persona revisora sin consultas administrativas y copia histórica bloqueada. Se verificó el diálogo a 320/390/768/1365 píxeles, panel y diálogo móvil con texto al 200 %, página de escritorio ampliada, teclado, foco y movimiento reducido. Se inspeccionaron las capturas de activación, retiro y confirmación móvil. Se corrigieron un desborde de título ampliado y la recuperación de foco sobre un botón ocupado; la presentación continúa usando los tokens existentes.

En la carpeta habitual pasaron 201 pruebas de reglas y orquestación existentes (1.135 aserciones), los 29 casos de modelo, los 32 escenarios de navegador, TypeScript, build, lint y Pint. Se trasladaron únicamente archivos propios; el parche de rutas conserva el orquestador y el cambio de package.json conserva los scripts ajenos. test:reglas incluye ambos archivos de pruebas de modelo. Los servidores aislados de verificación no sustituyen los servicios habituales y se detienen al finalizar.

No hay auditoría integral WCAG, lector de pantalla, carga concurrente ni Core Web Vitals. No se crean habilitaciones, revisiones o activaciones reales, ni se llama a proveedores. Sin migraciones, credenciales nuevas, Docker o escrituras a la base habitual.

## Archivos

- Backend: PrepararActivacionAdministrativa, ActivacionReglasAudienciaResource, extensión de GobiernoReglasAudienciaController y routes/api.php.
- Frontend: ActivacionReglasPanel y su modificador CSS de reflujo, model/activacion-reglas.ts, extensión de reglas-api.ts y VersionReglasPage; script test:reglas en package.json.
- Pruebas: ActivacionAdministrativaReglasTest, activacion-reglas.test.mjs y regresión reglas-ui.mjs.
- Documentación: este archivo y enlace desde la fase 21. Capturas sintéticas en artifacts/activacion-reglas.

## Siguiente fase

Vincular nuevas prácticas a la activación exacta y revalidar sus dependencias dentro de cada mutación del orquestador. La aprobación y activación no sustituyen una revisión profesional del procedimiento boliviano. No se concedió autoridad jurídica a una cuenta por criterio automático.

El primer hito interno de esa vinculación se documenta en [23-vinculacion-interna-practicas.md](23-vinculacion-interna-practicas.md): creación nueva, copia exacta y diagnóstico privado. Los comandos del motor y la creación pública todavía no están integrados.
