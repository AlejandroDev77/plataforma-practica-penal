# JURISSIM — reglas de trabajo

## Ramas Git
- Antes de modificar código: comprobar `git status --short --branch` y preservar cambios ajenos.
- `main` contiene versiones estables y recibe únicamente PR desde `develop`.
- `develop` integra el trabajo mediante PR; no implementar funcionalidades directamente allí.
- Crear ramas desde `develop` actualizado con `git pull --ff-only origin develop`.
- Nombres en español, minúsculas y guiones: `tipo/nombre-descriptivo`.
- Tipos: `funcionalidad`, `correccion`, `mejora`, `refactorizacion`, `prueba`, `documentacion`, `configuracion`.
- Base de datos: `funcionalidad/base-de-datos`.
- Al terminar: ejecutar verificaciones, revisar y añadir solo archivos de la tarea y hacer commits locales cuando corresponda.
- Si el bloqueo de facturación de GitHub vuelve a impedir que Actions ejecute los checks, detener la publicación; no omitir ni desactivar protecciones.
- Cuando se autorice publicar: push de la rama y PR a `develop`; integrar únicamente después de que los checks requeridos pasen.
- No realizar merge automático a `main`, eliminar ramas principales ni force push.
- No crear ramas futuras hasta que se solicite su tarea.
- La base de datos, autenticación, gestión de expedientes y extracción ya están integradas en `develop` mediante PRs aprobados. La rama existente `funcionalidad/analisis-expedientes` debe sincronizarse con `origin/develop` antes de publicar su contrato; no crear una rama sustituta.

## Implementación
- Leer `docs/00-contexto.md`, `docs/07-reglas-codex.md` y únicamente los documentos de la fase.
- Respetar versiones instaladas; PostgreSQL local para el dominio; sin Docker ni migración a SQLite/servicios administrados sin autorización explícita.
- Tablas, campos y modelos propios en español; conservar convenciones de Laravel y paquetes.
- No confiar en identificadores de propietario suministrados por el cliente.
- Ejecutar pruebas destructivas exclusivamente en `jurissim_pruebas`; nunca contra datos de desarrollo/producción.
- Una fase por tarea; no implementar servicios de IA, frontend o endpoints si la tarea es de base de datos.
