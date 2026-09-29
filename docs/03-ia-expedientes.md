# IA de expedientes

Estado: la fase de extracción está integrada en `develop` mediante PR #4. PDF, DOCX e imágenes se procesan en una cola de Laravel mediante el servicio interno FastAPI. Las pruebas automáticas usan documentos sintéticos. Tesseract 5.5.0 y los modelos `spa`/`eng` están configurados de forma persistente en esta máquina; el OCR real en español se verificó con un PDF sintético.

## Alcance implementado

```text
archivo privado
-> trabajo en cola
-> FastAPI interno con token
-> validar formato, tamaño y páginas
-> extraer texto por página / OCR cuando hace falta
-> guardar páginas y trazabilidad en PostgreSQL
-> mostrar resultado al propietario
```

- PDF: extracción por página con PyMuPDF; intenta OCR cuando el texto nativo es escaso.
- DOCX: conserva párrafos y tablas. Como la paginación depende de la aplicación que lo renderice, se guarda como documento completo y no se inventan páginas físicas.
- JPG, PNG y TIFF: se procesan por imagen; TIFF puede contener varias hojas.
- Se devuelven localizador, texto, uso de OCR y legibilidad. La confianza OCR queda nula cuando no hay una medición fiable.
- Archivos de más de 50 MB, más de 500 páginas, formatos incorrectos y páginas con dimensiones excesivas se rechazan.
- Las páginas sin texto reconocido y los fallos de OCR quedan señalados como parciales/error, nunca como extracción exitosa.
- FastAPI no persiste el archivo ni registra su contenido; Laravel guarda el texto bajo el expediente propietario. No se envía a LLM ni proveedor externo.
- La extracción puede consultarse desde la API autenticada y desde el detalle del expediente; el texto también está restringido al propietario.

La ruta interna es `POST /api/v1/documents/extract`; requiere el mismo `SERVICE_TOKEN` configurado en el servicio y `INTELLIGENCE_SERVICE_TOKEN` en Laravel. Laravel envía el archivo desde su almacenamiento privado y ejecuta el trabajo con `php artisan queue:work --tries=3 --timeout=600`.

## OCR nativo en Windows

La dependencia Python de PyMuPDF no instala el ejecutable Tesseract. El servicio acepta `tesseract` en el `PATH` o una ruta explícita `OCR_TESSERACT_PATH`. Con `OCR_LANGUAGE=spa+eng`, ambos modelos deben estar disponibles. En esta máquina, el ejecutable está en `C:\Program Files\Tesseract-OCR\tesseract.exe` y los modelos se guardaron en `%LOCALAPPDATA%\JURISSIM\tessdata`.

