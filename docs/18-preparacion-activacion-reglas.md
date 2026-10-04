# Preparación interna de activación de reglas

Estado: validación estructural y diagnóstico interno implementados, de solo lectura. Es el primer subhito de activación, no la activación ni la vinculación de prácticas al orquestador. Sin migraciones, endpoints, frontend, instrucciones generadas, permisos jurídicos reales o cambios a las prácticas existentes.

Continúa en `funcionalidad/versiones-reglas-audiencia`, que conserva sus dependencias locales pendientes de publicación. No crear una rama desde un `develop` que todavía no contiene esas dependencias ni integrar mediante un merge local para eludir checks.

## Perfil técnico

`ValidarEstructuraVersionReglas::ejecutar(contenido, idTipo)` es una función de diagnóstico sin acceso a la base, proveedores ni archivos. Perfil versionado `mvp-defensa-aciclico-v1`:

- Copia sellada de formato 1 y del tipo exacto; tipo activo en la copia.
- Listas acotadas: hasta 100 etapas, 1.000 transiciones, 10.000 propuestas y 10.000 turnos de referencia. El diagnóstico del servicio rechaza contenido JSONB de más de 2.000.000 bytes.
- Identificadores y órdenes enteros estrictos, sin coerción de cadenas; booleanos estrictos. Etapas con ID, código y orden únicos, nombres/códigos no vacíos y pertenencia al mismo tipo.
- Exactamente una etapa inicial activa y una final activa. Una etapa puede ser ambas; esto solo es una posibilidad estructural, no un procedimiento certificado. La inicial no recibe entradas activas y la final no tiene salidas activas.
- Transiciones con IDs y pares únicos, extremos distintos y existentes del mismo ámbito. Las aristas activas requieren extremos activos. Las inactivas se conservan como referencia, sin usarlas para reparar el grafo.
- Toda etapa activa debe ser alcanzable desde el inicio y tener camino al cierre. Las no finales requieren salida. Se rechazan ciclos, incluidos los desconectados; el recorrido es iterativo y acotado.
- Las bifurcaciones válidas se conservan sin elegir destino. El orden visual de una etapa no sustituye las transiciones ni impone una secuencia lineal.
- Cada etapa activa requiere entre 1 y 100 propuestas completas: ID y orden sin duplicados, orden 1–100, estado `borrador`, autor conocido, acto y descripción acotados. Se admiten huecos de numeración; no se reordena ni inventa un turno.
- Roles compatibles con el motor existente: juez, fiscal y abogado defensor. Otros intervinientes requieren soporte explícito posterior; no se sustituyen por estos roles.

Las propuestas referidas a etapas inactivas bloquean la preparación: no se descartan silenciosamente. Etapas inactivas sin propuestas pueden permanecer como referencia histórica.

Los turnos de catálogo no sustituyen las propuestas. Sus instrucciones no se copian ni se ejecutan. Las descripciones de las propuestas tampoco se convierten en instrucciones. Referencias y observaciones normativas no se interpretan como autoridad; su suficiencia pertenece a la revisión profesional.

Estas reglas son restricciones de compatibilidad del MVP, no afirmaciones sobre el procedimiento boliviano. No certifican la suficiencia del debate, que cada camino incluya fiscal o defensa, ni el tratamiento de incidentes, suspensiones, reaperturas o recursos. Los ciclos necesitan un diseño de ejecución y conservación del historial antes de admitirse.

## Contrato de diagnóstico

El validador devuelve `perfil_validacion`, `estructura_valida`, `bloqueos` y `diagnostico_truncado`. Cada bloqueo incluye un código fijo, etapa cuando es identificable e índice de fila; no repite descripciones, referencias, instrucciones, datos documentales o evidencia de acreditación. Se conservan hasta 100 bloqueos; si hay más, el resultado sigue siendo inválido y señala truncamiento.

Familias de bloqueos: formato/tipo o colecciones inválidas, etapas inválidas/duplicadas, inicio/cierre no únicos, transiciones inválidas/duplicadas/sin extremos, extremos inactivos, entrada al inicio, salida del cierre, falta de salida, falta de alcance desde el inicio o hacia el cierre, ciclos, propuestas inválidas/duplicadas/sin etapa activa, autoría desconocida, roles no soportados y turnos ausentes/excesivos.

`ConsultarPreparacionVersionReglas::ejecutar(User actor, idVersion)` agrega:

