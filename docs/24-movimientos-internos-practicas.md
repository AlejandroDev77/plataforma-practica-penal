# Inicio, avance y cierre internos de prácticas regladas

Continúa la [vinculación interna](23-vinculacion-interna-practicas.md). Este hito implementa el comando de movimiento sobre prácticas nuevas vinculadas; no incorpora todavía su creación pública, intervenciones, propuestas de agentes o interfaz al nuevo flujo. Las audiencias anteriores siguen usando el motor existente. No se crean reglas, habilitaciones, revisiones, activaciones ni rúbricas jurídicas reales.

## Comando y precondiciones

`MoverPracticaReglada::ejecutar(actor, idSimulacion, accion, estadoEsperado, idEtapaEsperada, idDestino=null)` es interno. El actor procede de autenticación, no de un propietario recibido en un cuerpo HTTP. Se reconsulta la propiedad de simulación y expediente; una práctica ajena no queda disponible por conocer su ID. El estado y etapa esperados son obligatorios, aunque la etapa inicial sea null.

Acciones: `iniciar`, `avanzar` y `finalizar`. El inicio elige únicamente la etapa inicial activa de la versión sellada, sin aceptar un destino arbitrario. El avance permite solo transiciones activas de esa copia: si hay varias salidas, exige destino explícito. El cierre exige la etapa final de la copia y conserva esa etapa como último contexto. No vuelve al catálogo editable para decidir el recorrido; las etapas mantienen sus identificadores de dominio y sus claves foráneas. Borrar dependencias necesarias puede impedir un movimiento, nunca reasignarlo a otra etapa.

Cada mutación revalida la activación exacta ligada a la práctica, su versión/huella/configuración, última revisión jurídica, habilitación, vencimientos PostgreSQL, tipo actualmente activo y última aprobación humana de un análisis procesado. El comando no usa el diagnóstico de solo lectura como permiso. Un retiro o sustitución bloquea la operación sin cambiar el vínculo histórico. No hay elección de una activación alternativa, reintento automático o generación de texto.

## Movimiento y estado atómicos

`movimientos_practicas_regladas` registra orden consecutivo por práctica, vínculo exacto, responsable del servidor, acción, estado/etapa de origen y destino y fecha PostgreSQL. La inserción se valida bajo bloqueo y un trigger posterior aplica el estado, etapa y fechas de la simulación dentro de la misma transacción. Si falla cualquiera de las comprobaciones o una clave foránea, no quedan movimiento ni avance parcial.

El guardia de la simulación acepta cambios de ejecución únicamente si corresponden al último movimiento validado y a su contexto de origen. No admite escrituras directas del estado ni reutilización de un movimiento ya aplicado. La configuración, propietario, expediente, análisis, tipo, rol y fecha de creación vinculados siguen inmutables. No se utiliza un indicador de sesión SQL ni un parámetro del navegador para omitir validaciones.

El orden normal de bloqueo es expediente, análisis, tipo `FOR NO KEY UPDATE`, habilitación, versión y simulación. Se exige `READ COMMITTED`, se vuelve a leer tras esperas y se usa el reloj PostgreSQL. Un contexto desactualizado o conflicto se rechaza sin reintentar. Escritores SQL que toman otros bloqueos antes de invocar estas funciones pueden provocar conflictos; no se afirma que cualquier orden externo esté libre de interbloqueos. El servicio transforma conflictos conocidos en un mensaje fijo sin SQL, parámetros ni instrucciones.

## Turnos y límites del hito

Avanzar o cerrar exige completar todos los turnos de la etapa actual. No basta un conteo: se comprueban orden global consecutivo, secuencia por etapa, rol y control del participante, contexto de expediente/análisis/tipo, texto no vacío y pertenencia a etapas realmente visitadas. Las etapas anteriores del recorrido deben conservar todos sus turnos. La normativa sustantiva, exactitud del contenido y fuentes no se certifican por estas comprobaciones técnicas.

**Las intervenciones de prácticas vinculadas todavía están bloqueadas.** Los tests preparan una transcripción futura exclusivamente mediante DDL y datos sintéticos en `jurissim_pruebas`, restaurando el trigger inmediatamente después. No se abre esa vía en la aplicación ni se desactivan protecciones en la base habitual. Por eso este hito no declara una audiencia pública completa, ni habilita generación o evaluación automática.

