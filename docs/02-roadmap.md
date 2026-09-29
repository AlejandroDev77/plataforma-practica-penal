# Roadmap de desarrollo

## Estado y regla de avance

La base, autenticación, expedientes y extracción están integrados mediante los PR #1–#4; el análisis versionado, la revisión humana y sus actualizaciones, incluida la configuración persistente de OCR, mediante los PR #5–#8; el motor de transiciones, la API autenticada de simulaciones y los turnos configurables mediante los PR #9–#11; la persistencia de citas por intervención mediante el PR #13; la recuperación RAG local del expediente y fuentes jurídicas mediante el PR #14; y la ejecución de análisis con Ollama local mediante el PR #16. Los checks requeridos pasaron antes de cada merge. `main` no se ha modificado.

El trabajo sigue una fase por rama, desde `develop`, y se integra solo por PR tras pasar los checks. El análisis versionado y con revisión humana está integrado mediante PR #7 y completado con la ejecución local del PR #16; la API base de simulaciones y sus turnos configurables mediante PR #9–#11; la persistencia de citas mediante PR #13; y la recuperación textual separada del expediente y fuentes jurídicas mediante PR #14. La búsqueda usa PostgreSQL local, sin embeddings. La ejecución del análisis estructurado con Ollama local, su cola, la interfaz y la trazabilidad del proveedor/modelo están integradas mediante el PR #16. Ollama continúa desactivado por defecto; las pruebas del modelo se hicieron con datos sintéticos y el proveedor externo permanece fuera de alcance. La carga del corpus oficial sigue pendiente de curación y revisión competente. No ejecutar pruebas destructivas contra la base PostgreSQL habitual.

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
- [x] dejar el ejecutable Tesseract y el modelo `spa` configurados de forma persistente en el entorno local.

Estado: pipeline y pruebas automáticas integrados en `develop` mediante PR #4. La configuración persistente de OCR en español se incorporó en PR #8. Tesseract 5.5.0 está instalado en Windows; `spa` y `eng` se guardaron en `%LOCALAPPDATA%\JURISSIM\tessdata`, y el `.env` local de FastAPI configura tanto el ejecutable como los modelos sin modificar el `PATH` global. Un escaneo sintético reconoció texto en español sin advertencias. DOCX muestra el documento completo porque su paginación física no está disponible en esta extracción.

El procesamiento funciona de forma nativa, sin Docker: el worker Laravel entrega el archivo privado a FastAPI con token, PyMuPDF/python-docx extraen texto y las páginas/localizadores y estados se guardan en PostgreSQL. FastAPI no persiste copias del expediente ni llama a LLM.

Resultado de la fase: texto recuperado asociado al archivo y a páginas/localizadores, con las limitaciones explícitas de DOCX y con OCR español probado y configurado localmente de forma persistente.

---

## Fase 4 — Análisis IA
- [x] definir el contrato JSON estricto para resumen, etapa, participantes, delitos, hechos, pruebas y cronología;
- [x] representar vacíos e incertidumbres sin inventar hechos;
- [x] exigir citas textuales vinculadas a páginas del lote autorizado;
- [x] limitar páginas y caracteres procesados por lote;
- [x] persistir resultados por versión, volver a verificar las citas y ofrecer revisión humana del propietario;
- [x] elegir Ollama local para pruebas y mantener el texto jurídico en el equipo;
- [x] ejecutar el análisis por cola y persistir el resultado trazable.

Estado: el esquema Pydantic, la persistencia interna versionada, las citas tipadas, el historial de revisión y la interfaz de revisión humana están integrados en `develop` mediante PR #7. La ejecución local con Ollama, la cola, la interfaz y el registro del proveedor/modelo están integrados mediante PR #16. Los checks requeridos pasaron, incluido el de integración PostgreSQL; localmente pasaron 26 pruebas Python, Ruff, Pint, PHP lint, `npm run build` y `npm run lint`. La llamada sintética directa con `qwen3.5:2b-q4_K_M` respondió con el esquema esperado y metadatos Ollama/modelo. Ollama queda desactivado por defecto y solo se permite en loopback al habilitarlo; no se envía texto a proveedores externos. El modelo se verificó con datos sintéticos, no con expedientes reales, y los resultados requieren revisión humana. La base habitual `jurissim` no se usó para pruebas destructivas.

---

## Fase 5 — RAG
- [x] RAG del expediente: recuperación textual local con citas de archivo/página, integrada en `develop` mediante PR #14.
- [x] RAG jurídico: indexación y consulta separada limitada a fuentes validadas y vigentes (PR #14); curar y cargar el corpus oficial sigue pendiente.

Resultado:
la IA recupera solo fragmentos relevantes con referencias.

---

## Fase 6 — Motor de audiencia
- [x] controlar inicio y avance usando etapas y transiciones configuradas;
- [x] configurar turnos por etapa, validar el rol del usuario al registrar intervenciones de texto y no avanzar mientras queden turnos configurados;
- [ ] definir actos procesales específicos permitidos por rol, sujetos a revisión jurídica;
- [x] conservar referencias verificadas de fuentes en intervenciones (PR #13);
- [x] permitir conservar propuestas de secuencia como borradores aislados; no implican aprobación ni activación;
- [x] exponer creación, consulta, registro de intervenciones y avance mediante API autenticada y limitada al propietario.

MVP:
medidas cautelares.

Estado: los PR #9–#11 integraron el avance por transiciones activas, la API limitada al propietario y el registro de intervenciones de texto bajo turnos configurables por rol. El PR #13 agregó el registro de referencias verificadas por intervención. La consola administrativa puede preparar propuestas aisladas que siempre permanecen en estado borrador; el rol técnico que permite prepararlas no confiere competencia jurídica ni existe una acción de aprobación o activación. No se precargaron turnos jurídicos: requieren revisión del equipo competente. Aún no hay agentes ni memoria conversacional automática; el siguiente trabajo jurídico depende de definir y revisar esas reglas y acordar quién y cómo podría promoverlas al catálogo activo.

---

## Fase 7 — Simulación por texto

Estado: el servicio interno de FastAPI puede proponer una intervención breve de juez/fiscal mediante Ollama local, con contrato estricto y contexto acotado (PR #19, integrado en `develop`). La ruta autenticada de Laravel prepara y devuelve borradores únicamente cuando el turno actual del servidor asigna juez/fiscal, conserva una instrucción no vacía, tiene participante IA activo y el análisis conserva su aprobación humana vigente. Obtiene hechos, citas y transcripción desde recursos autorizados del servidor; ignora cualquier contexto paralelo enviado por el cliente. La propuesta exige revisión humana y no se persiste como intervención ni avanza la audiencia. No se han cargado reglas jurídicas activas ni se han activado propuestas de turnos; la instrucción queda vacía por defecto. Se probó directamente con Qwen local y datos sintéticos; eso no valida calidad jurídica. Ollama continúa desactivado por defecto.

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
