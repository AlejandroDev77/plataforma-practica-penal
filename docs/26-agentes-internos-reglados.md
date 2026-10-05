# Propuestas y confirmación interna de agentes reglados

Continúa el [registro de defensa](25-registro-interno-defensa.md). Esta fase añade borradores internos de juez y fiscal para prácticas vinculadas a una versión revisada. Permite probar el recorrido completo con respuestas controladas, sin alterar turnos o reglas en el navegador. No conecta aún API pública, orquestador ni interfaz de las prácticas nuevas; no crea acreditaciones, revisiones o normativa boliviana real.

## Tres momentos separados

1. Preparar: comprobar propiedad, práctica activa, último movimiento, etapa, propuesta y orden esperados; revalidar reglas, análisis, participante e historial bajo los bloqueos de las fases 24–25.
2. Generar: consultar capacidad del proveedor y solicitar la propuesta fuera de cualquier transacción. No mantener bloqueos de dominio durante una llamada de red.
3. Guardar y confirmar: revalidar al guardar el borrador y nuevamente al confirmar su incorporación al acta. Generar no consume un turno. Confirmar exige una revisión humana explícita.

`ProponerAgenteReglado::ejecutar(actor, idSimulacion, idMovimiento, idEtapa, idTurno, orden, idsFragmento=[], autorizarExterno=false)` recibe actor autenticado, precondiciones exactas y una selección de hasta ocho fragmentos. `PrepararAgenteReglado` es su caso de uso transaccional, no una autorización reutilizable. `ConfirmarAgenteReglado::ejecutar(actor, idSimulacion, idBorrador, confirmacionHumana)` confirma el texto original; no admite contenido, rol, modelo, propietario, participante o citas sustituidos por el cliente.

La secuencia y las instrucciones proceden de la copia sellada. El participante del rol pendiente debe ser único, activo y controlado por IA. Un turno de defensa, práctica antigua sin vínculo, precondición obsoleta o historia incoherente no invoca al proveedor. Los movimientos siguen siendo explícitos: confirmar no avanza automáticamente de etapa.

## Selección y envío

El contexto enviado contiene IDs técnicos, rol, nombre de etapa sellado, instrucción y extractos de la selección explícita. No incluye el análisis completo, hechos reconstruidos automáticamente, historial de conversación, rutas de almacenamiento ni páginas adicionales. `visible_facts` y `transcript` quedan vacíos en este hito para no ampliar silenciosamente el envío autorizado. Ampliar ese contexto exige diseñar su consentimiento y revisión previa en la integración pública.

Los fragmentos se verifican con la función SQL de fase 25, incluyendo página legible/propiedad o norma validada vigente en Bolivia, correspondencia de citas y huellas de indexación. El registro privado del borrador conserva el fragmento y los metadatos completos seleccionados; el proveedor recibe únicamente su primer extracto de hasta 1.500 caracteres. El acta conserva exactamente ese extracto enviado, nunca el final del fragmento que el modelo no vio. La pantalla de revisión futura deberá mostrar los extractos efectivos y distinguirlos del documento completo.

Hasta ocho fuentes, títulos de 300 caracteres y localizadores de 200; sin hechos ni historial, el contexto queda dentro del límite de 20.000 caracteres del contrato FastAPI. La instrucción se conserva íntegra: si supera 1.500 caracteres, el comando se bloquea, no recorta reglas aprobadas. La respuesta se limita a 2.000 caracteres para ser compatible con el registro de intervenciones de esta fase; una respuesta mayor se rechaza aunque otros flujos antiguos admitan 3.000.

La pasarela nueva usa la configuración existente `services.intelligence` y exige `GET /api/v1/simulations/provider`: proveedor ollama/openai, modelo y capacidad de autorización. No hay fallback automático si FastAPI no expone esta capacidad o está deshabilitado. La carpeta habitual ya dispone del contrato extendido; instalaciones que solo tengan el servicio antiguo deben integrarlo antes de habilitar este flujo públicamente. No se sustituyeron sus archivos locales ni los contratos antiguos.

OpenAI requiere autorización explícita para ese contexto; por defecto está desautorizado. Se propaga `allow_external_processing` a FastAPI, que también debe respetarlo si cambia el proveedor durante la solicitud. La respuesta debe coincidir con proveedor/modelo consultados. Un fallo de conexión/HTTP se transforma en mensaje fijo sin cuerpo sensible de respuesta. Mantener deshabilitadas las funciones en nube de un proveedor que se presente como local. Esta entrega no verifica una llamada real a Ollama/OpenAI ni sus costes.

## Borradores e incorporación auditada

`borradores_agentes_reglados` es inmutable. Guarda responsable, movimiento/etapa/propuesta/orden exactos, selección, preparación, respuesta, proveedor/modelo, autorización y fechas PostgreSQL. Vence diez minutos después de su registro, según el reloj del servidor. Si el contexto cambió mientras se generaba, no se guarda el borrador; la llamada anterior puede haber consumido recursos del proveedor y no se reintenta automáticamente.

La preparación conserva copias verificables y huellas de análisis/historial, última revisión de análisis y contexto del participante. Cualquier cambio relevante al confirmar bloquea la incorporación, incluso otra revisión aprobada del análisis o un cambio de versión de la fuente seleccionada. Las reglas retiradas, sustituidas o vencidas se revalidan mediante el contexto bloqueado común.

