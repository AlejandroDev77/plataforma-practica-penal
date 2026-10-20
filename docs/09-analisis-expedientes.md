# Análisis estructurado de expedientes

## Estado

El contrato, la validación de procedencia, la persistencia versionada, la revisión humana y la selección de proveedor están implementados. El ejemplo local selecciona OpenAI para análisis; cada envío requiere autorización explícita y solo transmite las páginas seleccionadas. Ollama sigue disponible como opción local. La prueba de integración de Laravel debe ejecutarse únicamente contra la base aislada `jurissim_pruebas`; no debe apuntarse a la base habitual `jurissim`.

## Contrato de entrada

FastAPI recibe únicamente páginas extraídas que Laravel haya seleccionado para un lote. Cada entrada incluye los identificadores internos de archivo/página, el localizador y el texto de esa página. No incluye cuenta, correo, propietario ni ruta de almacenamiento.

- hasta 10 páginas por lote;
- hasta 40.000 caracteres en total;
- identificadores de página únicos en cada lote;
- campos inesperados rechazados por Pydantic.

Estos topes evitan mandar un expediente entero en una sola solicitud. Aumentarlos requiere evaluar de nuevo el modelo, su contexto y los tiempos de la cola local.

## Salida estructurada

El esquema versión `1.0` contiene resumen, etapa procesal, participantes, delitos tal como aparecen referidos, hechos, pruebas, cronología, preguntas sobre información faltante e incertidumbres. Las fechas conservan su forma original cuando no se puede establecer una fecha inequívoca.

Cada afirmación encontrada contiene:

- certeza descriptiva: `textual`, `inferido` o `incierto`;
- una o más referencias a páginas recibidas en el lote;
- un extracto literal que permite localizar y revisar la afirmación.

`Textual` describe lo que el documento afirma, no confirma que el hecho sea verdadero. Las declaraciones y alegaciones deben atribuirse a su emisor; `incierto` se reserva para fuentes ambiguas, contradictorias o ilegibles.

La comprobación de procedencia rechaza tanto páginas ajenas al lote como extractos que no aparezcan en la página citada (ignorando diferencias de espacios y mayúsculas). No demuestra por sí sola que una interpretación jurídica sea correcta; el resultado permanece como información no confirmada, sujeta a revisión humana.

Los vacíos se expresan como preguntas y su relevancia, no como respuestas inferidas. Las salidas no determinan culpabilidad ni sustituyen asesoramiento o revisión de un profesional.

## Modelo local en pruebas

Ollama se activa solo con `LLM_PROVIDER=ollama` y `LLM_MODEL` configurado; la opción predeterminada sigue siendo `LLM_PROVIDER=disabled`. La URL solo admite `http://127.0.0.1:11434`, el endpoint requiere el token interno y no registra el texto recibido. La integración solicita salida estructurada sin razonamiento extendido, valida el JSON conforme al contrato y vuelve a verificar las citas contra las páginas fuente antes de devolver el resultado. El contenido de las páginas se considera no confiable para ignorar instrucciones embebidas en el propio expediente.

La prueba sintética inicial de `qwen3.5:2b-q4_K_M` en el servicio devolvió tres hallazgos en español y citas coincidentes. Después se probó el flujo completo con una página sintética de 96 caracteres: Laravel la envió a la cola, Ollama respondió en loopback en 21 segundos y el resultado quedó guardado con una cita verificable y estado `No confirmado`. Esto comprueba el recorrido técnico con una entrada pequeña, no la calidad jurídica ni el comportamiento con lotes grandes; los resultados aún requieren revisión profesional. En Windows, Ollama debe ejecutarse con `OLLAMA_NO_CLOUD=1` y enlazado a loopback. El modelo no determina culpabilidad ni sustituye revisión jurídica.

La regresión optativa `tests/test_analysis_local_acceptance.py` evalúa un caso sintético inédito y comprueba resumen, hechos, ausencia de delitos inventados, vacíos y citas. Se ejecuta con `JURISSIM_EJECUTAR_OLLAMA_LOCAL=1` y `OLLAMA_NO_CLOUD=1`; nunca usa expedientes reales ni un proveedor externo. En la primera evaluación de `qwen3.5:2b-q4_K_M`, el modelo omitió el resumen y los hechos y generó un extracto con puntos suspensivos; el servicio rechazó el resultado. Se reforzó el prompt, se hizo `summary` obligatorio solo en el esquema enviado a Ollama y se añadió el error seguro `local_model_incomplete_analysis`. En la siguiente evaluación, el modelo produjo resumen y hechos y no inventó delitos, pero omitió la fecha central. Se añadió un complemento determinista que conserva en cronología las fechas explícitas en español o ISO junto con la oración literal de origen; respeta el máximo de 150 eventos y vuelve a validar la cita contra la página. Tras este ajuste, la regresión del caso sintético inédito pasó en 34 segundos. Es una prueba de recorrido técnico con una muestra, no demuestra cobertura de expedientes variados ni calidad jurídica; hay que ampliar la batería sintética y mantener la revisión humana.

Para repetir la evaluación desde PowerShell:

```powershell
cd ai-service
$env:OLLAMA_NO_CLOUD = "1"
$env:JURISSIM_EJECUTAR_OLLAMA_LOCAL = "1"
.\.venv\Scripts\python.exe -m pytest -q -p no:cacheprovider tests\test_analysis_local_acceptance.py
```