El motor anterior no puede usar estos vínculos para cambiar de etapa sin movimiento. Su comportamiento para prácticas sin vínculo permanece intacto. El diagnóstico de la fase 23 sigue devolviendo `execution_authorized=false` y `requires_command_integration=true`: no se ha completado la integración de todos los comandos ni del flujo público. Las relaciones y recursos antiguos pueden mostrar etiquetas del catálogo vivo; una futura proyección pública deberá presentar la copia revisada.

## Migración y conservación

La migración aditiva `2026_10_04_000003_registrar_movimientos_practicas.php` crea el registro y funciones/triggers correspondientes; reemplaza el guardia de cambios de ejecución sin editar la migración ya aplicada de la fase 23. No cambia filas existentes. Los movimientos son inmutables por modelo y PostgreSQL: no se actualizan, borran ni truncan. Las claves foráneas restringen eliminación de sus dependencias.

El rollback se niega si hay algún movimiento. Si el registro está vacío, retira únicamente esta capa y restaura el cerrojo anterior que bloquea toda ejecución vinculada. Las pruebas de migraciones antiguas retiran primero esta dependencia y la reinstalan al final. Un propietario SQL con permisos DDL puede omitir protecciones: no es una firma digital ni una barrera frente al dueño de PostgreSQL.

Tras comprobar el destino habitual y la migración anterior, instalar solo esta ruta desde backend:

```powershell
php artisan migrate --path=database/migrations/2026_10_04_000003_registrar_movimientos_practicas.php
```

No usar `migrate:fresh` en `jurissim`. Las pruebas destructivas comprueban base configurada y efectiva `jurissim_pruebas`, conexión PostgreSQL, ausencia de `DB_URL` y configuración cacheada. Credenciales solo en el entorno del proceso; HTTP externo bloqueado y escenarios sintéticos. Se prueban precondiciones secuenciales y aislamiento con una segunda conexión, no carga concurrente real ni validez profesional del procedimiento boliviano.

## Siguiente paso

La [fase 25](25-registro-interno-defensa.md) continúa con el registro interno de texto de la defensa y sus fuentes. No incorpora todavía propuestas de agentes ni el flujo público completo; los límites de este documento corresponden a su entrega original.

Integrar el registro de intervenciones a este vínculo con controles de turno, participantes, historial y fuentes en la transacción. Después adaptar la propuesta/confirmación de agentes y exponer el flujo nuevo mediante API, diagnóstico del orquestador e interfaz, con pruebas completas de aceptación. Mantener separadas la autorización técnica y la revisión profesional del contenido.

## Verificación de la entrega

El 4 de octubre de 2026 pasaron 316 pruebas y 1.834 aserciones en la suite completa de la rama, incluidas 24 pruebas nuevas de movimientos. En la carpeta habitual pasaron 245 pruebas de reglas y orquestación con 1.341 aserciones. Pint y revisión de espacios correctos. Las primeras ejecuciones detectaron una expresión CASE sin paréntesis en PL/pgSQL y un fixture sin cargar su estado predeterminado; ambos se corrigieron antes de las suites finales y de instalar la migración habitual. No se llamaron proveedores.

Se instaló únicamente la migración de esta fase en `jurissim`, verificando servidor efectivo local `127.0.0.1`, PostgreSQL, ausencia de `DB_URL` y configuración cacheada, y presencia de la migración anterior. Los conteos de usuarios, expedientes, análisis, prácticas, intervenciones, versiones, revisiones, habilitaciones, activaciones, retiros y vínculos permanecieron iguales. El registro de movimientos está vacío: no se iniciaron ni avanzaron prácticas reales. Los servicios habituales frontend, Laravel y FastAPI respondieron correctamente después de la instalación.

Archivos propios: modelo `MovimientoPracticaReglada`, servicio `MoverPracticaReglada`, migración `2026_10_04_000003_registrar_movimientos_practicas.php`, prueba `MovimientoPracticaRegladaTest`, ajustes de dependencias de rollback en tres pruebas anteriores, este documento y enlace desde la fase 23. Se trasladaron solo esos nueve archivos; no se sustituyeron servicios de agentes, rutas, interfaz o configuración ajenos. Se conservó la rama local de versiones y sus dependencias, comprobando develop actualizado con pull --ff-only. Sin push, PR, merge a develop/main ni cambios a protecciones.
