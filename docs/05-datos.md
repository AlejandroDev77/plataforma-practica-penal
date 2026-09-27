# Modelo de datos inicial — JURISSIM

Estado: esquema inicial implementado en la rama de base de datos y validado localmente con pruebas PostgreSQL aisladas. Las tablas no implican que los flujos de negocio/API estén construidos.

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
- Un expediente tiene varios archivos; cada archivo tiene páginas numeradas únicas.
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

Toda consulta futura de expedientes debe verificar propietario/autorización.
