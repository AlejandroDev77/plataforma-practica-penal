# Interfaz de revisión y habilitación de reglas

Fase frontend del circuito definido en [el contrato de API](16-api-revision-reglas.md). No cambia PostgreSQL, el motor de audiencias ni proveedores de generación. Continúa en la rama `funcionalidad/versiones-reglas-audiencia`, con commit local y sin publicación.

## Decisiones de interfaz

Proyecto en desarrollo: espacio educativo de práctica penal. Usuarios de esta superficie: administración técnica y personas con habilitación jurídica específica. Objetos: versiones selladas, decisiones y habilitaciones; no mezclar con expedientes privados.

Entrada administrativa desde Secciones → Reglas de audiencia. Primera decisión: consultar versiones o habilitaciones. La lectura precede a la decisión; la evidencia de acreditación se abre por separado. El éxito es una confirmación del servidor con historial actualizado, no la activación de turnos.

Se mantienen React/Vite, TanStack Query, tipografías Inter/Source Serif, paleta papel/tinta y componentes `PageHeading`, `Field`, `Modal` del producto. La referencia visual es el espacio de propuestas de turnos de JURISSIM; no se añade una biblioteca ni se modifica la navegación general. Listados editoriales acotados, detalle de lectura con contexto lateral y formularios de confirmación; sin hero, métricas ficticias, emojis ni JSON como operación principal. En móvil se conserva la lectura y todas las acciones, con controles de al menos 44 px donde corresponde.

Las guías de diseño y accesibilidad orientaron la separación de tareas, uso de tokens, confirmaciones, estados recuperables y retorno de foco a un punto estable después de cerrar un diálogo. Se reutiliza el diálogo nativo y se impide su cierre por Escape/fondo durante una escritura. No se rediseñan otras pantallas.

## Direcciones

- `/configuracion/reglas`: versiones y habilitaciones para `administrador_plataforma`.
- `/configuracion/reglas/versiones/{id}`: lectura de copia sellada e historial.
- `/revision-reglas`: entrada independiente con sesión, sin exigir un rol administrativo.
- `/revision-reglas/versiones/{id}`: revisión del ámbito habilitado, con autorización final en Laravel.
- `?seccion=habilitaciones` abre el listado de habilitaciones desde la lectura.

La entrada independiente no abre el resto de la administración: las rutas anteriores conservan su comprobación de roles. Una persona sin habilitación ve un listado vacío y no recibe versiones ajenas. El navegador nunca habilita permisos mediante roles declarados, IDs de propietario o banderas de ejecución.

## Flujos y seguridad

- Sellado: audiencia disponible, motivo y confirmación de conservación inmutable. La API captura el catálogo; no se envían contenido, autor ni estado ejecutable desde el formulario.
- Habilitación: identificador de una cuenta real, audiencia, alcance, vencimiento, referencia y huella de evidencia, más comprobación humana explícita. No se usan cuentas del almacén de demostración ni se preselecciona un revisor real. La identidad y competencia deben comprobarse fuera de una mera captura de datos.
- Decisión: lectura de la versión, habilitación propia vigente e independencia del autor y de todas las propuestas. Inicialmente se propone `requiere_cambios`, no aprobación. Fuentes identificadas con huella y fecha de comprobación de vigencia; aprobación con vencimiento dentro del ámbito. La fecha de comprobación no se rellena automáticamente.
- Retiro: motivo y confirmación; no elimina historial ni recupera una aprobación anterior. La API mantiene la autoridad final de titular/autor o administración.

La precondición de decisión se fija al abrir el formulario desde la versión consultada. No se renueva automáticamente después de un conflicto. Cambiar datos relevantes restablece la confirmación humana. Un cerrojo evita doble envío incluso antes del siguiente render; no hay reintentos de escrituras. `409`, pérdida de permiso o falta de confirmación de respuesta bloquean nuevos envíos en ese formulario y exigen cerrar, consultar y revisar de nuevo. Se conservan los datos para comprender el error, pero no se afirma éxito antes de recibirlo.

Las huellas se pueden ingresar o calcular con Web Crypto sobre los bytes de archivos de hasta 20 MB. Los archivos permanecen en el navegador: no se envían al backend ni al proveedor. La huella identifica bytes; no demuestra competencia profesional, autenticidad o vigencia jurídica. Las claves internas que estabilizan las filas de fuentes no salen en el payload.

Datos de revisión y acreditación solo en memoria, con consultas por ID de sesión, sin persistencia, caché inactiva de duración cero, refetch automático de ventana/reconexión desactivado y sin reintentos. El cierre de sesión del espacio de revisión elimina el estado de consultas. No se muestran errores privados de la API. Una consulta o un permiso de interfaz nunca sustituyen las comprobaciones transaccionales del servidor.

