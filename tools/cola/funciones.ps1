Set-StrictMode -Version Latest

function Get-IdentidadCola {
    param([Parameter(Mandatory = $true)][string]$RutaProyecto)
    $ruta = [IO.Path]::GetFullPath($RutaProyecto).TrimEnd([char[]]@('\', '/')).ToLowerInvariant()
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $hash = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($ruta)))).Replace('-', '').Substring(0, 12) }
    finally { $sha.Dispose() }
    return "JURISSIM-Cola-$hash"
}

function Test-ProcesoCola {
    param($Proceso, [string]$RutaArtisan)
    if ($null -eq $Proceso -or $Proceso.Name -ne 'php.exe' -or [string]::IsNullOrWhiteSpace($Proceso.CommandLine)) { return $false }
    $comando = $Proceso.CommandLine.Replace('/', '\')
    $ruta = [IO.Path]::GetFullPath($RutaArtisan).Replace('/', '\')
    # La ruta completa evita confundir workers de otros proyectos.
    $patron = '(?:^|\s)(?:"' + [regex]::Escape($ruta) + '"|' + [regex]::Escape($ruta) + ')(?=\s)'
    return ($comando -match $patron -and $comando -match '(?i)(?:^|\s)queue:work\s+database(?:\s|$)' -and $comando -match '(?i)\s--queue=default(?:\s|$)')
}

function Get-ProcesosCola {
    param([string]$RutaArtisan)
    return @(Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object { Test-ProcesoCola $_ $RutaArtisan })
}

function Get-ProcesoAnteriorCola {
    param([int]$IdProceso, [long]$CreacionTicks)
    if ($IdProceso -le 0 -or $CreacionTicks -le 0) { return $null }
    $proceso = Get-CimInstance Win32_Process -Filter "ProcessId = $IdProceso" -ErrorAction SilentlyContinue
    if ($null -ne $proceso -and $proceso.Name -eq 'php.exe' -and $null -ne $proceso.CreationDate -and
        $proceso.CreationDate.ToUniversalTime().Ticks -eq $CreacionTicks -and
        $proceso.CommandLine -match '(?i)(?:^|\s)queue:work\s+database\s+--queue=default(?:\s|$)') { return $proceso }
    # Un PID reutilizado nunca se adopta ni se detiene.
    return $null
}

function Assert-EntornoCola {
    param([string]$RutaProyecto, [string]$RutaPhp)
    if (-not (Test-Path -LiteralPath $RutaPhp -PathType Leaf)) { throw 'No se encontro el ejecutable PHP configurado.' }
    foreach ($relativa in @('backend/artisan', 'backend/vendor/autoload.php', 'backend/.env')) {
        if (-not (Test-Path -LiteralPath (Join-Path $RutaProyecto $relativa) -PathType Leaf)) { throw "Falta $relativa. Configure el proyecto antes de instalar la cola." }
    }
}

