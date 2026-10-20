$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'funciones.ps1')
$cantidad = 0
function Assert-Prueba {
    param([bool]$Condicion, [string]$Descripcion)
    if (-not $Condicion) { throw "Fallo: $Descripcion" }
    $script:cantidad++
}
foreach ($archivo in (Get-ChildItem -LiteralPath $PSScriptRoot -Filter '*.ps1')) {
    $tokens = $null
    $errores = $null
    [Management.Automation.Language.Parser]::ParseFile($archivo.FullName, [ref]$tokens, [ref]$errores) | Out-Null
    Assert-Prueba ($errores.Count -eq 0) "Sintaxis $($archivo.Name)"
}
$raiz = 'C:\Proyectos\Jurissim Prueba'
$artisan = Join-Path $raiz 'backend\artisan'
Assert-Prueba ((Get-IdentidadCola $raiz) -eq (Get-IdentidadCola ($raiz.ToUpperInvariant() + '\'))) 'Identidad normalizada'
Assert-Prueba ((Get-IdentidadCola $raiz) -ne (Get-IdentidadCola ($raiz + '-otro'))) 'Proyectos separados'
foreach ($comando in @(
    ('"C:\php\php.exe" "' + $artisan + '" queue:work database --queue=default --tries=3'),
    ('php "' + $artisan.Replace('\', '/') + '" queue:work database --queue=default')
)) {
    Assert-Prueba (Test-ProcesoCola ([pscustomobject]@{ Name = 'php.exe'; CommandLine = $comando }) $artisan) 'Reconoce worker propio'
}
foreach ($comando in @(
    'php artisan queue:work database --queue=default',
    'php "C:\Otro\backend\artisan" queue:work database --queue=default',
    ('php "' + $artisan + '" serve --port=8001'),
    ('php "' + $artisan + '" queue:work redis --queue=default'),
    ('php "' + $artisan + '" queue:work database --queue=default-otro'),
    ('php "' + $artisan + '-otro" queue:work database --queue=default'),
    ('php otra-' + $artisan + ' queue:work database --queue=default'),
    ''
)) {
    Assert-Prueba (-not (Test-ProcesoCola ([pscustomobject]@{ Name = 'php.exe'; CommandLine = $comando }) $artisan)) 'No adopta otro proceso'
}
Assert-Prueba (-not (Test-ProcesoCola $null $artisan)) 'Proceso inexistente'
Assert-Prueba (-not (Test-ProcesoCola ([pscustomobject]@{ Name = 'node.exe'; CommandLine = 'queue:work database --queue=default' }) $artisan)) 'Otro ejecutable'

# Simular CIM solamente dentro de este proceso de pruebas: protege contra PID reutilizado.
$fechaPrueba = [datetime]'2026-01-01T00:00:00Z'
function Get-CimInstance {
    param($ClassName, $Filter, $ErrorAction)
    return [pscustomobject]@{ Name = 'php.exe'; ProcessId = 123; CreationDate = $fechaPrueba; CommandLine = 'php artisan queue:work database --queue=default' }
}
Assert-Prueba ($null -ne (Get-ProcesoAnteriorCola 123 $fechaPrueba.ToUniversalTime().Ticks)) 'Adopcion con fecha exacta'
Assert-Prueba ($null -eq (Get-ProcesoAnteriorCola 123 ($fechaPrueba.ToUniversalTime().Ticks + 1))) 'Rechaza PID reutilizado'
Assert-Prueba ($null -eq (Get-ProcesoAnteriorCola 0 0)) 'Sin proceso previo'
Write-Output "$cantidad comprobaciones correctas. Sin cambios en la base de datos."

