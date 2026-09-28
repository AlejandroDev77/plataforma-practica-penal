# Motor de simulación

Estado: el catálogo de etapas y transiciones está sembrado para medidas cautelares. La API autenticada permite crear simulaciones a partir de un análisis aprobado, consultar las del expediente propietario y avanzar únicamente por transiciones activas. Cada simulación inicia en su etapa inicial configurada y termina al avanzar desde su etapa final. Aún no existen turnos configurables por rol, memoria conversacional ni agentes; no se genera texto ni se llama a un modelo.

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

La API `/api/v1` expone tipos de audiencia activos y permite crear, listar, consultar y avanzar simulaciones. Todas estas rutas requieren `auth:sanctum`; las consultas se limitan a los expedientes y simulaciones del usuario autenticado. La creación solo acepta análisis cuya última revisión humana sea `aprobado` y asigna al usuario el rol `abogado_defensor`. No crea participantes de IA ni genera intervenciones. Si una etapa admite varios destinos, la respuesta incluye los destinos configurados y el cliente debe enviar `id_etapa_destino`.

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

## Generación
Cada intervención debe recibir:
- rol;
- estado;
- últimos mensajes relevantes;
- hechos recuperados;
- normas recuperadas;
- reglas de actuación.

Evitar pasar toda la conversación si no es necesario.
