# Preparación administrativa de instrucciones

Estado: consulta del borrador y sellado explícito desde administración implementados. Continúa la fase 20, en la rama existente `funcionalidad/versiones-reglas-audiencia`, conservando las dependencias locales aún no publicadas. No hay nueva migración, proveedor, permiso jurídico real, activación pública ni vinculación con el orquestador.

## Flujo de trabajo

En `/configuracion/reglas`, **Preparar instrucciones** abre la elección de audiencia. La consulta es explícita: no conserva una versión ni escribe instrucciones en PostgreSQL. Se muestran las propuestas actuales, sin copiar automáticamente descripciones ni instrucciones de referencia.

El editor presenta una propuesta por paso, con etapa, orden, rol, descripción y referencia; permite avanzar, retroceder e ir a la primera instrucción pendiente. Los textos permanecen en memoria al recorrer los pasos. Se comprueban campos vacíos y el límite de 5.000 caracteres Unicode; la confirmación queda deshabilitada si hay pendientes o textos excesivos. No se aplica un límite HTML que reduzca a la mitad la capacidad al escribir caracteres fuera del BMP.

El motivo y la confirmación final preceden a **Sellar copia con instrucciones**. Cambiar el motivo o cualquier instrucción reinicia la confirmación. La copia resultante se abre para lectura y revisión independiente; no puede editarse ni borrarse. Administrar y sellar no concede autoridad jurídica ni permite aprobar autoría propia.

El borrador del formulario no se guarda en localStorage, archivos, bases o proveedores. Cerrar o recargar lo descarta y la interfaz lo advierte. Un conflicto o una respuesta incierta conserva el texto en el formulario bloqueado, sin reenvío automático ni renovación silenciosa de la huella; se requiere cerrar, consultar y comprobar de nuevo. No se promete recuperación del texto después de cerrar.

## Consulta autorizada y precondición

`GET /api/v1/reglas-audiencia/tipos/{tipo}/preparacion-instrucciones` requiere sesión y administración reconsultada, antes de buscar el tipo. Devuelve la estructura visible, las propuestas, `estructura_valida`, `huella_borrador` y `execution_authorized=false`. La respuesta es privada, no almacenada y limitada a borradores de hasta 2.000.000 bytes. Tiene el mismo límite de 10 solicitudes por minuto que las mutaciones de gobierno.

`CapturarBorradorReglas` comparte con el sellador una sola consulta PostgreSQL: captura todos los componentes del catálogo y la última versión del tipo bajo la misma vista MVCC. La huella SHA-256 usa el JSONB de **contenido completo + ID de última versión**, incluyendo los campos de referencia del catálogo que no se muestran como instrucciones del editor. No es una firma profesional, una prueba de lectura ni un permiso de ejecución.

El endpoint existente `POST /api/v1/reglas-audiencia/tipos/{tipo}/versiones` mantiene el sellado histórico solo con motivo. Si está presente `instrucciones`, incluso con valor nulo, exige una lista no vacía, `confirmacion_instrucciones=true` booleano exacto y `huella_borrador_esperada` válida. Si no hay instrucciones, los otros campos de cobertura están prohibidos.

El sellador bloquea el tipo, vuelve a capturar el contenido y comprueba la huella antes de crear la versión. Una propuesta, etapa, transición, referencia o última versión diferente invalida la preparación. Un segundo envío de la misma copia queda rechazado después del primer sellado, aunque el catálogo no haya cambiado. Una consulta nueva permite crear otra versión explícitamente. El bloqueo serializa sellados por tipo; no se afirma que todos los editores de catálogo estén bloqueados: se conserva la copia coherente observada por la consulta, no una garantía de que nadie edite el catálogo después de ella.

El método interno conserva sus llamadas anteriores; incorpora una sexta precondición opcional. La API con instrucciones siempre la exige. Se reutilizan el perfil técnico acíclico, validación de autoría, correspondencia exacta, IDs enteros estrictos y campos permitidos de `ContratoEjecucionReglas`. Las versiones y revisiones previas no se modifican.

## Texto, autoría y revisión

La excepción de `TrimStrings` se limita al nombre de campo `instrucciones.*.texto`; el resto de la normalización de Laravel permanece. No se recortan espacios ni saltos antes del sellado. Vacíos, IDs coercionados, duplicados, instrucciones ajenas y campos de rol/etapa adicionales se rechazan. El motivo conserva su validación y normalización. El hash definitivo sigue calculado por el trigger PostgreSQL sobre la copia sellada completa.

