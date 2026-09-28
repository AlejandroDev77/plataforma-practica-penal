# Análisis estructurado de expedientes

## Estado

El contrato, la validación de procedencia, la persistencia versionada y la revisión humana están implementados. Se acordó probar primero con un modelo local; el endpoint Ollama está desactivado por defecto. Todavía no existe un job de Laravel que genere análisis ni se conecta un proveedor externo. La suite Laravel de esta fase se ejecutó con éxito en la base aislada `jurissim_pruebas` (56 pruebas, 357 aserciones); no debe apuntarse a la base habitual `jurissim`.

## Contrato de entrada

FastAPI recibe únicamente páginas extraídas que Laravel haya seleccionado para un lote. Cada entrada incluye los identificadores internos de archivo/página, el localizador y el texto de esa página. No incluye cuenta, correo, propietario ni ruta de almacenamiento.

- hasta 10 páginas por lote;
- hasta 40.000 caracteres en total;
- identificadores de página únicos en cada lote;
- campos inesperados rechazados por Pydantic.

Estos topes evitan mandar un expediente entero en una sola solicitud. Se revisarán junto al modelo/contexto seleccionado antes de habilitar la ejecución real.

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

La primera prueba local de `qwen3.5:2b-q4_K_M` usó una página sintética y devolvió HTTP 200 con tres hallazgos en español; las tres citas coincidieron con la fuente y la certeza fue `textual`. La salida de razonamiento extendido se desactivó después de que una llamada inicial agotara el timeout. Esto solo valida la conexión y el contrato con un texto pequeño; falta evaluar lotes mayores y la calidad de extracción, implementar el job de análisis por cola en Laravel y revisar resultados con una persona. El modelo no determina culpabilidad ni sustituye revisión jurídica. Cualquier proveedor externo queda fuera de esta fase y requerirá una decisión separada.

El contrato se prueba sin credenciales ni servicios externos:

```powershell
cd ai-service
.\.venv\Scripts\python.exe -m pytest -q -p no:cacheprovider
```

## Persistencia interna y consulta

Cuando un productor interno autorizado ya tenga una salida estructurada, puede entregarla mediante `POST /api/internal/v1/expedientes/{id}/analisis` con el token Bearer compartido. Este endpoint solo valida y persiste; no llama ni elige ningún modelo. Laravel vuelve a verificar que todas las páginas sean legibles, pertenezcan al expediente indicado, no excedan 10 páginas/40.000 caracteres y que cada extracto coincida con el texto almacenado. Una referencia que falle cancela la transacción completa.

Cada resultado crea una nueva versión en `analisis_expediente`, sus elementos normalizados, las referencias tipadas y una fila de `historial_procesamiento`. Las certezas permanecen cualitativas; no se convierten en porcentajes. Los elementos comienzan sin confirmar y `modelo_ia` queda nulo mientras no se configure un proveedor.

El propietario consulta la versión más reciente con `GET /api/v1/expedientes/{id}/analisis`. Solo recibe citas con nombre del documento, localizador y extracto cuando la página sigue disponible y el texto coincide; los IDs internos de página no se exponen. La ruta está dentro de la sesión Sanctum y el ámbito del expediente del usuario.

La interfaz administrativa del detalle permite registrar `aprobado` o `requiere_cambios`; solicitar cambios exige observación. La aprobación solo se habilita con al menos una cita vigente y todas verificables. Cada decisión añade una fila en `revisiones_analisis`; no se sobrescribe el historial.

Al retirar un archivo, también se eliminan las versiones de análisis que lo citan junto con sus referencias, hallazgos, historial y revisiones. Así no persisten extractos o resultados derivados del archivo eliminado. Si una página se vuelve ilegible sin retirar el archivo, la cita se marca no disponible y no puede aprobarse el análisis.

Las pruebas HTTP de Laravel están en `backend/tests/Feature/Api/AnalisisExpedienteTest.php`. `phpunit.xml` fuerza PostgreSQL y la base `jurissim_pruebas`; la prueba principal además valida que no se use otra base ni `DB_URL`. Mantener esa separación al ejecutar `php artisan test` y no crear datos de prueba en `jurissim`.
