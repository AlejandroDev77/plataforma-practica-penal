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
- Mientras siga sin resolverse el bloqueo de facturación de GitHub, no hacer push, no crear/actualizar PR y no intentar saltar checks. Reanudar publicación solo cuando el usuario lo indique y las comprobaciones requeridas puedan ejecutarse.
- Cuando se autorice publicar: push de la rama y PR a `develop`; integrar únicamente después de que los checks requeridos pasen.
- No realizar merge automático a `main`, eliminar ramas principales ni force push.
- No crear ramas futuras hasta que se solicite su tarea.
- Excepción local vigente: el usuario pidió continuar durante el bloqueo de GitHub. Si una fase nueva depende de `funcionalidad/base-de-datos` aún no integrada, puede trabajarse en una rama local derivada de ella; no publicarla. Al habilitarse GitHub, primero integrar base de datos con checks aprobados y después rebasar la rama dependiente sobre `develop` antes de publicarla.

## Implementación
- Leer `docs/00-contexto.md`, `docs/07-reglas-codex.md` y únicamente los documentos de la fase.
- Respetar versiones instaladas; PostgreSQL local para el dominio; sin Docker ni migración a SQLite/servicios administrados sin autorización explícita.
- Tablas, campos y modelos propios en español; conservar convenciones de Laravel y paquetes.
- No confiar en identificadores de propietario suministrados por el cliente.
- Ejecutar pruebas destructivas exclusivamente en `jurissim_pruebas`; nunca contra datos de desarrollo/producción.
- Una fase por tarea; no implementar servicios de IA, frontend o endpoints si la tarea es de base de datos.
