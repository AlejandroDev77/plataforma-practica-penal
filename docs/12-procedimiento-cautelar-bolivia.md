# Procedimiento cautelar boliviano — matriz de trabajo

## Estado y alcance

Borrador documental, versión 1.0, investigado el 1 de octubre de 2026. No es una regla activa, dictamen jurídico ni certificación de vigencia normativa. No autoriza cargar turnos en PostgreSQL.

Primer escenario propuesto: audiencia inicial de medidas cautelares personales, en etapa preparatoria, con solicitud fiscal y defensa del imputado. No comprende cesación, ampliación, revocatoria, juicio, apelación ni procedimientos especiales. No describe todos los procedimientos de un juzgado boliviano.

Verificar jurisdicción y procedencia del expediente. Un documento extranjero no convierte sus normas o actos en bolivianos; cualquier adaptación académica debe quedar declarada y revisada, sin reemplazo automático de citas.

El [contrato documental](contratos/medidas-cautelares-bolivia.borrador.json) conserva identificadores, dependencias y bloqueos para preparar el orquestador. No lo consume la aplicación; no se debe importar a los catálogos existentes.

## Fuentes y límites de la investigación

| ID | Fuente oficial y localizador | Uso y limitación |
| --- | --- | --- |
| `tsj-cautelares-2017` | [TSJ, Protocolo de Dirección de Audiencias de Medidas Cautelares](https://tsj.bo/wp-content/uploads/2019/11/protocolo-de-direcci%C3%B3n-de-audiencias-de-medidas-cautelares-min.pdf), capítulo 5, páginas impresas 47–64 | Estructura orientativa histórica. No tomar su texto legal como consolidación actual. |
| `eje-cautelares-2021` | [Escuela de Jueces, material de actualización presencial y virtual](https://backend.academico.eje.gob.bo/files/recursos/PROTOCOLO_DE_DIRECCION_DE_AUDIENCIA_DE_MEDIDAS_CAUTELARES_1.pdf), láminas 13, 15, 28, 40–42 | Formación de noviembre de 2021; principios y límites, no garantía de vigencia en 2026. |
| `cpp-silep` | [SILEP, texto actualizado del CPP](https://www.silep.gob.bo/api/v1/documento_actualizado/4311/pdf), artículos 233 y 235 ter | Se verificaron extractos indexados oficiales. La descarga completa no respondió: pendiente cotejo del texto íntegro, reformas, vigencia temporal y Gaceta. |
| `tsj-derechos-2023` | [TSJ, Protocolo de Juzgamiento Penal con Enfoque de Derechos Humanos](https://tsj.bo/wp-content/uploads/2023/12/PROTOCOLO.pdf), página 64 y sección II.1.4.4 | Se verificaron extractos indexados oficiales. El lector rechazó el PDF por tamaño; consulta íntegra pendiente. |
| `tsj-ogp` | [TSJ, Oficina Gestora de Procesos](https://tsj.bo/ogp/), funciones y estructura | Distinguir apoyo administrativo y actividad jurisdiccional; no hay conexión de JURISSIM con Éforo u otros sistemas judiciales. |

El artículo 233 consultado contempla petición fiscal o de la víctima, incluso sin querella, para detención preventiva, además de imputación formal, requisitos y excepcionalidad. Por eso el escenario fiscal es una limitación del MVP, no una exclusión legal de la víctima. El artículo 235 ter consultado exige fundamentación y contempla improcedencia, medida solicitada o menos grave; para detención, duración y audiencia de control. [CPP, artículos 233 y 235 ter](https://www.silep.gob.bo/api/v1/documento_actualizado/4311/pdf).

El debate orientativo se organiza por cuestiones, dando contradicción en cada una. No equivale a dos discursos completos de fiscal y defensa seguidos de una resolución. Réplica y dúplica no son turnos obligatorios de cantidad fija; en cesación puede iniciar la defensa, escenario excluido de esta versión. [TSJ, capítulo 5, páginas 47–50](https://tsj.bo/wp-content/uploads/2019/11/protocolo-de-direcci%C3%B3n-de-audiencias-de-medidas-cautelares-min.pdf).

## Matriz propuesta

La columna «condición» es una decisión de diseño pendiente de revisión, no una norma boliviana adicional. Las acciones son nombres conceptuales; no son endpoints ni permisos implementados.

| Bloque | Participantes y acciones propuestas | Condición técnica para avanzar |
| --- | --- | --- |
| Preparación técnica | Apoyo: revisar expediente, solicitud y registros de convocatoria; sin decidir medidas. | Análisis aprobado, configuración revisada e identificación del escenario; lo faltante no se inventa. |
| Instalación | Juez: identificar participantes y explicar objeto; partes: identificarse. | Defensa y comprensión comprobadas; participantes necesarios representados. |
| Cuestiones previas | Partes: plantear; juez: ordenar tratamiento. | Marcar expresamente si existen; ante cuestión no cubierta, bloquear para revisión. No resolver incidentes genéricamente. |
| Materialidad y participación probable | Solicitud, contradicción y aclaraciones dirigidas por juez. | Debate registrado; ruta siguiente justificada y revisada, no decidida por el modelo. |
| Riesgos procesales | Debate separado sobre fuga y obstaculización. | Respuesta a cada riesgo invocado y fuentes verificadas; vacío documental no significa ausencia demostrada de riesgo. |
| Medida y duración | Debate de alternativas, necesidad y temporalidad. | Solicitud y respuesta registradas; datos exigibles identificados o marcados pendientes. |
| Resolución | Juez simulado: proponer fundamentos y resultado académico. | Revisión humana antes de registrar; ninguna sentencia de culpabilidad ni decisión real. |
| Cierre y registro | Registrar comunicación y cierre del ejercicio. | Resolución revisada y acta consistente. Cerrar audiencia no significa terminar la causa. |

Base de la organización del debate: [TSJ, capítulo 5](https://tsj.bo/wp-content/uploads/2019/11/protocolo-de-direcci%C3%B3n-de-audiencias-de-medidas-cautelares-min.pdf). Base de excepcionalidad y carácter procesal: [material de la Escuela de Jueces](https://backend.academico.eje.gob.bo/files/recursos/PROTOCOLO_DE_DIRECCION_DE_AUDIENCIA_DE_MEDIDAS_CAUTELARES_1.pdf). La separación del apoyo administrativo se apoya en [TSJ, OGP](https://tsj.bo/ogp/).

## Participantes: realidad procesal frente al MVP

Juez, fiscal y abogado defensor tienen turnos representables en la API actual. Imputado, víctima, querellante, abogado de la víctima e intérprete deben contemplarse al revisar el escenario; no son todos obligatorios en cualquier caso ni están disponibles como agentes del MVP. No asignarles una intervención de fiscal o defensa como sustitución silenciosa.

Si un escenario necesita un participante o una garantía que aún no se representa, declararlo no soportado para práctica completa; puede permanecer consultable. No obligar al imputado a declarar para satisfacer un contador de turnos. No inferir culpabilidad por guardar silencio. Estas salvaguardas de diseño necesitan revisión profesional de alcance y implementación.

## Contrato técnico del siguiente hito

Propuesta de fachada `OrquestadorAudiencia`, reutilizando `AvanzarEtapaAudiencia`, `ResolverTurnoAudiencia`, registro de intervenciones y generación/confirmación existentes. No reemplazar el dominio ni crear otro motor paralelo en FastAPI.

1. Resolver propietario, análisis vigente, escenario, versión de reglas y etapa desde Laravel.
2. Exponer bloque actual, participante, acción disponible, motivo de bloqueo y fuentes del contexto.
3. Diferenciar intervención de la defensa, borrador de agente, revisión del borrador y avance: generar no equivale a registrar, y registrar no equivale a avanzar.
4. Mantener transacciones, bloqueo de fila e idempotencia. Revalidar turno y contexto al confirmar.
5. Fijar versión de etapas, transiciones, turnos, reglas, fuentes normativas y revisión en cada práctica; cambios de catálogo no deben reescribir prácticas existentes.
6. Acotar memoria por asunto, separando hechos aprobados, alegaciones y datos inciertos. La relevancia RAG no constituye aprobación jurídica.
7. Exigir autorización específica antes de transmitir contexto a un proveedor externo. Ningún estudio de este documento consume API.

### Bloqueos identificados en el código

- `AudienciasSeeder` contiene una cadena por actores (`argumentacion_fiscal`, `argumentacion_defensa`, `debate`), no por cuestiones. No renombrar ni migrar automáticamente las etapas de prácticas existentes.
- En `origin/develop` inspeccionado, `ResolverTurnoAudiencia::exigirTurnosCompletados` permite avanzar cuando no hay turnos. La copia local de trabajo contiene un bloqueo aún separado de esa base; revisar y probar su integración antes de considerar una audiencia completa.
- `GuardarPropuestaTurnoRequest` admite solo juez, fiscal y defensa. Las propuestas son borradores; no hay aprobación/activación jurídica implementada.
- Confirmación y proveedor externo tienen cambios locales/ramas pendientes: su presencia local no demuestra integración en `develop`.
- No hay revisión normativa completa ni revisor jurídico designado. Administrador técnico, dueño del expediente y revisor jurídico son responsabilidades distintas.

## Revisión necesaria antes de activar

- [ ] Cotejar CPP y reformas aplicables, artículos pertinentes, jurisprudencia y fecha de vigencia; archivar versiones verificables, no solo URLs.
- [ ] Designar profesional responsable y acordar evidencia y procedimiento de aprobación; no inventar una firma ni un rol de aprobación.
- [ ] Revisar competencia del órgano, solicitud, intervención de víctima/imputado y garantías; casos de violencia, niñez y otras especialidades no se admiten automáticamente en el escenario genérico.
- [ ] Revisar ramas de resolución y tratamiento de cuestiones previas, alternativas, temporalidad y control; no fijar plazos por defecto ni deducirlos de un catálogo histórico.
- [ ] Tratar recursos en contrato propio revisado; no convertir réplica o cierre en apelación, ni asumir renuncia por ausencia de mensaje.
- [ ] Comprobar que todos los participantes/actos del escenario aprobado están soportados antes de habilitarlo.

## Criterios de aceptación del orquestador

Pruebas futuras con datos sintéticos y `jurissim_pruebas`, sin activar reglas en `jurissim`:

| Caso | Resultado esperado |
| --- | --- |
| Regla borrador, sin revisión o escenario no soportado | Consulta disponible; práctica completa y generación bloqueadas. |
| Falta análisis aprobado o se revoca | No generar/confirmar nuevas propuestas; conservar historial. |
| Cambian turno, fuentes o configuración durante generación | Rechazar confirmación obsoleta, sin duplicar ni consumir turno. |
| Dos solicitudes concurrentes o mismo token repetido | Orden consistente e idempotencia. |
| Actor incorrecto o destino arbitrario | Rechazo desde servidor, sin confiar en el navegador. |
| Bloque sin contradicción completada | No pasar al siguiente asunto por un simple conteo global. |
| Datos ausentes o fuente ajena | Marcar incertidumbre o rechazar; no fabricar hechos ni normas. |
| Réplica solicitada o cuestión previa no soportada | Decisión de ruta revisada o bloqueo explícito; nunca bucle ilimitado. |
| Cierre sin resolución revisada | No presentar práctica como terminada ni iniciar evaluación. |
| Escenario nuevo válido | Verificar el flujo completo con revisión profesional, no solo un JSON correcto. |

Este hito entrega documentación y contrato inactivo, no funcionalidad nueva. Siguiente tarea: implementar la coordinación y los bloqueos técnicos con secuencias sintéticas; la activación jurídica sigue siendo independiente.

Verificación documental desde la raíz: `powershell -NoProfile -File tools/documentacion/verificar-matriz-cautelar.ps1`. Comprueba estructura, referencias internas y guardas del borrador; no acredita corrección jurídica, accesibilidad permanente de enlaces ni vigencia normativa.

Pruebas de rechazo reproducibles: `powershell -NoProfile -File tools/documentacion/probar-matriz-cautelar.ps1`. Alteran copias en memoria, nunca el contrato guardado ni la aplicación.
