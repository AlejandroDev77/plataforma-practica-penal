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
- Disco `local` privado para desarrollo nativo (`backend/storage/app/private`).
- Cloudflare R2 disponible como disco S3-compatible opcional; necesita endpoint, bucket y credenciales propios.
- No publicar buckets ni retornar rutas internas al frontend.

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
- Autenticación, recuperación de contraseña y control de rol están implementados localmente; no se han publicado ni integrado.
- CRUD de expedientes y gestión de archivos están implementados y probados localmente en una rama dependiente, con disco privado local como configuración por defecto.
- R2 se conecta mediante el adaptador S3 de Flysystem cuando existan credenciales; la integración de cuenta/bucket aún no se ha probado.
- OCR, extracción, RAG y simulación todavía no se deben considerar implementados.
- La siguiente fase de producto es extracción/OCR; antes se debe cerrar y verificar la fase de expedientes en local.
