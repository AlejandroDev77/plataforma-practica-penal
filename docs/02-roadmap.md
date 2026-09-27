# Roadmap de desarrollo

## Estado y regla de avance

La base inicial PostgreSQL está implementada y validada localmente en `funcionalidad/base-de-datos`; autenticación/autorización está implementada en `funcionalidad/autenticacion`, y expedientes/archivos en `funcionalidad/gestion-expedientes`. Las tres fases siguen siendo ramas locales: por instrucción del usuario no se hace push, PR ni integración remota hasta que decida reanudar GitHub.

El desarrollo continúa en orden, con una fase por rama/tarea. Antes de extracción/OCR se cierra localmente la rama de expedientes y se verifica con PostgreSQL de pruebas aislado. Las pruebas no usan la base PostgreSQL habitual. La siguiente fase es extracción documental; todavía no se habilita análisis con LLM, RAG ni simulación.

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
- [x] registro con rol de menor privilegio;
- [x] login y logout mediante sesión segura;
- [x] consulta del perfil autenticado;
- [x] sesión protegida y autorización por rol para el panel administrativo;
- [x] recuperación y restablecimiento de contraseña;
- [x] bootstrap del primer administrador desde consola, nunca desde el formulario.

Estado: implementada y verificada localmente en `funcionalidad/autenticacion`; todavía no publicada ni integrada.

Resultado:
un usuario autenticado con rol administrativo puede entrar al dashboard. El registro público crea solo una cuenta básica, nunca concede permisos administrativos.

Una cuenta nueva no recibe permisos de administración. El primer administrador local se habilita con el comando documentado en `backend/README.md` después de crear la cuenta. La UI se comprobó visualmente; las rutas se cubren con pruebas de API en PostgreSQL aislado.

El perfil visible viene de la sesión real; editar identidad y guardar preferencias institucionales en el servidor todavía no están implementados.

---

## Fase 2 — Expedientes
- [x] crear, consultar, editar y eliminar expedientes propios;
- [x] subir y retirar varios archivos por expediente;
- [x] aceptar PDF y formatos de imagen permitidos;
- [x] validar tipo detectado y limitar cada archivo a 50 MB;
- [x] almacenar en disco privado local durante desarrollo;
- [x] preparar un disco R2 compatible con S3 configurable;
- [ ] configurar y verificar con un bucket/credenciales R2 reales;
- [x] probar recepción de un DOCX Office Open XML válido.

Resultado:
usuario gestiona solo sus propios expedientes.

Estado: implementada y probada localmente en `funcionalidad/gestion-expedientes`. El contenido queda privado y solo hay descarga autenticada. R2 ya tiene adaptador, pero aún no se configuró ni probó un bucket. No hay extracción de texto, OCR, detección de páginas ni análisis; eso se implementa en la fase 3.

---

## Fase 3 — Extracción
- [x] enviar archivos privados a un trabajo de cola de Laravel;
- [x] extraer texto de PDF digital por página;
- [x] extraer texto DOCX sin simular una paginación física;
- [x] recorrer imágenes JPG, PNG y hojas TIFF con OCR selectivo;
- [x] guardar páginas, localizadores, legibilidad y estado del proceso;
- [x] proteger la API interna FastAPI con token compartido y validar límites;
- [ ] instalar Tesseract con idioma español y verificar OCR real sobre un escaneo sintético.

Estado: pipeline local y pruebas automáticas listos. En esta máquina no se encontró el ejecutable Tesseract; mientras falte, las imágenes escaneadas se reportan como no legibles y el proceso no declara éxito. DOCX muestra el documento completo porque su paginación física no está disponible en esta extracción.

El procesamiento funciona de forma nativa, sin Docker: el worker Laravel entrega el archivo privado a FastAPI con token, PyMuPDF/python-docx extraen texto y las páginas/localizadores y estados se guardan en PostgreSQL. FastAPI no persiste copias del expediente ni llama a LLM.

Resultado de la fase: texto recuperado asociado al archivo y a páginas/localizadores, con las limitaciones explícitas de DOCX y OCR aún sin Tesseract real.

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
