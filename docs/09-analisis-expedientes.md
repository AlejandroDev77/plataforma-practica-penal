# Análisis estructurado de expedientes

## Estado

En desarrollo. El contrato y la validación de procedencia están implementados; no hay proveedor/modelo elegido, llamada a LLM, job de análisis ni escritura a `analisis_expediente`.

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

La comprobación de procedencia rechaza tanto páginas ajenas al lote como extractos que no aparezcan en la página citada (ignorando diferencias de espacios y mayúsculas). No demuestra por sí sola que una interpretación jurídica sea correcta; el resultado permanece como información no confirmada, sujeta a revisión humana.

Los vacíos se expresan como preguntas y su relevancia, no como respuestas inferidas. Las salidas no determinan culpabilidad ni sustituyen asesoramiento o revisión de un profesional.

## Decisión pendiente antes de usar un modelo

La arquitectura aún no ha seleccionado proveedor, modelo, endpoint ni política de transferencia de datos. Por tanto, esta fase no envía texto a terceros. Antes de activar generación se debe registrar esa decisión, configurar secretos localmente, definir el tratamiento de expedientes sensibles y validar la salida con documentos sintéticos.

El contrato se prueba sin credenciales ni servicios externos:

```powershell
cd ai-service
.\.venv\Scripts\python.exe -m pytest -q -p no:cacheprovider
```
