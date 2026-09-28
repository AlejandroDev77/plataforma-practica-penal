# Roadmap de desarrollo

## Estado y regla de avance

Las fases de base de datos, autenticación, gestión de expedientes y extracción están integradas en `develop` mediante los PR #1–#4; los checks requeridos de GitHub pasaron antes de cada merge. El usuario confirmó el desbloqueo de facturación de GitHub el 27-09-2026. `main` no se ha modificado.

El trabajo sigue una fase por rama, desde `develop`, y se integra solo por PR tras pasar los checks. La siguiente tarea es completar el contrato de análisis ya iniciado en `funcionalidad/analisis-expedientes`; todavía no se habilitan llamadas a LLM, RAG ni simulación. No ejecutar pruebas destructivas contra la base PostgreSQL habitual.

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

Estado: implementada, verificada e integrada en `develop` mediante PR #2.

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

Estado: implementada, probada e integrada en `develop` mediante PR #3. El contenido queda privado y solo hay descarga autenticada. R2 tiene adaptador, pero aún no se configuró ni probó un bucket. La extracción se añadió en la fase 3.

---

## Fase 3 — Extracción
- [x] enviar archivos privados a un trabajo de cola de Laravel;
- [x] extraer texto de PDF digital por página;
- [x] extraer texto DOCX sin simular una paginación física;
- [x] recorrer imágenes JPG, PNG y hojas TIFF con OCR selectivo;
- [x] guardar páginas, localizadores, legibilidad y estado del proceso;
- [x] proteger la API interna FastAPI con token compartido y validar límites;
- [x] verificar OCR real en español sobre un escaneo sintético con Tesseract 5.5.0;
- [ ] dejar el ejecutable Tesseract y el modelo `spa` configurados de forma persistente en el entorno local.

Estado: pipeline y pruebas automáticas integrados en `develop` mediante PR #4. La prueba sintética real reconoció texto en español sin advertencias. Tesseract 5.5.0 está instalado en Windows, pero su carpeta aún no forma parte del `PATH`; el modelo `spa` se usó desde una carpeta temporal para esta verificación. Para que OCR quede disponible al iniciar FastAPI en otras sesiones, configurar una ruta persistente para `spa` y `eng` y agregar el ejecutable al `PATH` del proceso, según `docs/03-ia-expedientes.md`. DOCX muestra el documento completo porque su paginación física no está disponible en esta extracción.

El procesamiento funciona de forma nativa, sin Docker: el worker Laravel entrega el archivo privado a FastAPI con token, PyMuPDF/python-docx extraen texto y las páginas/localizadores y estados se guardan en PostgreSQL. FastAPI no persiste copias del expediente ni llama a LLM.

Resultado de la fase: texto recuperado asociado al archivo y a páginas/localizadores, con las limitaciones explícitas de DOCX y con OCR español probado sobre un documento sintético. El entorno permanente de OCR sigue pendiente.

---

## Fase 4 — Análisis IA
- [x] definir el contrato JSON estricto para resumen, etapa, participantes, delitos, hechos, pruebas y cronología;
- [x] representar vacíos e incertidumbres sin inventar hechos;
- [x] exigir citas textuales vinculadas a páginas del lote autorizado;
- [x] limitar páginas y caracteres procesados por lote;
- [x] persistir resultados por versión, volver a verificar las citas y ofrecer revisión humana del propietario;
- [ ] decidir proveedor/modelo inicial y política para enviar texto jurídico;
- [ ] ejecutar análisis por cola.

Estado: el esquema Pydantic, la persistencia interna versionada, las citas tipadas, el historial de revisión y la interfaz del expediente están implementados en `funcionalidad/analisis-expedientes`. La migración aditiva se aplicó en desarrollo y `jurissim_pruebas` se creó como base aislada. La suite Laravel pasó con 56 pruebas y 357 aserciones; también pasaron las pruebas Python y las verificaciones previas de frontend. La base habitual `jurissim` no se usó para pruebas destructivas. No hay llamadas a LLM ni generación de contenido real: la decisión de proveedor/modelo y la política de transferencia de texto jurídico siguen pendientes por sus implicaciones de privacidad, coste y configuración.

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
