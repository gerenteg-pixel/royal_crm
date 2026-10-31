<?php
declare(strict_types=1);
/** Motor determinista aislado. Sin red, SQL ni escrituras. */
function rai_cotizacion(array $in): array {
    $producto = trim((string)($in['producto'] ?? ''));
    $destino = trim((string)($in['destino'] ?? ''));
    $base = $in['precio_base_verificado'] ?? null;
    $min = $in['minutos_google_maps_verificados'] ?? null;
    $retiro = ($in['retiro_en_bodega'] ?? false) === true;
    $ciudad = strtolower($destino);
    $capital = in_array($ciudad, ['tegucigalpa','comayaguela','distrito central'], true);
    if ($producto === '' || $destino === '') return ['estado'=>'faltan_datos','accion'=>'Solicitar producto y ciudad o municipio de entrega'];
    if (!is_numeric($base) || (float)$base <= 0) return ['estado'=>'requiere_validacion','accion'=>'Confirmar precio base oficial del producto'];
    if ($retiro) $flete = 0;
    elseif ($capital) $flete = 600;
    elseif (!is_int($min) || $min < 1) return ['estado'=>'requiere_maps','accion'=>'Consultar duracion de ruta en Google Maps desde Tegucigalpa hasta el municipio; no usar kilometros'];
    else {
        // Solo tramos expresamente corroborados; los demas requieren tarifa oficial.
        if ($min <= 60) $flete = 1100;
        elseif ($min <= 90) $flete = 1600;
        elseif ($min <= 120) $flete = 2200;
        else return ['estado'=>'requiere_tarifa','accion'=>'Validar tarifa oficial para rutas mayores a dos horas; no extrapolar'];
    }
    $precio = round((float)$base + $flete, 2);
    return ['estado'=>'cotizacion_referencial','producto'=>$producto,'destino'=>$destino,'precio_final_hnl'=>$precio,'desglose_interno'=>['base_hnl'=>(float)$base,'flete_hnl'=>$flete], 'requiere_confirmacion_logistica'=>true,'mensaje_cliente'=>sprintf('El precio referencial de %s con %s es de L%s, sujeto a confirmacion de disponibilidad y entrega.', $producto, $retiro?'retiro en bodega':'entrega en '.$destino,number_format($precio,2,'.',','))];
}
function rai_ruta_compartida(array $ruta): array {
    // Nunca se presupone que una ruta existe o que tiene cupo.
    foreach (['ruta_confirmada','cupo_confirmado','destino_en_trayecto','costo_incremental_verificado','autorizacion_logistica'] as $campo) {
        if (!array_key_exists($campo,$ruta)) return ['estado'=>'requiere_validacion','faltante'=>$campo];
    }
    if (!$ruta['ruta_confirmada'] || !$ruta['cupo_confirmado'] || !$ruta['destino_en_trayecto'] || !$ruta['autorizacion_logistica']) return ['estado'=>'no_autorizada'];
    if (!is_numeric($ruta['costo_incremental_verificado']) || $ruta['costo_incremental_verificado'] < 0) return ['estado'=>'requiere_validacion','faltante'=>'costo_incremental_verificado'];
    return ['estado'=>'candidata_autorizada','costo_incremental_hnl'=>(float)$ruta['costo_incremental_verificado'],'nota'=>'No fijar precio final sin margen y precio base oficial verificados'];
}
