# Fase 29 — Comprobación previa de evaluación reglada

## Alcance

Se añade una consulta privada de backend para comprobar los requisitos técnicos de evaluación del acta cerrada. No genera puntajes, no crea evaluaciones ni trabajos de cola, no llama a FastAPI/Ollama/OpenAI y no cambia reglas, rúbricas, revisiones o la base habitual. No incorpora todavía una pantalla de evaluación al circuito nuevo.

La aprobación jurídica de la secuencia de audiencia no acredita una rúbrica académica. El catálogo actual tiene versiones y criterios, pero no un registro verificable de revisión profesional de rúbricas. Por ello `rubric_review_status` siempre es `no_verificable`; `execution_authorized=false` y `allowed_actions=[]`, incluso si `technical_ready=true`. La comprobación no constituye una instantánea persistida ni una reserva: una futura solicitud debe verificar de nuevo y conservar exactamente el contexto autorizado.

## Consulta y privacidad

`GET /api/v1/practicas-regladas/{practica}/preparacion-evaluacion`, Sanctum y `Cache-Control: no-store, private`. No admite parámetros de propietario, rúbrica, transcripción, proveedor o consentimiento, ni ningún otro campo. Devuelve 401 sin sesión, 404 para recursos ajenos/inexistentes y 422 por campos adicionales. Reutiliza el manejo privado de errores del prefijo reglado. No hay POST de ejecución en esta ruta.

El servicio utiliza la comprobación del orquestador dentro de una transacción, conservando sus bloqueos y la propiedad tanto de la práctica como del expediente. Rechaza prácticas anteriores sin vínculo, preparación/actividad y contextos cuya activación, revisión o análisis dejaron de estar vigentes. Exige un movimiento final de cierre, fechas de inicio y fin, etapa coincidente y los turnos de la última etapa completos.

Se devuelve únicamente:

- identificador de práctica y banderas de consulta;
- motivos técnicos independientes de los bloqueos de revisión/integración;
- identificador, versión y cantidad de criterios de la rúbrica candidata, si es compatible;
- cantidades de intervenciones y defensa y recuento conservador de caracteres del contexto.

No se exponen texto del acta, análisis, criterios, extractos, datos personales, rutas de archivo, preparación del proveedor ni huellas internas.

## Comprobaciones de rúbrica y acta

La selección conserva la prioridad del evaluador anterior: coincidencia de rol vale dos puntos y coincidencia de tipo uno. La candidata más específica prevalece sobre genéricas; dos igualmente específicas bloquean, no se desempatan por ID. Solo se leen las dos mejores candidatas y hasta 21 criterios para detectar exceso sobre el máximo de veinte. No se elige una genérica para eludir una candidata más específica inválida.

Versiones positivas, nombres/descripciones no vacíos, órdenes positivos y distintos, pesos positivos finitos, máximos positivos finitos y límites del contrato actual (255 caracteres para nombre de rúbrica/criterio y 2.000 para descripción). No se asigna peso, escala ni aprobación por defecto.

El acta necesita defensa, roles admitidos, hasta cuarenta intervenciones y texto de hasta 2.000 caracteres Unicode por intervención. Las referencias conservadas de defensa deben tener tipo conocido, hasta diez fuentes por intervención, título de hasta 300, localizador de hasta 200 y extracto de hasta 1.000 caracteres. Un extracto mayor bloquea esta preparación; no se recorta silenciosamente. Esto es deliberadamente más conservador que el trabajo anterior, que truncaba sus extractos.

Se limita a 20.000 caracteres la suma de criterios, turnos y referencias de defensa, sin enviar esos textos. Solo se recorren 41 intervenciones para detectar exceso: `intervention_count` es el total exacto; si `complete_count=false`, los otros contadores representan el prefijo observado y no un contexto completo utilizable. No se afirma verificación semántica de las citas ni vigencia actual de cada fuente histórica por poder contarla. La ejecución futura deberá definir esa política y el consentimiento para el contexto concreto.

Una evaluación pendiente/procesando bloquea otra. Error/procesado no bloquean la consulta, pero tampoco conceden permiso de generación. Los cambios del catálogo se vuelven a consultar; esta lectura no congela una versión.

Si no hay una rúbrica compatible y única, el recuento de caracteres incluye solo el acta observada; no representa un contexto válido de evaluación. `complete_count` indica que se recorrió el acta completa, no que el contexto esté autorizado ni profesionalmente revisado.

## Separación del evaluador anterior

`SolicitarEvaluacion` rechaza una simulación con vínculo reglado antes de crear una evaluación o despachar el trabajo. `EvaluarSimulacion` también rechaza vínculos reglados antes de cualquier llamada HTTP, cubriendo entradas de cola antiguas. Las prácticas sin vínculo conservan su evaluación anterior. No se alteran proveedor, clave, cola, esquema de resultados ni frontend antiguo.

## Archivos y ejecución

- Servicio nuevo: `backend/app/Services/Evaluaciones/ConsultarPreparacionEvaluacionReglada.php`.
- Controlador nuevo: `backend/app/Http/Controllers/Api/V1/PreparacionEvaluacionRegladaController.php` y ruta privada en `backend/routes/practicas-regladas.php`.
- Guardias acotadas en `backend/app/Services/Evaluaciones/SolicitarEvaluacion.php` y `backend/app/Jobs/EvaluarSimulacion.php`.
- Pruebas: `backend/tests/Feature/PreparacionEvaluacionRegladaTest.php`.

No hay migraciones, dependencias nuevas ni cambios de `.env`. Las pruebas destructivas utilizan únicamente PostgreSQL `jurissim_pruebas`, con guardia de conexión efectiva/configurada, sin DB_URL ni configuración cacheada. No deshabilitan triggers para preparar el escenario; generan y confirman turnos sintéticos con pasarela simulada y bloquean HTTP inesperado.

## Verificación de entrega

- Rama funcional: 12 pruebas nuevas y 385 aserciones aprobadas.
- Carpeta habitual, con caché de rutas: 40 pruebas y 1.079 aserciones aprobadas, incluyendo la API reglada y las evaluaciones anteriores.
- Pint aprobado en los seis archivos PHP propios; comprobación de whitespace aprobada. Los nueve archivos de la fase coinciden entre ambas carpetas.
- La caché de rutas de prueba fue temporal y separada por proceso, y se retiró al terminar; no se cambió la caché habitual.
- Consulta local PostgreSQL en transacción de solo lectura: un usuario, dos expedientes, dos análisis, una práctica antigua, cero intervenciones, vínculos, borradores, rúbricas y evaluaciones. No se ejecutaron migraciones contra `jurissim` ni se cargaron normas o acreditaciones reales.

Comando de pruebas desde `backend`, con las credenciales del entorno aislado y `DB_DATABASE=jurissim_pruebas`, `DB_URL` vacío y sin configuración cacheada: `vendor/bin/phpunit --filter='PreparacionEvaluacionRegladaTest|PracticaRegladaApiTest|EvaluacionSimulacionTest|EvaluarSimulacionJobTest'`. La consulta privada no consume saldo ni acredita calidad jurídica. Trabajo local, sin push ni merge a ramas principales.

## Siguiente paso recomendado

Definir la revisión trazable de rúbricas por un profesional acreditado y la preparación inmutable del contexto de evaluación antes de conectar la ejecución y su interfaz. No transformar la bandera `activo` ni la aprobación de reglas de audiencia en una aprobación de rúbrica. La creación y aceptación real de prácticas bolivianas siguen pendientes de revisión competente; este diagnóstico no elimina esa condición.