Para habilitarlo en otro equipo, instala Tesseract y prepara un directorio persistente con `spa.traineddata` y `eng.traineddata`, obtenidos del [repositorio oficial de datos de Tesseract](https://github.com/tesseract-ocr/tessdata). Configura `ai-service/.env` con las rutas locales (el archivo no se versiona):

```dotenv
OCR_LANGUAGE=spa+eng
OCR_TESSERACT_PATH=C:\Program Files\Tesseract-OCR\tesseract.exe
OCR_TESSDATA_PATH=C:\Users\USUARIO\AppData\Local\JURISSIM\tessdata
```

Para comprobar la instalación, ejecuta `& 'C:\Program Files\Tesseract-OCR\tesseract.exe' --list-langs --tessdata-dir 'C:\Users\USUARIO\AppData\Local\JURISSIM\tessdata'`; debe mostrar `eng` y `spa`. No hace falta modificar el `PATH` global. Consulta también la [guía oficial de OCR de PyMuPDF](https://pymupdf.readthedocs.io/en/latest/recipes-ocr.html) y la [referencia de idiomas](https://pymupdf.readthedocs.io/en/latest/ocr/tesseract-language-packs.html).

Desde `ai-service/`, instalar las dependencias del módulo y de desarrollo:

```powershell
\.venv\Scripts\python.exe -m pip install -e ".[documents,dev]"
\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8100 --reload
```

Configurar un `SERVICE_TOKEN` aleatorio en `ai-service/.env` y copiar el mismo valor a `INTELLIGENCE_SERVICE_TOKEN` en `backend/.env`. Luego, en otra terminal desde `backend/`, arrancar el worker indicado arriba. Los secretos quedan solo en archivos `.env` locales.

## Análisis estructurado en curso

El primer contrato Pydantic está en `ai-service/app/modules/analysis/`. Recibe lotes acotados de páginas extraídas y define resumen, etapa procesal, participantes, delitos referidos por el documento, hechos, pruebas, cronología, información faltante e incertidumbres.

- Cada afirmación encontrada debe incluir página y extracto literal; la aplicación comprueba que la página pertenece al lote y que el extracto existe en su texto tras normalizar espacios.
- La certeza se clasifica como `textual`, `inferido` o `incierto`; no se trata la salida del modelo como confirmación jurídica ni se convierte en culpabilidad.
- Los lotes admiten hasta 10 páginas y 40.000 caracteres. No se envía el expediente completo en una sola solicitud.
- Los campos extra se rechazan y las preguntas de información faltante no se presentan como hechos.

El contrato, la persistencia versionada y la revisión humana ya están integrados mediante PR #7, pero todavía no se llama a un modelo. El proveedor inicial, el modelo y la política de transferencia de texto jurídico están pendientes; hasta que se decidan, no hay envío de datos a servicios LLM.

## Recuperación textual local del expediente

La rama `funcionalidad/rag-expediente` añade una primera recuperación lexical sobre PostgreSQL, sin embeddings ni proveedor externo. Al terminar la extracción, una tarea en cola divide cada página legible en fragmentos de hasta 1.600 caracteres, con 180 caracteres de solapamiento, y los guarda enlazados al archivo y a la página. Se puede volver a indexar un expediente existente desde una ruta autenticada.

La consulta usa el diccionario español de búsqueda de texto completo de PostgreSQL y devuelve solo coincidencias del expediente del usuario autenticado, con archivo, página y localizador. Un índice GIN parcial mantiene la búsqueda en los fragmentos privados. La puntuación solo ordena coincidencias textuales; no es certeza, validación jurídica ni una afirmación sobre los hechos. Esta fase recupera evidencia y no genera respuestas ni llama al modelo local.

La búsqueda consulta fragmentos del expediente, no fuentes jurídicas. La consulta jurídica usa una ruta y una colección distintas, y solo devuelve fragmentos de fuentes marcadas como validadas, vigentes y dentro de su periodo de vigencia. Una huella del contenido evita servir fragmentos obsoletos si cambia el texto de la fuente antes de reindexarlo. La cita devuelve título, número de norma, versión, vigencia y extracto literal. En una simulación, `POST /api/v1/simulaciones/{simulacion}/fuentes` ofrece ambas búsquedas en listas separadas, limitando la evidencia privada al expediente vinculado a la simulación.

La ruta interna puede encolar la indexación solo de esas fuentes elegibles. No se precarga ni se inventa corpus legal: la curación, carga y revisión por personas competentes de las normas bolivianas siguen pendientes. Esta búsqueda no genera respuestas ni llama al modelo local; embeddings y generación condicionada por recuperación continúan separados.

## Contexto estructurado objetivo

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

Cada dato futuro deberá conservar archivo/página cuando exista, estado (`confirmado`, `inferido`, `incierto` o `faltante`) y evidencia. No completar información ausente ni convertir OCR dudoso en un hecho.

## RAG jurídico futuro

Mantener separada la colección del conocimiento jurídico validado. Nunca mezclarla con la del expediente actual sin conservar procedencia, versión y localizador.

Antes de probar con casos reales hace falta acordar consentimiento, anonimización, retención y revisión jurídica de protección de datos. Las pruebas actuales usan únicamente archivos sintéticos.
