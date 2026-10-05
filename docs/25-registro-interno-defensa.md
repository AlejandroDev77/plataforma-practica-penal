# Registro interno de la defensa en prácticas regladas

Continúa [los movimientos internos](24-movimientos-internos-practicas.md). Esta fase habilita exclusivamente el registro interno de texto del abogado defensor en prácticas vinculadas a reglas inmutables. No conecta todavía rutas públicas, interfaz, propuestas de juez/fiscal ni evaluación. No crea habilitaciones, revisiones, activaciones ni normativa real. No llama a proveedores.

## Comando y alcance

`RegistrarDefensaReglada::ejecutar(actor, idSimulacion, idMovimientoEsperado, idEtapaEsperada, idTurnoEsperado, ordenEsperado, texto, idsFragmento=[])` recibe un actor autenticado del servidor; no admite propietario, participante, rol, modelo o fecha enviados por el navegador. La propiedad de práctica y expediente se reconsulta. Las prácticas ajenas no están disponibles y las antiguas sin vínculo conservan su motor anterior.

Exige práctica activa, último movimiento exacto, etapa actual, propuesta de turno de la copia revisada y orden global consecutivo. La historia anterior debe ser coherente: etapas visitadas, secuencia de roles/control, contexto y turnos anteriores completos. Solo admite un turno pendiente de defensa con un único participante de ese rol, activo y controlado por el usuario. Revalida activación, última revisión jurídica, habilitación, vencimientos PostgreSQL y última aprobación del análisis mediante el contexto bloqueado de fase 24. No utiliza el diagnóstico de solo lectura como autorización ni busca una activación alternativa.

Texto no vacío de hasta 2.000 caracteres y selección explícita de hasta diez fragmentos enteros positivos distintos. Sin fuentes se registra una intervención sin citas, no una afirmación verificada. La selección de una cita no implica que el argumento sea verdadero o jurídicamente correcto; siempre necesita revisión profesional.

## Registro, transcripción y fuentes atómicos

`registros_intervenciones_regladas` conserva vínculo, último movimiento, autor, participante derivado, etapa/propuesta, orden, texto, selección original y copias de fuentes. PostgreSQL valida la inserción, deriva los identificadores internos, reserva el ID de intervención y fija su fecha con reloj del servidor. Un trigger posterior inserta transcripción y citas en la misma sentencia/transacción. No quedan paquetes parciales ante una fuente inválida, duplicación, conflicto o fallo de clave foránea.

Se sustituye el bloqueo absoluto de inserción vinculada por una comprobación de correspondencia exacta con este registro. No hay bandera de sesión para omitir controles. Las escrituras directas o servicios de agentes anteriores siguen rechazados en prácticas vinculadas. La clave foránea diferida del registro a la transcripción impide un registro huérfano al commit; los demás vínculos restringen la eliminación de dependencias.

Los registros no se actualizan, borran ni truncan. Sus intervenciones y citas no se editan, eliminan ni amplían. La identidad/contexto/rol/control del participante auditado tampoco se reasigna; puede inactivarse para impedir intervenciones futuras sin alterar su identidad histórica. No se congelan nombres mostrados ni los documentos vivos. Las copias conservan el texto y metadatos de la fecha del registro.

## Verificación de citas

La selección contiene exclusivamente IDs de fragmentos. La función SQL de esta fase construye la copia bajo bloqueo, sin confiar en extractos, títulos o metadatos suministrados por un cliente:

- Expediente: archivo del expediente exacto, extracción procesada o parcialmente fallida con página legible, correspondencia archivo/página y extracto contenido en el texto extraído.
- Fuente jurídica: validada y vigente, fechas inclusivas según el reloj PostgreSQL en `America/La_Paz`, extracto contenido en su versión actual y huella MD5 de indexación coincidente.
- Ambas: texto no vacío, fragmento de hasta 10.000 caracteres; paquete de copias de hasta 100.000 bytes JSONB.