El contrato se prueba sin credenciales ni servicios externos:

```powershell
cd ai-service
.\.venv\Scripts\python.exe -m pytest -q -p no:cacheprovider
```

## Proveedores de análisis y consentimiento externo

`ANALYSIS_PROVIDER=disabled|ollama|openai` selecciona el proveedor del análisis sin cambiar otros flujos. Por defecto usa OpenAI; se puede elegir Ollama de forma explícita. `OPENAI_MODEL` toma `gpt-4o-mini` si no se configura. La clave permanece en `ai-service/.env`, nunca en el navegador ni en Git. OpenAI usa la API Responses con JSON Schema estricto, máximo de 4.096 tokens y `store=false`; esta opción no equivale a retención cero del proveedor.

Antes de cada solicitud, la persona debe marcar una casilla que autoriza enviar únicamente las páginas seleccionadas. Laravel conserva esa decisión en los metadatos del proceso y la transmite al servicio interno; FastAPI rechaza OpenAI con `403` si falta. La autorización es falsa por defecto y solo aplica a esa solicitud. El proveedor externo recibe texto seleccionado y referencias temporales, no IDs internos, nombres de archivo, localizadores, cuentas, propietarios ni rutas privadas. Las citas se remapean a páginas locales y se cotejan literalmente antes de persistir. El job tiene un intento para evitar duplicar llamadas facturables; no hay fallback automático.

Las pruebas usan `MockTransport` y bloquean HTTP real. No se hicieron solicitudes a OpenAI durante el desarrollo ni la verificación.

## Persistencia interna y consulta

Cuando un productor interno autorizado ya tenga una salida estructurada, puede entregarla mediante `POST /api/internal/v1/expedientes/{id}/analisis` con el token Bearer compartido. Este endpoint solo valida y persiste; no llama ni elige ningún modelo. Laravel vuelve a verificar que todas las páginas sean legibles, pertenezcan al expediente indicado, no excedan 10 páginas/40.000 caracteres y que cada extracto coincida con el texto almacenado. Una referencia que falle cancela la transacción completa.

Cada resultado crea una nueva versión en `analisis_expediente`, sus elementos normalizados, las referencias tipadas y una fila de `historial_procesamiento`. Las certezas permanecen cualitativas; no se convierten en porcentajes. Los elementos comienzan sin confirmar. El endpoint interno de persistencia no elige proveedor y deja `modelo_ia` nulo; el job registra el modelo utilizado en `analisis_expediente.modelo_ia` y el proveedor/modelo en los metadatos del proceso.

El propietario consulta la versión más reciente con `GET /api/v1/expedientes/{id}/analisis`. Solo recibe citas con nombre del documento, localizador y extracto cuando la página sigue disponible y el texto coincide; los IDs internos de página no se exponen. La ruta está dentro de la sesión Sanctum y el ámbito del expediente del usuario.

El propietario inicia un análisis indicando las páginas concretas que quiere incluir. En el detalle administrativo, solo se pueden marcar páginas legibles; la interfaz muestra el recuento de páginas y caracteres, el avance del proceso y permite reintentar sin exponer el texto en el almacenamiento de sesión:

```http
POST /api/v1/expedientes/{id}/analisis
Content-Type: application/json

{"page_ids": [123, 124]}
```

La API comprueba que todas las páginas pertenecen al expediente autenticado, sean legibles y no superen 10 páginas ni 40.000 caracteres. Se responde `202 Accepted` con un `process_id`; `GET /api/v1/expedientes/{id}/analisis/procesos/{process_id}` informa si está pendiente, procesándose, completado o falló. No se aceptan IDs de propietario del cliente y un expediente no puede tener dos análisis simultáneos.

El job envía al servicio de inteligencia únicamente el texto de las páginas seleccionadas, nunca datos de cuenta ni rutas privadas. Laravel solo acepta el servicio HTTP de loopback en el puerto `8100` (`localhost`, `127.0.0.1` o `::1`) y no sigue redirecciones; el servicio requiere el token compartido y los textos no se escriben en logs. FastAPI devuelve el proveedor/modelo empleado en cabeceras internas; Laravel admite `ollama` y `openai` y persiste esos datos junto con el resultado validado en la misma fila de historial que abrió la solicitud. El worker local se ejecuta con `php artisan queue:work --queue=default --timeout=600`.

La interfaz administrativa del detalle permite registrar `aprobado` o `requiere_cambios`; solicitar cambios exige observación. La aprobación solo se habilita con al menos una cita vigente y todas verificables. Cada decisión añade una fila en `revisiones_analisis`; no se sobrescribe el historial.

Al retirar un archivo, también se eliminan las versiones de análisis que lo citan junto con sus referencias, hallazgos, historial y revisiones. Así no persisten extractos o resultados derivados del archivo eliminado. Si una página se vuelve ilegible sin retirar el archivo, la cita se marca no disponible y no puede aprobarse el análisis.

Las pruebas HTTP de Laravel están en `backend/tests/Feature/Api/AnalisisExpedienteTest.php`. `phpunit.xml` fuerza PostgreSQL y la base `jurissim_pruebas`; la prueba principal además valida que no se use otra base ni `DB_URL`. Mantener esa separación al ejecutar `php artisan test` y no crear datos de prueba en `jurissim`.
