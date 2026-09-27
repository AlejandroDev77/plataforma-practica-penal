# IA de expedientes

Estado: diseño para una fase futura; el procesamiento de documentos todavía no está habilitado para expedientes reales.

## Objetivo
Transformar documentos arbitrarios en un `CaseContext` confiable.

## Pipeline

```text
archivos
-> extracción/OCR
-> páginas
-> chunks
-> embeddings
-> extracción estructurada
-> validación
-> CaseContext
```

El pipeline se implementará después de autenticación y gestión segura de expedientes/archivos. No enviar documentos reales a proveedores de IA hasta definir consentimiento, anonimización, retención y límites de acceso.

## CaseContext mínimo

```json
{
  "summary": "",
  "procedural_stage": "",
  "participants": [],
  "offenses": [],
  "facts": [],
  "evidence": [],
  "chronology": [],
  "missing_information": [],
  "uncertain_information": []
}
```

## Regla de trazabilidad
Cada hecho relevante debe intentar guardar:

```json
{
  "value": "...",
  "source_file": "...",
  "page": 1,
  "confidence": 0.0
}
```

## Reglas anti-alucinación
1. No completar datos ausentes.
2. No inventar nombres, fechas, delitos, pruebas o actuaciones.
3. Diferenciar:
   - confirmado;
   - inferido;
   - incierto;
   - faltante.
4. Si OCR es deficiente, indicarlo.
5. Preferir "no identificado" antes que inventar.

## RAG
Mantener dos colecciones:

### Expediente
Solo información del caso actual.

### Jurídico
Normativa y material validado por el equipo de Derecho.

Nunca mezclar ambas fuentes sin registrar origen.
