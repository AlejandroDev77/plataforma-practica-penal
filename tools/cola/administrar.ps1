param(
    [ValidateSet('Instalar', 'Estado', 'Desinstalar')][string]$Accion = 'Estado',
    [string]$RutaProyecto = (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent),
    [string]$RutaPhp = '',
    [int]$IdProcesoAnterior = 0
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'funciones.ps1')
$RutaProyecto = [IO.Path]::GetFullPath($RutaProyecto)
$nombre = Get-IdentidadCola $RutaProyecto
$supervisor = Join-Path $RutaProyecto 'tools/cola/supervisar.ps1'
$powershell = Join-Path $env:SystemRoot 'System32/WindowsPowerShell/v1.0/powershell.exe'
$actual = Get-ScheduledTask -TaskPath '\' -TaskName $nombre -ErrorAction SilentlyContinue

if ($Accion -eq 'Estado') {
    if ($null -eq $actual) { Write-Output 'La cola persistente no esta instalada.'; exit 0 }
    $info = Get-ScheduledTaskInfo -TaskPath '\' -TaskName $nombre
    Write-Output (([pscustomobject]@{ Tarea = $nombre; Estado = [string]$actual.State; UltimoResultado = $info.LastTaskResult }) | ConvertTo-Json -Compress)
    $rutaEstado = Join-Path $RutaProyecto 'backend/storage/framework/cola-jurissim.estado.json'
    if (Test-Path -LiteralPath $rutaEstado) { Write-Output (Get-Content -LiteralPath $rutaEstado -Raw) }
    exit 0
}

if ($null -ne $actual -and ($actual.Actions.Count -ne 1 -or
    -not [string]::Equals($actual.Actions[0].Execute, $powershell, [StringComparison]::OrdinalIgnoreCase) -or
    -not $actual.Actions[0].Arguments.Contains(('"' + $supervisor + '"')))) {
    throw 'Existe una tarea con ese nombre y otra accion. No se modifico.'
}

if ($Accion -eq 'Desinstalar') {
    if ($null -ne $actual) {
        # No desinstalar mientras se procesan trabajos: Windows puede detener tambien los hijos.
        Stop-ScheduledTask -TaskPath '\' -TaskName $nombre
        Unregister-ScheduledTask -TaskPath '\' -TaskName $nombre -Confirm:$false
        Write-Output 'Inicio automatico retirado. No se borraron trabajos, expedientes ni registros.'
    }
    exit 0
}

if ([string]::IsNullOrWhiteSpace($RutaPhp)) { $RutaPhp = (Get-Command php -ErrorAction Stop).Source }
$RutaPhp = [IO.Path]::GetFullPath($RutaPhp)
Assert-EntornoCola $RutaProyecto $RutaPhp
$ticks = [long]0
if ($IdProcesoAnterior -gt 0) {
    $previo = Get-CimInstance Win32_Process -Filter "ProcessId = $IdProcesoAnterior" -ErrorAction Stop
    if ($null -eq $previo -or $previo.Name -ne 'php.exe' -or
        $previo.CommandLine -notmatch '(?i)(?:^|\s)queue:work\s+database\s+--queue=default(?:\s|$)') {
        throw 'El proceso anterior indicado no es un worker database/default. No se detuvo ningun proceso.'
    }
    $ticks = $previo.CreationDate.ToUniversalTime().Ticks
}

# Rutas entre comillas; no se cambia ExecutionPolicy ni se guardan credenciales.
if ($RutaProyecto.Contains('"') -or $RutaPhp.Contains('"')) { throw 'Ruta no admitida.' }
$argumentos = '-NoProfile -NonInteractive -WindowStyle Hidden -File "' + $supervisor + '" -RutaProyecto "' + $RutaProyecto + '" -RutaPhp "' + $RutaPhp + '" -IdProcesoAnterior ' + $IdProcesoAnterior + ' -CreacionAnteriorTicks ' + $ticks
$usuario = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$accionTarea = New-ScheduledTaskAction -Execute $powershell -Argument $argumentos -WorkingDirectory (Join-Path $RutaProyecto 'backend')
$disparador = New-ScheduledTaskTrigger -AtLogOn -User $usuario
$principal = New-ScheduledTaskPrincipal -UserId $usuario -LogonType Interactive -RunLevel Limited
$ajustes = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskPath '\' -TaskName $nombre -Action $accionTarea -Trigger $disparador -Principal $principal -Settings $ajustes `
    -Description 'JURISSIM: supervision local de la cola PostgreSQL, con reinicio y sin ventanas.' -Force | Out-Null
Start-ScheduledTask -TaskPath '\' -TaskName $nombre
Write-Output "Cola persistente instalada: $nombre. Se inicia ahora y al iniciar sesion en Windows."