La respuesta acepta solo rol esperado, texto, IDs de fuentes seleccionadas y `requires_human_review=true`. No se aceptan campos extra, fuentes desconocidas/repetidas ni rol diferente. Se conservan todos los fragmentos seleccionados en el borrador y solo las fuentes declaradas utilizadas en el acta. IDs correctos no certifican que el argumento sea verdadero o jurídicamente correcto: el usuario debe revisar texto y citas.

Una confirmación crea un único registro de fase 25 con vínculo al borrador y revisión humana explícita. PostgreSQL deriva contenido, autor, participante, modelo/proveedor, fecha y fuentes, e inserta acta/citas atómicamente. La transcripción conserva revisión y procedencia declaradas por el servicio; no es una firma criptográfica del proveedor ni una certificación profesional.

La repetición del mismo borrador devuelve la intervención ya incorporada sin duplicar ni consumir otro turno, tras revalidar propiedad/dependencias. Dos borradores del mismo turno no pueden incorporarse ambos. Ningún cliente puede modificar el registro, transcripción, citas o identidad/rol/control auditados. La creación directa de intervenciones vinculadas sigue bloqueada. El flujo de defensa anterior conserva su comportamiento y límites.

## Migración y límites operativos

`2026_10_04_000005_confirmar_agentes_reglados.php` añade el registro de borradores y dos campos a los registros de intervención. Instalar solo esta ruta, tras comprobar base habitual local y migración de defensa aplicada:

```powershell
php artisan migrate --path=database/migrations/2026_10_04_000005_confirmar_agentes_reglados.php
```

No usar `migrate:fresh` en `jurissim`. El rollback se niega incluso si hay borradores sin confirmar, preservando el historial. Vacío, restaura íntegramente la capa de defensa anterior. Cinco pruebas de rollback antiguas retiran esta dependencia antes de sus propias migraciones y la reinstalan al final. Privilegios DDL del dueño de PostgreSQL no quedan limitados por estos guardias.

La suite nueva necesita transacciones reales con llamadas fuera de ellas: no manipula contadores internos ni simula un commit mediante savepoints. Reconstruye y limpia exclusivamente `jurissim_pruebas`, comprobando conexión PostgreSQL configurada y efectiva, ausencia de DB_URL/cache antes de cada acción destructiva. Usa pasarela controlada y HTTP falso. Solo la prueba de vencimiento altera fechas sintéticas mediante DDL temporal, restaurando el trigger inmediatamente; el recorrido completo juez/fiscal/defensa no omite protecciones. No se afirma una prueba de carga concurrente ni una validación profesional de un procedimiento real.

## Siguiente paso

La conexión pública de comandos y la orquestación privada se documentan en [fase 27](27-api-practicas-regladas.md). La interfaz continúa como fase posterior.

Exponer creación, consulta de la copia revisada, movimientos, selección/consentimiento, revisión de borradores y defensa mediante API y orquestador, con respuestas privadas y precondiciones explícitas. Luego conectar la interfaz y probar aceptación integral. El diagnóstico de fase 23 no cambia a ejecución autorizada mientras falte esa integración pública.


## Verificación de la entrega

El 4 de octubre de 2026 pasaron 370 pruebas y 2.180 aserciones en la suite completa de la rama, incluyendo 31 pruebas nuevas de agentes (240 aserciones adicionales). En la carpeta habitual pasaron 210 pruebas seleccionadas de agentes nuevos, propuestas antiguas, defensa, movimientos, versiones, revisión, activación, vínculos, contrato y orquestación, con 1.149 aserciones. Pint y revisión de espacios correctos. La comprobación inicial de sintaxis detectó una indentación de heredoc, corregida antes de ejecutar las migraciones de pruebas. Se verificó además la proyección de fragmentos de 1.600 caracteres sin rechazar el formato normal del índice ni atribuir al modelo texto que no recibió.

Se instaló únicamente la migración de esta fase en `jurissim`, comprobando PostgreSQL, servidor efectivo `127.0.0.1`, ausencia de DB_URL/configuración cacheada y migración de defensa aplicada. Los dieciséis conteos anteriores de dominio y registros jurídicos permanecieron iguales; el registro nuevo de borradores está vacío. No se crearon aprobaciones, activaciones, prácticas, borradores o intervenciones reales. Frontend, Laravel y FastAPI respondieron HTTP 200 después de la instalación. No se hicieron llamadas generativas ni se consumió saldo de proveedores.

Archivos propios: migración `2026_10_04_000005_confirmar_agentes_reglados.php`, modelo `BorradorAgenteReglado`, contrato `AgenteRegladoGateway`, adaptador `FastApiAgenteRegladoGateway`, servicios `PrepararAgenteReglado`, `ProponerAgenteReglado` y `ConfirmarAgenteReglado`, prueba `AgenteRegladoTest`, cinco ajustes de rollback en pruebas anteriores, este documento y enlace desde fase 25. Se trasladaron solo esos quince archivos tras comprobar originales y ausencia de cambios concurrentes. Se conservaron pasarelas, servicios, contratos, FastAPI, rutas, interfaz y configuración ajenos. Trabajo en la rama local existente de versiones, con develop actualizado; sin push, PR, merge a develop/main ni cambios a protecciones.