Vencimientos enviados con zona horaria; los controles de fecha/hora usan la hora local del equipo y así lo indican. Las fechas del historial se muestran en `America/La_Paz`. El reloj de PostgreSQL decide la validez final.

La pantalla de una versión requiere acceso vigente para consultar su historial. Si se pierde ese acceso, la administración puede retirar una decisión desde su vista; el comando API también admite el retiro por su autor. Una futura vista de decisiones propias retirables sin acceso al contenido necesita su contrato de consulta específico, no ampliar permisos de lectura a versiones ajenas.

## Archivos

- API y tipos/validación: `frontend/src/features/audiencias/api/reglas-api.ts`, `model/reglas.ts`, `model/use-reglas.ts`.
- Componentes: `frontend/src/features/audiencias/components/ReglasUi.tsx`, `ReglasForms.tsx`.
- Pantallas y estilo: `frontend/src/pages/admin/ReglasAudienciaPage.tsx`, `VersionReglasPage.tsx`, `reglas-audiencia.css`.
- Espacio del revisor: `frontend/src/layouts/RevisionReglasLayout.tsx`.
- Integración: `frontend/src/app/router.tsx`, `lazy-pages.tsx`, `layouts/AppShell.tsx` y `Brand.tsx`.
- Extensiones compatibles: `frontend/src/components/ui/AdminUi.tsx` (diálogo ocupado) y `features/auth/components/RequireAuthentication.tsx` (sesión independiente, administración por defecto).
- Pruebas: `frontend/tests/reglas.test.mjs`, `reglas-ui.mjs`; scripts en `frontend/package.json`.
- Capturas de datos sintéticos en el checkout verificado: `artifacts/revision-reglas`.

## Verificación y comandos

Sin migraciones ni instalación de dependencias nuevas. Con Node 24 y las dependencias existentes:

```powershell
cd frontend
npm run build
npm run lint
npx tsc -p tsconfig.app.json --noEmit --strict
npm run test:reglas
```

`npm run test:reglas-ui` requiere un frontend aislado en `127.0.0.1:5177` o `JURISSIM_UI_URL`, un Playwright disponible (`JURISSIM_PLAYWRIGHT_PATH` si no está instalado en el proyecto) y un navegador existente (`JURISSIM_BROWSER_PATH` si no coincide con el binario esperado). No instala navegadores. Todos los endpoints API se interceptan con escenarios sintéticos y el tráfico externo se bloquea; no usa el login real ni crea autoridades/decisiones en `jurissim`.

Pruebas de modelo: 14 casos sobre confirmación, identidad, ámbito, independencia, fechas, fuentes, precondición, payload permitido y huellas. Navegador: listado/filtro/paginación, registro de decisión, conflicto sin reenvío, teclado/Escape/foco, doble envío, habilitación con huella local, retiro, denegación, sesión ausente, error de servicio y reflujo/texto largo a 390, 768 y 1365 px, más tamaño de texto 200 % y preferencia de movimiento reducido. La lectura y el formulario móvil se inspeccionan mediante capturas.

Contrastes medidos: tinta secundaria sobre papel 6,33:1; error sobre papel 7,45:1; texto del botón principal 8,55:1. Esto no certifica toda WCAG: no se realizó prueba con lector de pantalla real ni scanner automático. No se midieron Core Web Vitals; Vite advierte un chunk principal de aproximadamente 559 kB, independiente de la carga diferida de estas pantallas. La optimización general queda como tarea separada.

## Resultado de esta fase

4 de octubre de 2026: build, lint y TypeScript estricto correctos en la rama y en la carpeta habitual; 14 pruebas de modelo y 12 escenarios de navegador aprobados en ambos checkouts. El escenario adicional confirma que una habilitación rechazada al actualizar oculta la acción de revisión, sin reutilizar la autoridad almacenada en memoria.

La primera corrida de navegador de la carpeta habitual tuvo un timeout esperando la redirección al login; las repeticiones completas pasaron. Se añadieron diagnósticos del escenario sintético para no ocultar futuros fallos. Las capturas incluyen un retiro con su historial coherente, no una aprobación recuperada.

Se incorporaron únicamente los archivos de esta fase a la carpeta habitual, conservando los cambios ajenos. Commit local en la rama de trabajo; sin push, PR ni merge. No se iniciaron servicios permanentes ni se modificaron credenciales, cuentas, expedientes, proveedores o bases de datos. Los servidores frontend aislados usados en la comprobación no son un despliegue.

La siguiente fase comienza con la [preparación interna de activación](18-preparacion-activacion-reglas.md): validación estructural y diagnóstico, todavía sin activación ni vínculo ejecutable al orquestador. Se requiere revisión profesional real antes de cargar reglas bolivianas ejecutables.
