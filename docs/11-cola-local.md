# Cola persistente en Windows

La extracción y los demás trabajos en segundo plano necesitan un worker Laravel. Subir un archivo solo lo encola: ni Vite ni `artisan serve` consumen la cola.

## Instalación local

Desde la raíz del proyecto, con PostgreSQL y FastAPI disponibles:

```powershell
powershell -NoProfile -File .\tools\cola\administrar.ps1 -Accion Instalar
```

Si PHP no está en PATH, añadir `-RutaPhp "C:\ruta\php.exe"`. La ruta se resuelve al instalar, sin modificar el PATH. La instalación exige dependencias de Laravel y `backend/.env`.

Se registra una tarea `JURISSIM-Cola-<identidad-del-proyecto>` para el usuario actual, sin elevación, contraseña guardada ni cambios de ExecutionPolicy. Arranca inmediatamente y al iniciar sesión en Windows. Solo se instala para este checkout; reinstalar actualiza la misma tarea.

El supervisor mantiene **un worker `database/default` por checkout**, sin consola visible. Comprueba la conexión PostgreSQL, la configuración local y la salud de FastAPI antes de iniciar un nuevo worker. Si falta un servicio, espera y vuelve a comprobar. No inicia PostgreSQL ni FastAPI por su cuenta y no reintenta automáticamente trabajos que ya fallaron.

El worker usa `--tries=3 --timeout=600 --sleep=3 --max-time=3600`. Si termina, el supervisor intenta recuperarlo cada cinco segundos; Task Scheduler reintenta arrancar el supervisor tres veces con intervalos de un minuto si este falla. Un bloqueo exclusivo evita supervisores duplicados y los workers se identifican por la ruta absoluta de `artisan`. El `retry_after` debe ser mayor que 600 segundos.

Durante la primera instalación puede adoptarse un worker manual conocido mediante `-IdProcesoAnterior <PID>`. Su fecha de creación se verifica para no confundir un PID reutilizado. Al cerrar ese worker, el supervisor inicia el suyo. No se debe iniciar otra consola `queue:work` mientras está instalado el supervisor: workers manuales con rutas relativas no se pueden atribuir de forma fiable a un proyecto.

## Estado y registros

```powershell
powershell -NoProfile -File .\tools\cola\administrar.ps1 -Accion Estado
```

- Estado del supervisor: `backend/storage/framework/cola-jurissim.estado.json`, con fecha UTC y PID. Una fecha antigua no garantiza que siga activo; comprobar también el estado de la tarea.
- Transiciones: `backend/storage/logs/cola-supervisor.log`, sin textos de documentos ni credenciales.
- Salida de cada worker: `backend/storage/logs/cola-*.stdout.log` y `cola-*.stderr.log`. Los registros de Laravel también siguen en `storage/logs`.

Después de cambiar código o configuración, usar `php artisan queue:restart` desde `backend`. El worker termina su trabajo actual y el supervisor inicia otro. La rotación por `max-time` también recoge cambios, pero puede tardar hasta una hora. No borrar la cola para reiniciar el worker.

## Límites y desinstalación

El equipo debe permanecer encendido, sin suspensión y con la sesión del usuario iniciada. No es un servicio del sistema antes de iniciar sesión. Bloquear la pantalla no cierra la sesión. PostgreSQL y FastAPI siguen siendo necesarios durante el procesamiento; supervisar el worker no evita errores del proveedor o del documento.

```powershell
powershell -NoProfile -File .\tools\cola\administrar.ps1 -Accion Desinstalar
```

Retira únicamente esta tarea; no borra archivos, trabajos, expedientes ni registros. Desinstalar mientras se procesa un archivo no está recomendado: detener la tarea puede interrumpir sus procesos asociados según el comportamiento de Windows.

## Verificación de scripts

```powershell
powershell -NoProfile -File .\tools\cola\pruebas.ps1
php -l .\tools\cola\comprobar.php
```

Las pruebas de identificación y sintaxis no acceden a la base de datos. `comprobar.php` solo hace `select 1`, no consume ni modifica trabajos. La tarea no genera análisis nuevos ni solicitudes al proveedor; procesa exclusivamente los trabajos ya encolados por la aplicación.

