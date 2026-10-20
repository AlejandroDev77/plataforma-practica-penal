param(
    [Parameter(Mandatory = $true)][string]$RutaPhp,
    [string]$RutaProyecto = (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent),
    [int]$IdProcesoAnterior = 0,
    [long]$CreacionAnteriorTicks = 0
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'funciones.ps1')
$RutaProyecto = [IO.Path]::GetFullPath($RutaProyecto)
Assert-EntornoCola $RutaProyecto $RutaPhp
$backend = Join-Path $RutaProyecto 'backend'
$artisan = Join-Path $backend 'artisan'
$estadoRuta = Join-Path $backend 'storage/framework/cola-jurissim.estado.json'
$bloqueoRuta = Join-Path $backend 'storage/framework/cola-jurissim.lock'
$logRuta = Join-Path $backend 'storage/logs/cola-supervisor.log'
$bloqueo = $null
$ultimoEstado = ''

function Set-EstadoCola {
    param([string]$Estado, [int]$WorkerId = 0)
    $registro = @{ estado = $Estado; worker_pid = $WorkerId; supervisor_pid = $PID; actualizado = [DateTime]::UtcNow.ToString('o') }
    [IO.File]::WriteAllText($estadoRuta, ($registro | ConvertTo-Json -Compress), [Text.Encoding]::UTF8)
    $clave = "$Estado/$WorkerId"
    if ($script:ultimoEstado -ne $clave) {
        [IO.File]::AppendAllText($logRuta, "$(Get-Date -Format o) $Estado worker=$WorkerId`r`n", [Text.Encoding]::UTF8)
        $script:ultimoEstado = $clave
    }
}

try {
    try { $bloqueo = [IO.File]::Open($bloqueoRuta, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None) }
    catch [IO.IOException] { exit 0 } # Otro supervisor de este mismo checkout ya esta activo.
    while ($true) {
        try {
            $anteriores = @(Get-ProcesosCola $artisan)
            $manual = Get-ProcesoAnteriorCola $IdProcesoAnterior $CreacionAnteriorTicks
            if ($null -ne $manual) { $anteriores += $manual }
            if ($anteriores.Count -gt 0) {
                Set-EstadoCola 'activo' ([int]$anteriores[0].ProcessId)
            } else {
                $comprobacion = & $RutaPhp (Join-Path $PSScriptRoot 'comprobar.php') $backend 2>$null
                if ($LASTEXITCODE -ne 0) {
                    Set-EstadoCola 'esperando-postgresql-o-configuracion'
                } else {
                    $saludUrl = ($comprobacion | ConvertFrom-Json).health_url
                    $salud = Invoke-WebRequest -UseBasicParsing -Uri $saludUrl -TimeoutSec 5
                    if ($salud.StatusCode -ne 200) { throw 'Servicio documental no disponible.' }
                    $fecha = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
                    $worker = Start-Process -FilePath $RutaPhp -WorkingDirectory $backend -WindowStyle Hidden -PassThru `
                        -ArgumentList @(('"' + $artisan + '"'), 'queue:work', 'database', '--queue=default', '--tries=3', '--timeout=600', '--sleep=3', '--max-time=3600') `
                        -RedirectStandardOutput (Join-Path $backend "storage/logs/cola-$fecha.stdout.log") `
                        -RedirectStandardError (Join-Path $backend "storage/logs/cola-$fecha.stderr.log")
                    Set-EstadoCola 'activo' $worker.Id
                }
            }
        } catch {
            # No registrar mensajes HTTP, conexion, claves ni texto de los expedientes.
            Set-EstadoCola 'esperando-servicio-o-recuperacion'
        }
        Start-Sleep -Seconds 5
    }
} finally {
    if ($null -ne $bloqueo) { $bloqueo.Dispose() }
}

