# Motor de simulación

Estado: el motor de audiencia y sus turnos configurables están integrados mediante PR #9–#11; la persistencia de fuentes por intervención, mediante PR #13; y la búsqueda RAG separada para el expediente y fuentes jurídicas, mediante PR #14. El catálogo de etapas y transiciones está sembrado para medidas cautelares. La API autenticada permite crear simulaciones a partir de un análisis aprobado, consultar las del expediente propietario, registrar intervenciones de texto y avanzar únicamente por transiciones activas. No hay turnos jurídicos precargados: hasta que se incorporen reglas revisadas, las etapas no aceptan intervenciones. Se implementa por separado un servicio interno para proponer texto con Ollama local; sigue desactivado por defecto y todavía no está conectado al orquestador ni persiste intervenciones.

## Objetivo
Controlar la audiencia; el LLM NO controla el flujo completo.

## Componentes

```text
CaseContext
LegalRAG
HearingRules
ConversationMemory
        |
        v
HearingOrchestrator
        |
        +--> JudgeAgent
        +--> ProsecutorAgent
        +--> DefenseAgent
```

## Orquestador
Debe conocer:
- tipo de audiencia;
- estado actual;
- participante activo;
- siguiente turno;
- acciones válidas;
- contexto del expediente;
- historial.

El servicio `App\Services\Simulaciones\AvanzarEtapaAudiencia` es la primera pieza del orquestador. Bloquea la fila de la simulación durante cada avance para evitar transiciones concurrentes. Si una etapa tiene varias salidas activas, exige que quien lo invoque indique una de esas salidas; no permite saltar a etapas arbitrarias.

La API `/api/v1` expone tipos de audiencia activos y permite crear, listar, consultar, registrar intervenciones del usuario y avanzar simulaciones. Todas estas rutas requieren `auth:sanctum`; las consultas se limitan a los expedientes y simulaciones del usuario autenticado. La creación solo acepta análisis cuya última revisión humana sea `aprobado` y asigna al usuario el rol `abogado_defensor`. No crea participantes de IA ni genera intervenciones automáticas. Si una etapa admite varios destinos, la respuesta incluye los destinos configurados y el cliente debe enviar `id_etapa_destino`.

La tabla `turnos_etapa_audiencia` define una secuencia finita por etapa; cada fila representa una intervención esperada y contiene su orden y rol. Se permiten varias filas para el mismo rol cuando la configuración aprobada contempla más de un turno. Al iniciar una simulación se guarda esa secuencia en `simulaciones.configuracion.turnos_por_etapa`, de modo que cambios posteriores en el catálogo solo afecten simulaciones nuevas. `POST /api/v1/simulaciones/{simulacion}/intervenciones` recibe `contenido` y, opcionalmente, hasta diez IDs distintos en `source_fragment_ids`. El servidor determina rol y participante desde el turno vigente; el cliente no puede enviar el texto citado, el rol ni el propietario. Cada fragmento privado debe pertenecer al expediente de la simulación, apuntar a una página legible y coincidir con su texto extraído; se admiten archivos procesados o con extracción parcial si la página citada es legible. Una fuente jurídica debe estar validada y vigente y su índice debe corresponder a la versión actual del texto. La cita guarda el extracto y una instantánea segura de título, archivo/página o datos de versión normativa; la respuesta la devuelve dentro de `interventions[].sources` sin rutas de almacenamiento. Una intervención sin fuentes sigue siendo válida. El endpoint de intervenciones registra las referencias recibidas, pero no busca fragmentos ni genera argumentación. La respuesta incluye `current_turn` con `allowed_actions`; la única acción implementada es `submit_text_intervention`. El avance queda bloqueado mientras existan turnos configurados sin completar. Las etapas sin secuencia configurada conservan el avance de catálogo pero no admiten intervenciones.