Autor de la versión, rol, etapa, orden y autores de propuestas proceden del servidor; el cliente solo aporta motivo, textos, confirmación y huella esperada. Una estructura incompatible puede consultarse como tal, pero no obtiene un editor ni puede sellarse con instrucciones.

El flujo de revisión de la fase 20 exige después sus dos confirmaciones, fuentes, vigencia, independencia y acreditación. Ningún paso de preparación crea una habilitación, revisión, activación o práctica. Las propuestas sintéticas usadas en pruebas no representan normas o procedimientos bolivianos reales. La validación normativa continúa siendo profesional y humana.

## Diseño y verificación

Las guías de interfaz orientaron la reutilización de Modal, CampoRegla, estados y tokens papel/tinta; pasos por propuesta en lugar de un formulario con miles de campos, acciones junto al texto, foco en la propuesta al navegar y avisos de recuperación. No se añadieron bibliotecas, navegación general ni un nuevo sistema visual. El hook de registro se comparte desde `model/use-registro-reglas.ts`, conservando cerrojo, errores y ausencia de reintentos.

Las pruebas destructivas usan exclusivamente `jurissim_pruebas`, comprobada tanto en configuración como en la conexión efectiva, sin DB_URL ni configuración cacheada. Las pruebas HTTP externas se bloquean. Los escenarios de navegador interceptan la API con datos sintéticos y no escriben en la base habitual.

Verificación: 12 pruebas nuevas de API y 92 aserciones; 23 casos de modelo y 25 escenarios de navegador aprobados; TypeScript, lint y build comprobados. Las capturas de preparación de escritorio y móvil se inspeccionaron. Incluye correspondencia, confirmaciones estrictas, texto exacto, catálogo modificado, sellado intermedio, reenvío, permiso retirado, incompatibilidad, recuperación de consulta, pendientes, navegación y conservación de texto, doble envío, anchos 320/390/768/1365, texto al 200 % y movimiento reducido. No hay auditoría completa de accesibilidad, lector de pantalla, estrés concurrente ni medición de Core Web Vitals. Continúa el aviso de Vite de chunk principal superior a 500 kB.

El 4 de octubre de 2026 pasaron la suite completa de la rama (264 pruebas, 1.513 aserciones) y, en la carpeta habitual, las pruebas de reglas y orquestación existentes (193 pruebas, 1.020 aserciones), además de los 23 casos de modelo, TypeScript estricto, lint y build. Pint correcto para los archivos PHP de esta fase. El traslado conserva las rutas del orquestador y los cambios ajenos del checkout habitual. No se modifican credenciales ni la base habitual. El commit y la publicación se mantienen separados: no hay push, PR ni merge en esta fase.

Los 25 escenarios de navegador también pasaron en la carpeta habitual, con inspección de sus capturas de preparación. Los servidores aislados de pruebas no son un despliegue ni reemplazan los servicios habituales.

No se requiere instalación de dependencias ni ejecución de migraciones. Las capturas de esta fase están en `artifacts/preparacion-instrucciones`; para ejecutarlas se conservan las variables de entorno descritas en la fase 20.

## Archivos de esta fase

- Backend: `app/Services/Simulaciones/CapturarBorradorReglas.php`, `SellarVersionReglasAudiencia.php`, `app/Http/Controllers/Api/V1/GobiernoReglasAudienciaController.php`, `app/Http/Resources/Api/V1/BorradorInstruccionesResource.php`, `routes/api.php` y `bootstrap/app.php`.
- Frontend: `features/audiencias/components/PrepararInstruccionesForm.tsx`, `ReglasForms.tsx`, `model/use-registro-reglas.ts`, `model/reglas.ts`, `api/reglas-api.ts`, `pages/admin/ReglasAudienciaPage.tsx` y `reglas-audiencia.css`, todos bajo `frontend/src`.
- Pruebas: `backend/tests/Feature/Api/PrepararInstruccionesReglasTest.php`, `frontend/tests/reglas.test.mjs` y `reglas-ui.mjs`.
- Documentación: este documento y enlace desde la fase 20; capturas sintéticas de preparación y regresión en `artifacts/preparacion-instrucciones`.

## Siguiente paso

Exponer la activación administrativa y su retiro como decisiones separadas, sobre una revisión jurídica vigente y exacta. Después vincular nuevas prácticas a esa activación y revalidar sus dependencias en cada mutación del orquestador. La preparación y aprobación no habilitan por sí solas la ejecución de turnos.
