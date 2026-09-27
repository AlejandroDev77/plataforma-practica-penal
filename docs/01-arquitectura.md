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
- pgvector

### Archivos
- Cloudflare R2

### Procesamiento
- PyMuPDF
- python-docx
- OCRmyPDF/Tesseract

### Colas
- Redis

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
