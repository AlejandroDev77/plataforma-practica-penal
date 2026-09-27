# Arquitectura

## Stack

### Frontend
- React
- TypeScript
- Vite
- Tailwind CSS
- React Three Fiber
- Drei
- Zustand

### Backend principal
- Laravel
- REST API
- autenticación con Sanctum
- autorización por usuario

### Servicio IA
- Python
- FastAPI
- Pydantic

### Datos
- PostgreSQL
- pgvector (opcional y diferido hasta implementar recuperación vectorial)

### Archivos
- Cloudflare R2 (diferido hasta implementar cargas)

### Procesamiento
- PyMuPDF
- python-docx
- OCRmyPDF/Tesseract

### Colas
- cola de base de datos en desarrollo; Redis se evaluará al preparar staging/producción

### Desarrollo local
- PHP/Laravel, PostgreSQL, Node/Vite y Python se ejecutan de forma nativa.
- No utilizar Docker.
- El frontend se inicia desde `frontend/` con `npm run dev`.
- PostgreSQL local es la base de desarrollo prevista; no migrar a SQLite ni a un servicio administrado sin una decisión explícita.

---

## Responsabilidades

### Laravel
Maneja:
- usuarios;
- autenticación;
- expedientes;
- archivos;
- permisos;
- simulaciones;
- historial;
- evaluaciones;
- integración con R2;
- comunicación con FastAPI.

### FastAPI
Maneja:
- OCR;
- extracción;
- chunking;
- embeddings;
- análisis estructurado;
- RAG;
- motor IA;
- evaluación.

### React
Maneja:
- dashboard;
- carga de archivos;
- resumen;
- selección de audiencia/rol;
- sala;
- chat;
- historial;
- resultados.

El frontend no es autoridad para permisos, propiedad de expedientes ni transiciones de audiencia. La API Laravel valida todas las operaciones.

---

## Comunicación

```text
React
  |
  v
Laravel API
  |
  +--> PostgreSQL
  +--> Cloudflare R2
  |
  v
FastAPI IA
  |
  +--> PostgreSQL/pgvector
  +--> proveedor LLM
```

## Regla
No mezclar lógica jurídica/IA compleja dentro de controladores Laravel.

## Estado de implementación

- La estructura de los tres proyectos existe.
- La base inicial PostgreSQL de dominio está implementada y tiene pruebas locales; su integración por GitHub queda pendiente de que los checks requeridos puedan ejecutarse.
- Registro/login conectados, gestión completa de expedientes, OCR/RAG y simulación todavía no se deben considerar implementados.
- La siguiente fase funcional es autenticación y autorización; avanzar una fase por rama/tarea.
