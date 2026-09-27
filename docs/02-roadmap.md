# Roadmap de desarrollo

## Estado y regla de avance

La base inicial de PostgreSQL (tablas de dominio, relaciones, restricciones, modelos, seeders y pruebas) está implementada en la rama local `funcionalidad/base-de-datos` y se validó en un PostgreSQL de pruebas aislado. La revisión/merge remoto sigue pendiente porque GitHub Actions no arranca debido al bloqueo de facturación de la cuenta. No se omiten checks ni se hace push mientras el usuario mantenga el trabajo local.

Completar la base no significa que las funciones de autenticación, expedientes, OCR, recuperación o simulación estén listas. Se trabajan en orden y una fase por rama/tarea. El siguiente bloque funcional es autenticación y autorización. Como la base ya tiene una rama local pendiente de checks, se permite avanzar en una rama local dependiente de esa base; no se publica ni integra hasta que la rama de base pase los checks y se integre en `develop`.

## Fase 0 — Base
- crear repositorio;
- configurar frontend;
- configurar Laravel;
- configurar FastAPI;
- configurar PostgreSQL;
- `.env.example`;
- documentar ejecución local nativa, sin Docker.

Resultado:
frontend y backend arrancan localmente; el servicio IA ofrece únicamente su esqueleto técnico de salud, sin procesamiento de expedientes listo para usuarios.

---

## Fase 1 — Usuarios
- registro;
- login;
- logout;
- perfil;
- autorización.

Resultado:
usuario autenticado puede entrar al dashboard.

---

## Fase 2 — Expedientes
- crear expediente;
- subir uno o varios archivos;
- PDF;
- DOCX;
- imágenes;
- almacenar en R2;
- eliminar expediente.

Resultado:
usuario gestiona solo sus propios expedientes.

---

## Fase 3 — Extracción
Pipeline:

```text
archivo
-> detectar formato
-> extraer texto
-> OCR si es necesario
-> normalizar
-> separar páginas
```

Resultado:
texto recuperado con relación archivo/página.

---

## Fase 4 — Análisis IA
Extraer:
- resumen;
- partes;
- delitos;
- hechos;
- pruebas;
- cronología;
- etapa procesal;
- información faltante;
- incertidumbres.

Resultado:
JSON validado por Pydantic.

---

## Fase 5 — RAG
Separar:
- RAG del expediente;
- RAG jurídico.

Resultado:
la IA recupera solo fragmentos relevantes con referencias.

---

## Fase 6 — Motor de audiencia
Crear:
- estados;
- turnos;
- roles;
- memoria;
- reglas;
- orquestador.

MVP:
medidas cautelares.

---

## Fase 7 — Simulación por texto
Usuario:
- abogado defensor.

IA:
- juez;
- fiscal.

Resultado:
audiencia interactiva coherente con expediente y normas.

---

## Fase 8 — Evaluación
Evaluar:
- uso del expediente;
- argumentación;
- fundamentación;
- respuesta;
- coherencia procesal.

Resultado:
feedback estructurado.

---

## Fase 9 — Sala 3D
- sala simple;
- cámara fija;
- personajes GLB;
- indicador de hablante;
- animación idle/hablar.

No añadir movimiento libre.

---

## Fase 10 — Voz
- Speech-to-Text;
- Text-to-Speech;
- micrófono;
- reproducción por personaje.

---

## Fase 11 — Ampliación
- más roles;
- incidentales;
- juicio oral;
- estadísticas;
- mejores rúbricas.
