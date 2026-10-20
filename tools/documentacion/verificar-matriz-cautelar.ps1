param([string]$Json = '')
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$raiz = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$ruta = Join-Path $raiz 'docs/contratos/medidas-cautelares-bolivia.borrador.json'
if ([string]::IsNullOrWhiteSpace($Json)) { $Json = Get-Content -LiteralPath $ruta -Raw -Encoding UTF8 }
$matriz = $Json | ConvertFrom-Json
$cantidad = 0
function Assert-Documento {
    param([bool]$Condicion, [string]$Mensaje)
    if (-not $Condicion) { throw "Contrato documental invalido: $Mensaje" }
    $script:cantidad++
}
Assert-Documento ($matriz.version -eq '1.0') 'Version inesperada'
Assert-Documento ($matriz.uso -eq 'documentacion_no_ejecutable') 'No es documentacion aislada'
Assert-Documento ($matriz.jurisdiccion -eq 'BO') 'Jurisdiccion inesperada'
Assert-Documento ($matriz.escenario -eq 'solicitud_fiscal_inicial_etapa_preparatoria') 'Escenario inesperado'
Assert-Documento ($matriz.activo -is [bool] -and -not $matriz.activo) 'El borrador no puede estar activo'
Assert-Documento ($matriz.revision_juridica -eq 'pendiente') 'No inventar aprobacion juridica'
Assert-Documento ($matriz.vigencia_normativa -eq 'pendiente_cotejo_integral') 'No certificar vigencia sin cotejo'
Assert-Documento ($null -eq $matriz.revisor_juridico -and $null -eq $matriz.fecha_aprobacion) 'No inventar revisor o firma'
Assert-Documento (Test-Path -LiteralPath (Join-Path (Split-Path $ruta -Parent) $matriz.documento) -PathType Leaf) 'Documento asociado inexistente'
Assert-Documento (-not [string]::IsNullOrWhiteSpace($matriz.criterio_participacion)) 'Falta criterio de participantes'
Assert-Documento (-not [string]::IsNullOrWhiteSpace($matriz.criterio_revision)) 'Falta separacion de revisiones'

$fuentes = @($matriz.fuentes.id)
$roles = @($matriz.participantes.id)
$acciones = @($matriz.acciones_conceptuales)
$etapas = @($matriz.etapas_propuestas.id)
foreach ($lista in @($fuentes, $roles, $acciones, $etapas)) {
    Assert-Documento ($lista.Count -gt 0 -and @($lista | Select-Object -Unique).Count -eq $lista.Count) 'IDs vacios o duplicados'
    foreach ($id in $lista) { Assert-Documento ($id -cmatch '^[a-z][a-z0-9_-]+$') 'ID no normalizado' }
}
foreach ($fuente in $matriz.fuentes) {
    $uri = [uri]$fuente.url
    Assert-Documento ($uri.IsAbsoluteUri -and $uri.Scheme -eq 'https' -and $uri.Host -in @('tsj.bo', 'backend.academico.eje.gob.bo', 'www.silep.gob.bo')) 'Fuente no oficial esperada'
    Assert-Documento (-not [string]::IsNullOrWhiteSpace($fuente.localizador) -and -not [string]::IsNullOrWhiteSpace($fuente.consulta)) 'Fuente sin localizador o tipo de consulta'
}
$implementados = @($matriz.participantes | Where-Object turno_disponible_en_api | ForEach-Object id)
Assert-Documento ($implementados.Count -eq 3 -and @($implementados | Where-Object { $_ -notin @('juez', 'fiscal', 'abogado_defensor') }).Count -eq 0) 'Soporte API declarado incorrectamente'
foreach ($etapa in $matriz.etapas_propuestas) {
    Assert-Documento (@($etapa.participantes).Count -gt 0 -and @($etapa.participantes | Where-Object { $_ -notin $roles }).Count -eq 0) 'Participante no definido'
    Assert-Documento (@($etapa.acciones).Count -gt 0 -and @($etapa.acciones | Where-Object { $_ -notin $acciones }).Count -eq 0) 'Accion no definida'
    Assert-Documento (@($etapa.fuentes).Count -gt 0 -and @($etapa.fuentes | Where-Object { $_ -notin $fuentes }).Count -eq 0) 'Referencia no definida'
    Assert-Documento (@($etapa.condiciones_tecnicas).Count -gt 0) 'Etapa sin condiciones'
    foreach ($destino in $etapa.salidas_propuestas) {
        Assert-Documento ($destino -in $etapas) 'Destino inexistente'
        Assert-Documento ([Array]::IndexOf($etapas, $destino) -gt [Array]::IndexOf($etapas, $etapa.id)) 'Ciclo o retroceso no documentado'
    }
}
Assert-Documento ($etapas[0] -eq 'preparacion_tecnica' -and $etapas[-1] -eq 'cierre_y_registro') 'Inicio o cierre inesperados'
Assert-Documento (@($matriz.etapas_propuestas[-1].salidas_propuestas).Count -eq 0) 'El cierre no tiene salida'
$visitadas = New-Object 'System.Collections.Generic.HashSet[string]'
$pendientes = New-Object 'System.Collections.Generic.Queue[string]'
$pendientes.Enqueue($etapas[0])
while ($pendientes.Count -gt 0) {
    $idActual = $pendientes.Dequeue()
    if ($visitadas.Add($idActual)) {
        $nodo = @($matriz.etapas_propuestas | Where-Object id -EQ $idActual)[0]
        foreach ($destino in $nodo.salidas_propuestas) { $pendientes.Enqueue($destino) }
    }
}
Assert-Documento ($visitadas.Count -eq $etapas.Count) 'Etapas inaccesibles desde el inicio'
foreach ($requerida in @('no_importar_como_catalogo_activo', 'no_ocultar_participantes_no_soportados', 'no_avanzar_sin_reglas_revisadas_ni_contradiccion', 'no_decidir_rutas_juridicas_automaticamente_con_llm', 'no_fijar_plazos_por_defecto', 'no_enviar_contexto_externo_sin_autorizacion_especifica')) {
    Assert-Documento ($requerida -in $matriz.restricciones_tecnicas) 'Falta guarda obligatoria'
}
foreach ($exclusion in @('cesacion', 'ampliacion', 'revocatoria', 'juicio_oral', 'apelacion', 'activacion_juridica')) {
    Assert-Documento ($exclusion -in $matriz.fuera_de_alcance) 'Falta exclusion de alcance'
}
Write-Output "$cantidad comprobaciones documentales correctas; no valida la ley ni activa reglas."