La comparación normaliza espacios y minúsculas, pero conserva literalmente el fragmento original en la copia. Se guardan SHA-256 del contenido de la página o de la norma, título y localizador/metadatos pertinentes. No se guardan rutas privadas ni se envía texto fuera del equipo. MD5 se conserva únicamente como señal de reindexación compatible con el índice existente; no es una firma ni una aprobación.

El nuevo comando no modifica el preparador de fuentes antiguo ni los servicios de agentes existentes. La construcción SQL separada permite también proteger escrituras directas y usar fechas de Bolivia sin depender del reloj PHP. El bloqueo general mantiene el orden de fase 24, seguido por participantes y fragmentos ordenados; se bloquean archivo/página o fuente durante la comprobación. Escritores externos con otro orden pueden provocar interbloqueos: se rechazan conflictos sin reintentar ni afirmar que cualquier orden externo sea seguro. Se exige `READ COMMITTED`; no se afirma una prueba de carga concurrente.

## Migración y pruebas

Migración aditiva: `2026_10_04_000004_registrar_defensa_reglada.php`. No transforma filas anteriores ni altera el motor sin vínculo. El rollback se niega si hay algún registro auditado; vacío, retira esta capa y restaura el bloqueo absoluto de intervenciones vinculadas. Las pruebas de rollback de fases anteriores retiran primero esta dependencia.

Instalar únicamente esta ruta después de verificar base efectiva habitual local y migración de movimientos:

```powershell
php artisan migrate --path=database/migrations/2026_10_04_000004_registrar_defensa_reglada.php
```

Nunca `migrate:fresh` en `jurissim`. Las pruebas destructivas verifican configuración y base efectiva `jurissim_pruebas`, PostgreSQL, ausencia de DB_URL/configuración cacheada. Bloquean HTTP externo y usan escenarios sintéticos. Para situar la defensa después del juez, preparan un turno sintético anterior mediante DDL solo en pruebas, restaurando inmediatamente el trigger; no abren agentes reales. Las protecciones no son una barrera contra un dueño de PostgreSQL con privilegios DDL.

## Siguiente paso

Adaptar propuestas y confirmación de agentes al mismo vínculo, contexto exacto, vigencia, fuentes y registro transaccional. Después conectar creación, movimientos, intervenciones y proyección de la copia revisada a API/orquestador/interfaz, manteniendo separadas autorización técnica y revisión profesional. El diagnóstico público de fase 23 sigue sin declarar ejecución autorizada ni integración completa.


## Verificación de la entrega

El 4 de octubre de 2026 pasaron 339 pruebas y 1.940 aserciones en la suite completa de la rama (23 pruebas nuevas, 106 aserciones adicionales). En la carpeta habitual pasaron las 158 pruebas seleccionadas de defensa, movimientos, versiones, revisión, activaciones, contrato, vínculo y orquestación, con 767 aserciones. Pint y revisión de espacios correctos. Las primeras ejecuciones detectaron un alias SQL ambiguo y funciones persistentes tras reconstruir tablas; se corrigieron antes de las suites finales. No se llamaron proveedores.

Se instaló solo la migración de esta fase en la base habitual `jurissim`, comprobando PostgreSQL, servidor efectivo `127.0.0.1`, ausencia de DB_URL/configuración cacheada y migración anterior aplicada. Los quince conteos comprobados de dominio y registros jurídicos no cambiaron. El nuevo registro está vacío: no se crearon intervenciones ni aprobaciones reales. Frontend, Laravel y FastAPI respondieron HTTP 200.

Archivos propios: modelo `RegistroIntervencionReglada`, servicio `RegistrarDefensaReglada`, migración `2026_10_04_000004_registrar_defensa_reglada.php`, prueba `DefensaRegladaTest`, cuatro ajustes de dependencias de rollback en pruebas anteriores, este documento y enlace desde fase 24. Se trasladaron solo esos diez archivos después de comprobar originales idénticos y sin cambios concurrentes. Se preservaron servicios de fuentes/agentes, interfaz, configuración y demás cambios ajenos. Trabajo local en la rama existente de versiones, con develop actualizado; sin push, PR, merge a develop/main ni alteración de protecciones.
