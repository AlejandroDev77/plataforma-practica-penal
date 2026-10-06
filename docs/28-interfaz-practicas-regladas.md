# Fase 28 — Interfaz de prácticas regladas

La comprobación privada previa a evaluación y la separación del evaluador anterior se documentan en [fase 29](29-preparacion-evaluacion-reglada.md); no implican generación de puntajes ni aprobación de rúbricas.

## Alcance y decisión de interfaz

Conecta la administración con los comandos privados de fase 27: creación con una activación revisada explícita, inicio, selección de fuentes, consentimiento de envío, revisión de borradores, defensa, avance y cierre. Mantiene React, TypeScript, TanStack Query, Axios y los componentes existentes; no añade dependencias, versiones, migraciones, Docker ni cambios de proveedor.

Espacio de práctica, no chat genérico: estado/etapa y siguiente acción al comienzo, instrucción exacta de la copia conservada, referencias seleccionadas junto al turno y acta persistida. Conserva la tipografía jurídica, paleta y componentes de JURISSIM. Las guías ui-ux, frontend-design y frontend-ui-standards orientaron las confirmaciones, recuperación, teclado y reflujo. No hay imágenes decorativas, aprobaciones ficticias ni contenido generado de muestra dentro del producto.

## Recorrido y controles

1. El análisis debe estar aprobado. Elegir un tipo consulta la activación más reciente y vuelve a comprobar su vigencia, versión, revisión y huella. No recupera una activación anterior si la última está retirada. Una casilla explícita confirma la copia antes de crear; crear deja la práctica en preparación, no la inicia.
2. La pantalla observa el contrato privado de orquestación. Las posibilidades técnicas no se convierten en permisos: conserva read_only=true, execution_authorized=false y allowed_actions=[]; el servidor revalida cada comando.
3. Iniciar no llama al modelo. Nombres, instrucciones, rol y destinos proceden de la versión conservada, no del catálogo mutable.
4. Juzgado/fiscalía: la búsqueda textual de PostgreSQL no genera contenido. Ninguna fuente se selecciona automáticamente; hasta ocho extractos. La vista previa muestra exactamente el texto limitado a 1.500 caracteres Unicode por fuente, la instrucción, el rol y la etapa.
5. OpenAI necesita una casilla de autorización para un solo borrador. Cambiar fuentes, contexto o proveedor retira esa autorización. Se informa de salida del equipo y posible consumo de saldo. No se envían PDF, análisis ni acta completos.
6. El borrador guardado se recupera al recargar, sin nueva generación. El texto no se edita al confirmar; se muestran fuentes enviadas y las declaradas utilizadas, sin afirmar verificación semántica. La incorporación al acta exige otra confirmación humana explícita y no avanza la etapa.
7. El vencimiento observado de PostgreSQL, el reloj de interfaz y la correspondencia del turno bloquean la confirmación. El servidor sigue siendo autoritativo. Cerrar una lectura no elimina el borrador.
8. Defensa: texto hasta 2.000 unidades UTF-16 en la interfaz y diez fragmentos seleccionados, con las precondiciones exactas del turno. Ante fallo se conserva el texto; no se reenvía automáticamente.
9. Avance solo a destinos de la copia, con selección explícita si hay bifurcación. Cierre mediante diálogo, confirmación independiente, cancelación por Escape y devolución del foco. No hay reapertura ni evaluación automática.

Los estados vacíos, consulta pendiente, falta de revisión, bloqueo de vigencia, contrato incompatible, fallo del proveedor, borrador vencido y resultado incierto tienen mensajes y recuperación explícita. Una petición fallida bloquea el reenvío hasta volver a consultar. Nunca se anuncia éxito sin respuesta válida.

Las consultas privadas se separan por usuario y práctica, sin reintentos ni conservación tras desmontaje. Solo un diagnóstico válido sin_vinculacion permite mostrar la pantalla antigua; un fallo/contrato desconocido no vuelve silenciosamente al circuito anterior. Las prácticas anteriores no se convierten, no obtienen acreditación ni pierden su interfaz existente.

## Consultas privadas de apoyo

Se agregan tres rutas al prefijo /api/v1/practicas-regladas, bajo Sanctum y no-store/private:

| Método | Ruta relativa | Resultado |
| --- | --- | --- |
| GET | /{practica}/acta | ID, expediente e intervenciones/citas almacenadas; sin turnos del catálogo vivo |
| POST | /{practica}/fuentes | Búsqueda textual, consulta 3–500 caracteres y límite 1–10; sin modelo ni escrituras de dominio |
| GET | /{practica}/borradores | Diez resúmenes como máximo, descendentes por ID; next_cursor e id_antes opcional |

Las tres comprueban propietario de la práctica y del expediente. No aceptan campos ajenos, propietario, claves, modelo ni texto de aprobación. El historial no entrega preparación, contenido, análisis, rutas ni huellas; leer el detalle de un borrador requiere otra consulta privada. Las citas del acta reutilizan el recurso existente filtrado a los campos de lectura, preservando compatibilidad. Las rutas antiguas no se modifican.

## Verificación

Contenido exclusivamente sintético, sin datos personales ni significado jurídico. El navegador intercepta TODO tráfico API y bloquea conexiones externas; las pasarelas de backend se simulan y se bloquea HTTP inesperado. Las pruebas destructivas guardan conexión configurada y efectiva exclusivamente a PostgreSQL jurissim_pruebas, sin DB_URL ni configuración cacheada. No deshabilitan las protecciones del propietario. La prueba de vencimiento existente manipula únicamente fechas sintéticas y restaura su trigger inmediatamente.