- Actor procedente del contexto autenticado del servidor; exige administración de plataforma reconsultada. Un revisor habilitado o administrador institucional no recibe este acceso técnico por defecto.
- Lectura de la versión exacta sellada, nunca del catálogo editable actual ni una versión alternativa.
- Recalcula en PostgreSQL la huella del JSONB completo con el mismo procedimiento del sellado; no compara una serialización HTTP/PHP. Una diferencia añade `huella_version_incompatible`.
- Consulta la última revisión vigente mediante el servicio existente, con vencimientos del reloj de PostgreSQL. Ausencia, requerimiento de cambios, retiro o vencimiento añade `revision_juridica_no_vigente`. No recupera aprobaciones anteriores ni las rehabilita por renovar la habilitación.
- `prerrequisitos_preliminares_cumplidos` describe únicamente estructura, integridad y revisión en esta lectura. Incluso si es verdadero, siempre devuelve `execution_authorized=false`, `requires_separate_activation=true` y `activacion_separada_pendiente` en `blocking_reasons`.

No reserva una revisión ni concede autoridad de ejecución. La estructura sellada y el estado jurídico se consultan por separado: no se ofrece una fotografía transaccional conjunta. Un retiro/vencimiento puede ocurrir antes de usar el resultado. Tampoco certifica disponibilidad actual de catálogos, participantes, análisis, proveedor o tratamiento de datos.

## Continuación de activación

La persistencia del contrato ejecutable, revisión explícita de sus instrucciones, activación y retiro internos se desarrollan en `19-activacion-interna-reglas.md`. Este diagnóstico preliminar conserva su contrato de solo lectura y sus bloqueos: no se transforma en permiso. La interfaz de activación y la vinculación al motor continúan pendientes.

El siguiente comando debe registrar una activación explícita e inmutable y su retiro, ligados a versión, revisión, habilitación y perfil estructural exactos. Debe comprobar otra vez permisos, integridad, estado y precondiciones dentro de la transacción, coordinando el orden de bloqueos con habilitación → versión y serializando el destino por tipo. Nunca reutilizar este diagnóstico como permiso.

Antes de implementar ese comando hay que definir un contrato ejecutable de turnos e instrucciones que también esté cubierto por la revisión jurídica. No convertir borradores retrospectivamente en instrucciones ni aprobar una representación diferente mediante la huella de la copia original.

Después, nuevas prácticas capturarán la activación exacta; las existentes no se actualizarán silenciosamente. Las mutaciones del motor deberán usar una comprobación común de vigencia y de turno, incluido el retiro posterior. El orquestador de solo lectura existente permanece sin cambios en esta fase.

## Archivos y comprobación

- `backend/app/Services/Simulaciones/ValidarEstructuraVersionReglas.php`.
- `backend/app/Services/Simulaciones/ConsultarPreparacionVersionReglas.php`.
- `backend/tests/Unit/ValidarEstructuraVersionReglasTest.php`: 48 casos puros; no crea aplicación ni conecta PostgreSQL.
- `backend/tests/Feature/PreparacionVersionReglasTest.php`: 12 escenarios sintéticos. Guardia antes de refrescar esquema: PostgreSQL, base configurada y efectiva `jurissim_pruebas`, sin `DB_URL` ni configuración cacheada. HTTP externo bloqueado. La alteración privilegiada de huella se simula únicamente allí y restaura el trigger en `finally`.
- Este documento y enlace desde la fase de interfaz.

Sin comandos de instalación ni migración. Con dependencias existentes, desde `backend`:

```powershell
php vendor/bin/phpunit tests/Unit/ValidarEstructuraVersionReglasTest.php
php vendor/bin/phpunit tests/Feature/PreparacionVersionReglasTest.php
```

Para las pruebas con aplicación, configurar exclusivamente `jurissim_pruebas` y una clave de aplicación válida para el proceso de pruebas; nunca ejecutar un refresco de esquema contra `jurissim`. El checkout aislado no incluye un `.env`; se usan variables de proceso, sin guardar ni imprimir credenciales. La primera corrida completa detectó falta de `APP_KEY` en ese checkout; la repetición con clave sintética temporal pasó, sin modificar la clave del sistema habitual.

Verificación del 4 de octubre de 2026: suite completa en la rama, 220 pruebas y 1.165 aserciones aprobadas. En la carpeta habitual, validación/preparación nuevas, versiones, revisiones, API de gobierno y orquestador existente: 149 pruebas y 672 aserciones aprobadas. Pint correcto. Archivos propios incorporados y comparados entre checkouts, conservando todos los cambios ajenos. Sin migraciones, registros jurídicos reales, activaciones, cambios a expedientes, llamadas a modelos ni publicación remota; solo commit local.
