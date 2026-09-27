# IA de expedientes

Estado: la fase de extracción está integrada en `develop` mediante PR #4. PDF, DOCX e imágenes se procesan en una cola de Laravel mediante el servicio interno FastAPI. Las pruebas automáticas usan documentos sintéticos. Tesseract 5.5.0 está instalado en esta máquina; el OCR real en español se verificó con un PDF sintético y el modelo `spa` aislado en una carpeta temporal. Para usarlo en sesiones normales aún hay que configurar de forma persistente el modelo español y el `PATH` del proceso.

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

La dependencia Python de PyMuPDF no instala el ejecutable Tesseract. El servicio espera `tesseract` en el `PATH` del proceso y, con la configuración predeterminada `OCR_LANGUAGE=spa+eng`, que ambos modelos estén disponibles. En Windows, esta máquina tiene Tesseract 5.5.0 en `C:\Program Files\Tesseract-OCR`, pero ese directorio no está en el `PATH` y el modelo `spa` usado en la prueba se descargó a `%TEMP%`; por tanto, la verificación no implica que OCR quede habilitado permanentemente.

Para habilitarlo en el entorno local, instala o conserva Tesseract y prepara un directorio persistente con `spa.traineddata` y `eng.traineddata`, obtenidos del [repositorio oficial de datos de Tesseract](https://github.com/tesseract-ocr/tessdata). Configura `OCR_TESSDATA_PATH` con ese directorio en `ai-service/.env`. Antes de iniciar FastAPI en la misma sesión de PowerShell, incorpora la carpeta del ejecutable al `PATH` y confirma ambos idiomas:

```powershell
$env:Path = 'C:\Program Files\Tesseract-OCR;' + $env:Path
& 'C:\Program Files\Tesseract-OCR\tesseract.exe' --list-langs --tessdata-dir $env:OCR_TESSDATA_PATH
```

Debe mostrar `eng` y `spa`. PyMuPDF utiliza esos datos al ejecutar OCR. Consulta también la [guía oficial de OCR de PyMuPDF](https://pymupdf.readthedocs.io/en/latest/recipes-ocr.html) y la [referencia de idiomas](https://pymupdf.readthedocs.io/en/latest/ocr/tesseract-language-packs.html).

Desde `ai-service/`, instalar las dependencias del módulo y de desarrollo:

```powershell
\.venv\Scripts\python.exe -m pip install -e ".[documents,dev]"
\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8100 --reload
```

Configurar un `SERVICE_TOKEN` aleatorio en `ai-service/.env` y copiar el mismo valor a `INTELLIGENCE_SERVICE_TOKEN` en `backend/.env`. Para OCR, `OCR_LANGUAGE=spa+eng`; si Tesseract no detecta su directorio, definir `OCR_TESSDATA_PATH`. Luego, en otra terminal desde `backend/`, arrancar el worker indicado arriba. Los secretos quedan solo en archivos `.env` locales.

## Análisis estructurado en curso

El primer contrato Pydantic está en `ai-service/app/modules/analysis/`. Recibe lotes acotados de páginas extraídas y define resumen, etapa procesal, participantes, delitos referidos por el documento, hechos, pruebas, cronología, información faltante e incertidumbres.

- Cada afirmación encontrada debe incluir página y extracto literal; la aplicación comprueba que la página pertenece al lote y que el extracto existe en su texto tras normalizar espacios.
- La certeza se clasifica como `textual`, `inferido` o `incierto`; no se trata la salida del modelo como confirmación jurídica ni se convierte en culpabilidad.
- Los lotes admiten hasta 10 páginas y 40.000 caracteres. No se envía el expediente completo en una sola solicitud.
- Los campos extra se rechazan y las preguntas de información faltante no se presentan como hechos.

Este contrato todavía no llama a un modelo, persiste análisis ni está expuesto como operación de usuario. El proveedor inicial, el modelo y la política de transferencia de texto jurídico están pendientes; hasta que se decidan, no hay envío de datos a servicios LLM.

RAG, embeddings y recuperación jurídica siguen siendo fases separadas.

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

## RAG futuro

Mantener separadas las colecciones del expediente actual y del conocimiento jurídico validado. Nunca mezclar ambas fuentes sin conservar procedencia, versión y localizador.

Antes de probar con casos reales hace falta acordar consentimiento, anonimización, retención y revisión jurídica de protección de datos. Las pruebas actuales usan únicamente archivos sintéticos.
