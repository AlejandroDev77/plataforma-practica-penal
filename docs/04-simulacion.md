# Motor de simulación

Estado: integrado en `develop` mediante PR #9–#11. El catálogo de etapas y transiciones está sembrado para medidas cautelares. La API autenticada permite crear simulaciones a partir de un análisis aprobado, consultar las del expediente propietario, registrar intervenciones de texto y avanzar únicamente por transiciones activas. Los turnos por etapa se configuran por rol, se copian a la simulación al crearla y regulan el registro de intervenciones y el avance. No hay turnos jurídicos precargados: hasta que se incorporen reglas revisadas, las etapas no aceptan intervenciones. No hay agentes ni generación automática; no se llama a un modelo.

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

La tabla `turnos_etapa_audiencia` define una secuencia finita por etapa; cada fila representa una intervención esperada y contiene su orden y rol. Se permiten varias filas para el mismo rol cuando la configuración aprobada contempla más de un turno. Al iniciar una simulación se guarda esa secuencia en `simulaciones.configuracion.turnos_por_etapa`, de modo que cambios posteriores en el catálogo solo afecten simulaciones nuevas. `POST /api/v1/simulaciones/{simulacion}/intervenciones` recibe `contenido` y, opcionalmente, hasta diez IDs distintos en `source_fragment_ids`. El servidor determina rol y participante desde el turno vigente; el cliente no puede enviar el texto citado, el rol ni el propietario. Cada fragmento privado debe pertenecer al expediente de la simulación y, cuando refiere a una página, su extracto debe coincidir con texto de una página legible. Una fuente jurídica debe estar validada y vigente y su índice debe corresponder a la versión actual del texto. La cita guarda el extracto y una instantánea segura de título, archivo/página o datos de versión normativa; la respuesta la devuelve dentro de `interventions[].sources` sin rutas de almacenamiento. Una intervención sin fuentes sigue siendo válida. Esta API registra las referencias recibidas; todavía no busca fragmentos ni genera argumentación. La respuesta incluye `current_turn` con `allowed_actions`; la única acción implementada es `submit_text_intervention`. El avance queda bloqueado mientras existan turnos configurados sin completar. Las etapas sin secuencia configurada conservan el avance de catálogo pero no admiten intervenciones.

No se han cargado secuencias jurídicas iniciales a propósito. Antes de configurar una audiencia, un equipo jurídico debe definir y revisar el orden y los roles para el caso de uso aplicable. Turnos que correspondan a agentes aún no implementados no deben activarse: la fase de generación deberá registrar esos turnos desde el servidor.

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
