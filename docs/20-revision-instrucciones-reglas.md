# Revisión de instrucciones selladas

Estado: lectura y revisión explícita implementadas sobre el gobierno de reglas existente. Continúa `19-activacion-interna-reglas.md`. Esta fase no crea un editor de instrucciones, endpoints de activación ni vínculos con prácticas. No se conceden habilitaciones jurídicas reales y no se consulta a proveedores.

Se conserva `funcionalidad/versiones-reglas-audiencia`, cuyas dependencias aún son commits locales. No se publica ni integra mediante un merge local.

## Lectura completa, sin proyección parcial

El detalle autorizado de una versión devuelve `revision_instrucciones` con estado `ausente`, `disponible` o `incompatible`, y `requiere_confirmacion`. Solo el contrato exacto que supera `ContratoEjecucionReglas` se incorpora como `contenido.contrato_ejecucion`; el listado no transporta las instrucciones. Un contrato desconocido, incompleto o inválido no se recorta para aparentar una copia revisable.

La interfaz muestra cada texto íntegro junto a su propuesta, etapa, orden y rol. Conserva espacios y saltos de línea, trata el contenido como texto y adapta palabras largas sin recortar. Reutiliza la paleta, tipografía, formularios y estados del panel jurídico. La acción de revisión está al final de la lectura, no antes del contenido.

La validación del navegador exige el perfil reconocido, IDs enteros seguros, correspondencia exacta y orden entre propuestas e instrucciones, todas las etapas presentes y la huella de precondición. No reemplaza la validación del servidor. Una copia incompatible bloquea el formulario, explica el motivo y permite volver a consultar. Una copia histórica sin contrato sigue siendo revisable, pero no adquiere cobertura retroactiva.

## Confirmación y cobertura

El endpoint existente `POST /api/v1/reglas-audiencia/versiones/{version}/revisiones` mantiene sus fuentes, vigencia, confirmación humana, independencia, acreditación y precondiciones. Cuando la copia persistida contiene contrato exige además:

- `confirmacion_instrucciones`: booleano exacto `true`.
- `huella_ejecucion_revisada`: SHA-256 de la copia completa, exactamente igual a su huella sellada.

El navegador deriva la huella de la copia consultada; no hay un campo para introducirla ni para enviar instrucciones editadas. El servidor decide si estos campos son necesarios según el contenido persistido, nunca según una bandera del cliente. En copias históricas los valores de cobertura están prohibidos.

La casilla específica declara haber leído todas las instrucciones y comprobado su correspondencia. Está separada de la confirmación general: ambas son necesarias tanto para aprobar como para solicitar cambios en una copia con contrato. Editar decisión, fechas, fundamento o fuentes reinicia ambas casillas; cambiar la casilla de instrucciones reinicia la general. La interfaz conserva el bloqueo de doble envío y no reintenta automáticamente mutaciones ante conflicto o respuesta incierta.

El historial expone `huella_ejecucion_revisada` y distingue decisiones que incluyen las instrucciones, decisiones sin esa cobertura y discrepancias de huella. Ninguna de estas etiquetas activa turnos. La huella no acredita por sí misma lectura efectiva, competencia profesional o validez normativa: es una declaración explícita registrada sobre una copia concreta, no una firma profesional.

## Permisos y conflictos

La visibilidad se comprueba antes de validar la confirmación HTTP. Autoría propia o desconocida, habilitación ausente, retirada o vencida siguen bloqueadas. Los administradores de plataforma no reciben autoridad jurídica por ese rol. Los cambios de precondición o una copia persistida incompatible producen conflicto y requieren nueva consulta; los campos de formulario incorrectos producen errores de validación. No se devuelven detalles internos de PostgreSQL.

El formulario captura la versión consultada y no permanece disponible al cambiar a otra versión. Las respuestas siguen siendo privadas y sin caché compartida. No se introducen rutas ni permisos nuevos.

## Verificación y operación

No hay nueva migración. Se requiere la migración aditiva de la fase 19, ya aplicada en la carpeta habitual. No ejecutar `migrate:fresh` en `jurissim`.

Las pruebas de API refrescan exclusivamente `jurissim_pruebas`, comprueban la base configurada y efectiva, rechazan `DB_URL` y configuración cacheada y bloquean HTTP externo. Los datos son sintéticos; nunca se usan expedientes o acreditaciones reales.

Verificación del 4 de octubre de 2026 en la rama: 252 pruebas de backend y 1.421 aserciones, 19 escenarios de modelo frontend y 18 escenarios de navegador aprobados; compilación TypeScript, lint, Pint y build comprobados. En la carpeta habitual pasaron las 181 pruebas de reglas y orquestación existentes, con 928 aserciones, los 19 escenarios de modelo y los 18 de navegador, además de TypeScript estricto, lint y build. Las capturas de escritorio y móvil se inspeccionaron visualmente. El navegador intercepta todas las llamadas API: no registra revisiones ni habilitaciones en la base habitual. El build conserva el aviso de un chunk principal superior a 500 kB; no se afirma haber optimizado ese paquete en esta fase.

El script `frontend/tests/reglas-ui.mjs` permite `JURISSIM_UI_URL`, `JURISSIM_PLAYWRIGHT_PATH`, `JURISSIM_BROWSER_PATH` y `JURISSIM_UI_ARTIFACT_DIR` para una ejecución aislada. Cubre lectura exacta, texto no ejecutable, contrato incompatible, confirmaciones independientes, cambios de campos, conflicto sin reenvío, navegación por teclado, foco restaurado, anchos 320/390/768/1365, texto al 200 % y movimiento reducido. No se afirma una auditoría completa de accesibilidad, lector de pantalla, carga concurrente o rendimiento.

## Trabajo siguiente

Preparar desde administración instrucciones explícitas para sellar una nueva copia completa, con validación y autoría, sin reutilizar automáticamente textos del catálogo. Después exponer la activación administrativa como decisión separada. La conexión con el orquestador requiere otra fase: fijar la activación exacta al crear la práctica y revalidar sus dependencias al mutar turnos. Sigue vigente `execution_authorized=false`; no se habilita ejecución por haber aprobado una revisión.
