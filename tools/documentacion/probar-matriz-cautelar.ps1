$ErrorActionPreference = 'Stop'
$verificador = Join-Path $PSScriptRoot 'verificar-matriz-cautelar.ps1'
$raiz = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$original = Get-Content -LiteralPath (Join-Path $raiz 'docs/contratos/medidas-cautelares-bolivia.borrador.json') -Raw -Encoding UTF8
& $verificador
$casos = @(
    @{ Nombre = 'activacion accidental'; Cambiar = { param($d) $d.activo = $true } },
    @{ Nombre = 'aprobacion ficticia'; Cambiar = { param($d) $d.revision_juridica = 'aprobado' } },
    @{ Nombre = 'actor desconocido'; Cambiar = { param($d) $d.etapas_propuestas[0].participantes = @('actor_desconocido') } },
    @{ Nombre = 'fuente no esperada'; Cambiar = { param($d) $d.fuentes[0].url = 'https://example.org/ley' } },
    @{ Nombre = 'ciclo no documentado'; Cambiar = { param($d) $d.etapas_propuestas[4].salidas_propuestas = @('instalacion') } },
    @{ Nombre = 'bloques inaccesibles'; Cambiar = { param($d) $d.etapas_propuestas[0].salidas_propuestas = @() } },
    @{ Nombre = 'soporte ficticio'; Cambiar = { param($d) $d.participantes[3].turno_disponible_en_api = $true } },
    @{ Nombre = 'guarda eliminada'; Cambiar = { param($d) $d.restricciones_tecnicas = @($d.restricciones_tecnicas | Where-Object { $_ -ne 'no_importar_como_catalogo_activo' }) } }
)
foreach ($caso in $casos) {
    $datos = $original | ConvertFrom-Json
    $modificar = $caso.Cambiar
    & $modificar $datos
    $rechazado = $false
    try { & $verificador -Json ($datos | ConvertTo-Json -Depth 15) | Out-Null }
    catch {
        if ($_.Exception.Message -notlike 'Contrato documental invalido:*') { throw }
        $rechazado = $true
    }
    if (-not $rechazado) { throw "No se rechazo: $($caso.Nombre)" }
    Write-Output "Rechazo verificado: $($caso.Nombre)"
}
Write-Output '8 pruebas negativas correctas. Sin archivos temporales, PostgreSQL ni proveedores.'
