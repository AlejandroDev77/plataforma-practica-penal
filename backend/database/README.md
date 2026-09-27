# Base de datos inicial de JURISSIM

## PostgreSQL local (sin Docker)

Requisitos: PHP del proyecto con `pdo_pgsql`, PostgreSQL y Composer. Se validó sobre PostgreSQL 17.
No se modifican dependencias ni se convierten datos SQLite existentes.

1. Crear usuario y bases desde una conexión administradora de PostgreSQL. Elegir una contraseña propia:

```sql
CREATE ROLE jurissim LOGIN PASSWORD 'REEMPLAZAR';
CREATE DATABASE jurissim OWNER jurissim;
CREATE DATABASE jurissim_pruebas OWNER jurissim;
```

2. Configurar `backend/.env` según `.env.example` con `DB_CONNECTION=pgsql`, base `jurissim` y credenciales locales.
3. Para una base existente ejecutar `php artisan migrate --seed`. El seeder es idempotente, preserva configuración y no crea cuentas ni expedientes.
4. Preparar `.env.testing` a partir de `.env.testing.example` con una clave de aplicación y credenciales para `jurissim_pruebas`.
5. Ejecutar `php artisan test`. PHPUnit fija conexión y base de pruebas; la suite rechaza DB_URL y otras bases antes de RefreshDatabase.

Solamente en una base de pruebas descartable:

```powershell
$env:DB_CONNECTION='pgsql'
$env:DB_DATABASE='jurissim_pruebas'
# Completar DB_HOST, DB_PORT, DB_USERNAME y DB_PASSWORD del servidor de pruebas.
php artisan migrate:fresh --seed --force
php artisan test
php artisan migrate:status
```

`migrate:fresh` elimina las tablas de la conexión seleccionada. No ejecutarlo sobre `jurissim` con datos reales.
El .env local preexistente no se cambia automáticamente ni se incluyen credenciales en Git.

## pgvector opcional

Las migraciones principales no exigen pgvector. Los fragmentos y la trazabilidad funcionan sin generar embeddings.
Instalar pgvector compatible con la versión/arquitectura del servidor PostgreSQL, siguiendo el proyecto oficial:
https://github.com/pgvector/pgvector#installation

Un administrador puede habilitarlo en la base elegida:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

Después ejecutar:

```text
php artisan migrate --path=database/migrations/pgvector
php artisan migrate:status --path=database/migrations/pgvector
```

Esta migración también ejecuta CREATE EXTENSION IF NOT EXISTS (requiere permiso suficiente cuando no esté habilitada).
Si faltan los binarios, falla con un mensaje explícito sin alterar las tablas principales.
Añade `embedding vector` sin dimensión fija; cada vector debe declarar `modelo_embedding` y `dimensiones_embedding`.
Una restricción compara `vector_dims(embedding)` con la dimensión declarada.
No se crean índices ANN hasta conocer modelo/dimensión/métrica. Las búsquedas futuras deben filtrar modelo, dimensión y propiedad.
La integración de escritura/búsqueda de vectores corresponde a la fase RAG.

Las migraciones opcionales no se ejecutan con el comando normal ni con RefreshDatabase.
Si se aplicaron y se usa rollback, incluir su ruta junto a la principal, por ejemplo:
`php artisan migrate:rollback --path=database/migrations --path=database/migrations/pgvector`.

## Integridad y eliminación

- Las entidades extraídas requieren `id_analisis`; una simulación fija la versión que utiliza.
- Las FK compuestas impiden mezclar expediente, análisis, archivos, páginas, participantes, tipos de audiencia y criterios.
- Referencias usan seis FK tipadas opcionales y un CHECK de exactamente una. No hay identificadores polimórficos sin integridad referencial.
- Fragmentos tienen exactamente un origen: archivo privado o fuente jurídica. Las citas usan fragmentos y conservan el texto utilizado.
- Un trigger impide citar fragmentos privados de otro expediente. Origen/propiedad/contexto de los registros involucrados son inmutables.
- Los puntajes por criterio no pueden superar su máximo; criterios evaluados se modifican creando una versión nueva de rúbrica.
- Estados técnicos acotados con CHECK; roles, delitos y clasificaciones extensibles usan texto. No se implementa lógica jurídica.
- Confianza entre 0 y 1. Tiempos con zona horaria; fechas documentales sin hora inventada.
- Sin soft deletes en esta fase: borrar expediente/usuario elimina sus dependencias y fragmentos privados. No sustituye la futura autorización en API.
- Fuentes compartidas citadas y análisis utilizados se conservan mediante FK; para retirarlos utilizar estado inactivo/archivado.
- Las FK de análisis usado, participante detectado y fragmento citado son diferibles al commit para completar cascadas con varias rutas. Las pruebas fuerzan SET CONSTRAINTS ALL IMMEDIATE antes de afirmar integridad.
- Triggers de eliminación registran las claves de archivos/fuentes en `objetos_pendientes_eliminacion` dentro de la transacción, incluso en cascadas SQL.
- La cola sobrevive a los padres y un rollback también revierte sus entradas. Ningún archivo local ni objeto R2 se borra en esta fase.
- El futuro servicio de limpieza deberá bloquear/reintentar cada tarea, comprobar que la clave no esté nuevamente referenciada y borrar el objeto de forma idempotente.
- `orden` es único por simulación. El futuro motor debe asignarlo bajo bloqueo de fila/transacción; no usar MAX + 1 sin protección.
- Las etapas/transiciones son un catálogo inicial configurable, no un motor procesal implementado.

## Git

`main ← develop ← funcionalidad/base-de-datos`.
Las reglas completas están en `AGENTS.md` y `docs/07-reglas-codex.md`. Los checks requeridos del PR no arrancan mientras GitHub mantenga bloqueada la cuenta por facturación. No afirmamos que las protecciones de ramas estén configuradas más allá de lo que se ve en el PR. No desactivar ni omitir los checks: hasta resolver la cuenta, conservar el trabajo en local. No se hace push o merge sin la indicación del usuario y los checks aprobados.