- Frontend: 13 pruebas nuevas del contrato, junto con 29 anteriores de reglas/activación: 42 pruebas aprobadas.
- Navegador: 16 escenarios nuevos aprobados en la rama y en la carpeta habitual; 32 escenarios de regresión de reglas aprobados en la rama.
- Backend de la rama: 21 pruebas HTTP y 654 aserciones aprobadas; autenticación, propietario, acta, búsqueda, privacidad, paginación y lectura sin generación/confirmación.
- Carpeta habitual: 64 pruebas y 933 aserciones aprobadas del circuito nuevo junto con creación, propuestas y orquestación anteriores. Las 21 pruebas HTTP (654 aserciones) pasaron también con rutas cacheadas mediante un archivo temporal separado por proceso; se retiró al terminar sin cambiar la caché habitual.
- Compilación y análisis estático aprobados en ambas carpetas. Pint aprobado. Vite mantiene el aviso anterior de chunk principal mayor de 500 kB; no se modifica la arquitectura de empaquetado en esta fase.

Reflujo probado a 320, 390, 768 y 1.365 px, texto al 200 %, contenido largo, movimiento reducido y texto con etiquetas HTML mostrado literalmente, sin ejecutarse. Confirmaciones, bloqueo de doble envío, fuentes/consentimiento, recarga, 409, cancelación y foco comprobados en navegador. Capturas sintéticas revisadas en artifacts/practicas-regladas, incluido borrador-desktop.png y borrador-mobile.png. Contraste medido: texto secundario #56634d sobre #fffefa, 6,33:1; borde de controles #727b68 sobre #fffefa, 4,38:1 (borde, no texto). Las etiquetas superiores utilizan el tono de texto secundario.

Esta evidencia no certifica WCAG, lectura con lector de pantalla, corrección jurídica, funcionamiento de una clave/saldo real ni una generación real. La superficie conectada es administrativa; no incorpora todavía un portal independiente para estudiantes sin rol administrativo.

## Ejecución y entrega local

Con las dependencias ya instaladas:

- Frontend: npm run build, npm run lint y node --test tests/reglas.test.mjs tests/activacion-reglas.test.mjs tests/practicas.test.mjs desde frontend.
- Navegador: node tests/practicas-ui.mjs; indicar JURISSIM_PLAYWRIGHT_PATH, JURISSIM_BROWSER_PATH y JURISSIM_UI_URL cuando no estén disponibles por defecto. No instala navegadores ni dependencias.
- Backend: vendor/bin/phpunit --filter=PracticaRegladaApiTest desde backend con las credenciales locales de jurissim_pruebas, sin usar la base habitual.

No se requieren migraciones ni una clave nueva. No se modifican .env, documentos, reglas jurídicas, revisiones, habilitaciones, activaciones o prácticas habituales. La creación permanecerá bloqueada si no existe una copia jurídicamente revisada y vigente; la condición se explica en pantalla, no se omite.

Frontend /login en 5173, Laravel /api/v1/status en 8001 y FastAPI /api/v1/health en 8100 respondieron 200. La consulta de orquestación sin sesión y con Accept: application/json respondió 401 y no-store/private. Los catorce conteos habituales coinciden con la fase anterior: un usuario, dos expedientes, dos análisis, una simulación antigua, cero intervenciones, borradores y registros jurídicos/reglados. No se enviaron documentos al modelo.

Los resultados privados de consultas y mutaciones nuevas usan gcTime=0; no se precarga un borrador mediante setQueryData antes de montar su observador. Al generar se vuelve a leer el detalle persistido por su ID. No hay caché persistente en localStorage ni reenvío del comando para recuperar su resultado.

La creación y los comandos tienen un bloqueo síncrono de envío además del estado pendiente de la interfaz. El recorrido de navegador ejecuta dos clics consecutivos dentro del mismo evento para creación, generación, confirmación y defensa, y comprueba un solo envío. El cierre incierto también permanece bloqueado hasta consultar de nuevo.

## Archivos de esta fase

- Nueva carpeta frontend/src/features/practicas: contratos/validación, API, consultas, creación, selección de fuentes, revisión/acta y estilos.
- Integración selectiva en frontend/src/pages/admin/SimulationDetailPage.tsx y frontend/src/features/simulations/components/CaseSimulationsSection.tsx, conservando el código previo del circuito antiguo.
- Consultas de apoyo en backend/app/Http/Controllers/Api/V1/PracticaRegladaController.php y backend/routes/practicas-regladas.php; extensión de backend/tests/Feature/PracticaRegladaApiTest.php.
- Pruebas frontend/tests/practicas.test.mjs, frontend/tests/practicas-ui.mjs y fixture sintético frontend/tests/fixtures/practicas.mjs.
- Esta documentación y enlace desde docs/27-api-practicas-regladas.md. Capturas sintéticas nuevas en artifacts/practicas-regladas; las capturas antiguas de regresión se conservan sin modificarlas.

Se preservan los cambios previos de frontend, backend y FastAPI en la carpeta habitual. Trabajo local sobre rama funcional existente, sin push, PR o merge a develop/main. El siguiente paso es aceptación conjunta del circuito con un revisor profesional acreditado para las reglas bolivianas; no aprobar normas automáticamente ni presentar el fixture de pruebas como una regla vigente.
