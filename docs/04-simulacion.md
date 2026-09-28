# Motor de simulación

Estado: el catálogo de etapas y transiciones está sembrado para medidas cautelares. El avance determinista ya se controla en Laravel: inicia en la única etapa inicial activa, sigue transiciones activas del tipo de audiencia y finaliza al intentar avanzar desde la etapa marcada como final. Aún no existen endpoints de simulación, asignación de turnos por rol, memoria conversacional ni agentes; no se genera texto ni se llama a un modelo.

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

El servicio `App\Services\Simulaciones\AvanzarEtapaAudiencia` es la primera pieza del orquestador. Bloquea la fila de la simulación durante cada avance para evitar transiciones concurrentes. Si una etapa tiene varias salidas activas, exige que quien lo invoque indique una de esas salidas; no permite saltar a etapas arbitrarias. La autorización de propiedad corresponde al controlador que lo invoque y todavía no hay uno.

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
