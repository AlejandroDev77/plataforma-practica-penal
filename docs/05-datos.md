# Modelo de datos inicial — JURISSIM

Estado: esquema inicial implementado y validado localmente con PostgreSQL de pruebas aislado. Autenticación, CRUD de expedientes/archivos y almacenamiento de páginas extraídas ya tienen API; las tablas de análisis, recuperación, simulación y evaluación no significan que esos flujos estén construidos.

El contrato Pydantic para el contenido de `analisis_expediente.datos_estructurados` está en desarrollo; aún no se crean versiones de análisis ni filas normalizadas a partir de un modelo.

PostgreSQL. Las tablas de dominio y sus atributos están en español, con claves
descriptivas y timestamps `fecha_creacion` / `fecha_actualizacion`.
Laravel, Sanctum y Spatie mantienen sus tablas/columnas estándar.

## Tablas implementadas

| Área | Tablas |
| --- | --- |
| Expedientes y archivos | expedientes, archivos_expediente, paginas_expediente |
| Análisis versionado | analisis_expediente, participantes_expediente, delitos_expediente, hechos_expediente, pruebas_expediente, cronologia_expediente, incidencias_analisis |
| Trazabilidad | referencias_expediente, historial_procesamiento |
| Conocimiento | fuentes_juridicas, fragmentos_documento |
| Catálogos | tipos_audiencia, etapas_audiencia, transiciones_audiencia |
| Simulación | simulaciones, participantes_simulacion, intervenciones, fuentes_intervencion |
| Evaluación | rubricas, criterios_rubrica, evaluaciones, resultados_evaluacion |
| Limpieza futura | objetos_pendientes_eliminacion |

## Relaciones y decisiones

- `users.id → expedientes.id_usuario` determina propiedad. Relaciones autenticadas
  asignarán el propietario; `id_usuario` no es mass assignable en los modelos de dominio.
- Un expediente tiene varios archivos; cada archivo tiene páginas numeradas únicas. `localizador` conserva la página física de PDF/imagen o indica explícitamente que un DOCX se extrajo como documento completo.
- `(id_expediente, version)` identifica un análisis. Sus elementos extraídos e incidencias
  apuntan a `id_analisis`; las simulaciones fijan esa versión para conservar contexto.
- Referencias tienen `id_expediente`, `id_analisis`, `id_archivo`, `id_pagina` opcional
  y exactamente una FK de elemento. Las FK compuestas verifican su pertenencia.
- Fragmentos pertenecen a un archivo privado (`id_archivo`) o una fuente jurídica
  (`id_fuente_juridica`), nunca ambos. Las citas se enlazan mediante `id_fragmento`
  y guardan `fragmento_utilizado`; no aceptan fuentes privadas de otro expediente.
- `embedding` se incorpora con una migración pgvector opcional, sin dimensión inventada.
  `modelo_embedding` y `dimensiones_embedding` preparan la integración posterior.
- Audiencias, etapas y transiciones son configurables. Solo medidas cautelares está
  habilitada inicialmente. No hay lógica jurídica definitiva ni motor implementado.
- Intervenciones tienen orden único por simulación; la etapa debe corresponder al tipo.
- Rúbricas versionadas con criterios ponderados. Resultados solo admiten criterios
  de la rúbrica evaluada y puntajes entre cero y su máximo.
- Información extraída y fuentes jurídicas empiezan sin confirmar/validar.
- No hay soft deletes por ahora. Se prueban cascadas y una cola transaccional de claves
  pendientes para la futura eliminación física, sin llamadas a R2.

## Operación y pruebas

Consultar `backend/database/README.md` para configuración PostgreSQL, pgvector,
migraciones, pruebas aisladas, eliminación y límites de esta fase.

### Acceso a expedientes y archivos

- La API lista y resuelve expedientes desde la relación del usuario autenticado; un ID de otra cuenta responde 404.
- La creación asigna `id_usuario` en Laravel. El cliente no puede enviar propietario, estado ni estado de procesamiento.
- Los archivos admitidos se limitan en el servidor a PDF, DOCX e imágenes JPG/PNG/TIFF, con máximo de 50 MB por archivo y 5 por carga.
- En desarrollo se usa el disco `local` de Laravel, cuya raíz es privada y no requiere `storage:link`.
- Las rutas internas, nombres almacenados y discos no se exponen en recursos JSON. Descarga requiere sesión y propiedad del expediente.
- DOCX se inspecciona como archivo ZIP sin extraerlo; se requieren `ext-zip` y la estructura Open XML esperada.
- `FILESYSTEM_DISK=r2` permite configurar Cloudflare R2 mediante Flysystem S3 con `R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com`, región `auto`, bucket y credenciales; no se incluye bucket ni credenciales. R2 debe permanecer privado y probarse antes de activarlo.
- Al eliminar un archivo/expediente, PostgreSQL registra claves pendientes; Laravel las elimina del disco luego del commit y conserva en cola los fallos para reintento.
- Al subir archivos se encola una extracción de texto; eso no implica análisis jurídico, recuperación ni simulación. Antivirus y políticas de retención siguen pendientes. OCR necesita Tesseract instalado y configurado en el host.

La extracción por cola registra el resultado en `paginas_expediente`, incluyendo texto, localizador, uso de OCR y legibilidad. Las páginas se consultan mediante una ruta autenticada que vuelve a limitar el archivo al propietario. El texto extraído es dato sensible y no se incluye en listados generales.
El servicio interno FastAPI recibe solo el archivo que Laravel autorizó, valida el token, extrae en memoria y devuelve páginas; no usa LLM ni conserva otra copia. OCR real depende de Tesseract y del paquete de idioma español instalados en el equipo.

Toda consulta futura de expedientes debe verificar propietario/autorización.