`POST /api/v1/simulaciones/{simulacion}/fuentes` busca candidatas en dos colecciones separadas: fragmentos del expediente vinculado a esa simulación y fragmentos jurídicos validados cuya vigencia y huella de contenido siguen actuales. La autorización se resuelve desde la simulación del usuario autenticado; no se acepta un expediente indicado por el cliente. La respuesta devuelve extractos literales, IDs y localizadores para adjuntarlos en una intervención mediante `source_fragment_ids`. La puntuación solo ordena coincidencias y no representa certeza jurídica; la ruta no genera texto ni llama a modelos.

No se han cargado secuencias jurídicas iniciales a propósito. Antes de configurar una audiencia, un equipo jurídico debe definir y revisar el orden y los roles para el caso de uso aplicable. Turnos que correspondan a agentes aún no implementados no deben activarse: la fase de generación deberá registrar esos turnos desde el servidor.

La consola administrativa puede conservar propuestas de turnos en `propuestas_turnos_audiencia`, separadas de `turnos_etapa_audiencia`. Solo `administrador_plataforma` puede consultarlas o prepararlas; este permiso es técnico y no representa competencia jurídica. El endpoint fija el estado en `borrador`, ignora campos de estado/autor enviados por el cliente y no ofrece una operación de aprobación ni activación. Estas propuestas no se incluyen en instantáneas ni alteran simulaciones. No se precarga contenido jurídico. Definir quién revisará y aprobará reglas, y cómo una regla aprobada llegará al catálogo activo, queda pendiente de decisión y revisión profesional.

## Estado MVP

```text
OPENING
-> IDENTIFICATION
-> PROSECUTION_ARGUMENTS
-> DEFENSE_ARGUMENTS
-> DEBATE
-> DECISION
-> CLOSED
```

El equipo jurídico puede ajustar etapas y transiciones en los catálogos. La secuencia sembrada para medidas cautelares es una configuración inicial del producto, no una certificación jurídica de la secuencia procesal.

## Regla
Un agente NO puede:
- saltarse etapas;
- inventar prueba;
- actuar fuera de su rol;
- citar normas inexistentes;
- decidir por otro participante.

## Memoria
Guardar cada intervención:

```text
order
actor
role
content
timestamp
sources[]
```

El registro de fuentes usa la tabla existente `fuentes_intervencion`; las referencias quedan unidas a los fragmentos y conservan el extracto y sus datos de citación tal como se verificaron al registrar la intervención. El buscador RAG aún no se integra en esta fase.

## Generación
Cada intervención debe recibir:
- rol;
- estado;
- últimos mensajes relevantes;
- hechos recuperados;
- normas recuperadas;
- reglas de actuación.

Evitar pasar toda la conversación si no es necesario.

### Propuesta local aislada

POST /api/v1/simulations/proposals ofrece una pieza interna para proponer una respuesta breve de juez o fiscal. Requiere el token Bearer compartido con FastAPI; acepta solo esos roles de agente, un turno acotado, hasta 20 afirmaciones con tipo, certeza, atribución y fuentes, 8 extractos fuente y 12 mensajes de transcripción, con límite total de contexto. Ollama debe estar habilitado explícitamente con LLM_PROVIDER=ollama y usar la URL de loopback ya validada por configuración; el valor predeterminado continúa desactivado. La respuesta incluye el rol, el texto propuesto, las IDs de fuente recibidas y el indicador obligatorio requires_human_review=true. Se rechazan los roles distintos al solicitado y las referencias fuente que el servicio no recibió. Estos controles de formato y procedencia no demuestran que todas las afirmaciones generadas sean correctas.

El endpoint no consulta la base de datos, no recibe texto completo de expedientes por sí mismo, no guarda ni registra intervenciones y no altera etapas. La futura integración Laravel debe obtener rol, instrucción de turno, hechos, fuentes y transcripción desde recursos autorizados del servidor; nunca debe aceptar esos datos como autoridad desde el navegador. La instrucción de turno debe provenir de una regla activa revisada. Mientras no existan turnos activos revisados, no hay solicitudes válidas desde el orquestador. El texto generado es una propuesta educativa, no decisión ni asesoramiento jurídico; debe mantenerse bajo revisión humana y no inventar hechos o normas.
