<?php
declare(strict_types=1);
/**
 * Royal AI Sales - puente de cotizacion contextual.
 * Solo lectura. No escribe SQL, no envia mensajes, no modifica rutas.
 * Conserva la firma y el formato de respuesta del worker existente.
 */
if (!function_exists('rai_google_quote')) {
function rai_google_quote(array $history, array $catalog, mysqli $crm, int $empresaId = 1): array {
    $fold = static function (string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        return strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    };
    $users = [];
    foreach ($history as $entry) {
        if (($entry['role'] ?? '') === 'user') {
            $t = trim((string)($entry['content'] ?? ''));
            if ($t !== '') $users[] = $fold($t);
        }
    }
    if (!$users) return ['ok'=>false,'status'=>'need_verified_product'];
    $latest = end($users);

    // Seleccionar el producto especifico mas reciente, no un producto viejo.
    // Excluir nombres genericos y no usar coincidencias parciales como "mixto" para "mixta".
    $products = [];
    foreach ($catalog as $item) {
        $name = $fold((string)($item['nombre'] ?? ''));
        if ($name === '') continue;
        $words = preg_split('/\s+/u', $name) ?: [];
        $distinct = array_values(array_filter($words, static function ($w) {
            return mb_strlen($w, 'UTF-8') >= 4 && !in_array($w, ['paca','pacas','ropa','usada','surtida','caja','cajas','variada','variedad'], true);
        }));
        $products[] = ['name'=>$name,'tokens'=>$distinct,'item'=>$item];
    }
    $product = null;
    for ($i = count($users)-1; $i >= 0; $i--) {
        $msg = $users[$i];
        $matches = [];
        foreach ($products as $candidate) {
            $found = preg_match('/(?<![\p{L}])'.preg_quote($candidate['name'],'/').'(?![\p{L}])/u', $msg) === 1;
            if (!$found && $candidate['tokens']) {
                foreach ($candidate['tokens'] as $token) {
                    if (preg_match('/(?<![\p{L}])'.preg_quote($token,'/').'(?![\p{L}])/u', $msg)) {
                        $found = true;
                        break;
                    }
                }
            }
            if ($found) $matches[(string)($candidate['item']['id'] ?? $candidate['name'])] = $candidate['item'];
        }
        if (count($matches) > 1) return ['ok'=>false,'status'=>'ambiguous_product'];
        if (count($matches) === 1) { $product = reset($matches); break; }
    }
    if ($product === null) return ['ok'=>false,'status'=>'need_verified_product'];
    $name = (string)$product['nombre'];
    $base = $product['precio_base_hnl_verificado'] ?? null;
    if (!is_numeric($base) || !is_finite((float)$base) || (float)$base <= 0) return ['ok'=>false,'status'=>'unverified_price'];
    if ((int)($product['stock_registrado_no_confirmado'] ?? 0) <= 0) return ['ok'=>false,'status'=>'stock_not_available'];
    if (preg_match('/\b(?:recoger|recogida|retiro|retirar|bodega|pasar a traer)\b/u', $latest)) return ['ok'=>false,'status'=>'pickup_requires_authorized_price'];

    // Primero destinos claros y verificables. La capital se reconoce aun en frases naturales.
    // La lista solo sirve para reconocer la capital; las demas ciudades siguen usando Google Maps.
    $destination = '';
    $capitalPattern = '/(?<![\p{L}])(?:tegucigalpa|comayaguela|distrito\s+central)(?![\p{L}])/u';
    $deptPattern = '/(?<![\p{L}])francisco\s+morazan(?![\p{L}])/u';
    for ($i = count($users)-1; $i >= 0; $i--) {
        $msg = $users[$i];
        if (preg_match($capitalPattern, $msg, $m)) {
            $city = $m[0] === 'comayaguela' ? 'Comayaguela' : ($m[0] === 'distrito central' ? 'Distrito Central' : 'Tegucigalpa');
            $destination = $city.(preg_match($deptPattern, $msg) ? ', Francisco Morazan' : '');
            break;
        }
        // Departamento solo no equivale a municipio.
        if (preg_match($deptPattern, $msg) && !preg_match('/\b(?:municipio|ciudad|aldea|colonia)\b/u', $msg)) continue;
        $candidate = '';
        if (preg_match('/\b(?:vivo en|soy de|estoy en|ubicad[oa] en|entregar en|entrega en|enviar a|mandar a|destino es|municipio de|ciudad de|seria en|seria para|seria a|queda en|estaria en|la entrega es en|la direccion es en)\s+([a-z][a-z\s,.-]{2,75})/u', $msg, $m)) {
            $candidate = $m[1];
        } elseif (preg_match('/^(?:en\s+)?([a-z][a-z\s.-]{2,48})(?:\s*,\s*([a-z][a-z\s.-]{2,40}))?\s*[.!?]*$/u', $msg, $m)) {
            $candidate = $m[1].(empty($m[2]) ? '' : ', '.$m[2]);
        }
        $candidate = preg_split('/\s+(?:cuanto|cuesta|vale|precio|paca|por favor|gracias|necesito|quiero|me sale|me cuesta|seria|sale|con entrega|con envio|pero|porque|que|y|ya|incluido|incluida)\b/u', trim($candidate), 2)[0] ?? '';
        $candidate = trim($candidate, " \t\n\r\0\x0B,.-");
        if ($candidate === '' || mb_strlen($candidate,'UTF-8') < 3) continue;
        if (preg_match('/\b(?:que|precio|cotice|cotizar|me|la|el|una|un|paca|foto|fotos|cuanto|cuesta|vale|sale|quiero|necesito|gracias|favor|usted|diga|dame|dar|ver|saber|si|claro|bueno|perfecto|interesa|compro|comprar|ganancia|negocio|ropa|casa)\b/u', $candidate)) continue;
        if (preg_match('/\b(?:guatemala|nicaragua|salvador|mexico|estados unidos)\b/u', $candidate)) continue;
        if (count(preg_split('/\s+/u', $candidate)) > 6) continue;
        $destination = mb_convert_case($candidate, MB_CASE_TITLE, 'UTF-8');
        break;
    }
    if ($destination === '') return ['ok'=>false,'status'=>'need_city','instructions'=>'Solicitar solo el municipio de entrega; si ya indico el municipio, no volver a pedirlo.'];

    $normalizedDestination = $fold($destination);
    $local = (bool)preg_match('/^(?:tegucigalpa|comayaguela|distrito central)(?:\s*,\s*(?:francisco morazan|honduras))?$/u', $normalizedDestination);
    $root = dirname(__DIR__);
    if (!is_file($root.'/royal_rutas_core.php')) return ['ok'=>false,'status'=>'missing_routes_files'];
    require_once $root.'/royal_rutas_core.php';
    if (!function_exists('royal_rutas_config')) return ['ok'=>false,'status'=>'missing_route_functions'];
    try { $cfg = royal_rutas_config($crm, $empresaId); }
    catch (Throwable $e) {
        error_log('Royal AI rutas configuracion: '.$e->getMessage());
        return ['ok'=>false,'status'=>'route_config_unavailable'];
    }
    $origin = trim((string)($cfg['origen_texto'] ?? ''));
    if ($origin === '') return ['ok'=>false,'status'=>'route_config_unavailable'];
    $seconds = 0;
    $billMinutes = null;
    if ($local) {
        // Validar ciudad canonica para evitar diferencias de formato entre archivos.
        $canonical = preg_match('/^comayaguela/u', $normalizedDestination) ? 'Comayaguela' : 'Tegucigalpa';
        if (function_exists('royal_rutas_es_local') && !royal_rutas_es_local($canonical, 'Francisco Morazan')) {
            return ['ok'=>false,'status'=>'requires_logistics_validation'];
        }
        $charge = $cfg['tarifa_local'] ?? null;
    } else {
        if (!is_file($root.'/royal_rutas_google.php')) return ['ok'=>false,'status'=>'missing_routes_files'];
        require_once $root.'/royal_rutas_google.php';
        if (!function_exists('royal_google_route') || !function_exists('royal_rutas_minutos_facturables') || !function_exists('royal_rutas_costo_por_minutos')) return ['ok'=>false,'status'=>'missing_route_functions'];
        try {
            $route = royal_google_route([$origin, $destination.', Honduras']);
            if (!is_array($route) || empty($route['ok'])) return ['ok'=>false,'status'=>'google_unavailable'];
            $seconds = (int)($route['duration_seconds'] ?? 0);
            if ($seconds <= 0) return ['ok'=>false,'status'=>'invalid_duration'];
            $billMinutes = royal_rutas_minutos_facturables($seconds, (int)($cfg['redondeo_umbral_min'] ?? 15));
            if ($billMinutes > 720) return ['ok'=>false,'status'=>'requires_logistics_validation'];
            $charge = royal_rutas_costo_por_minutos($billMinutes, $cfg, false);
        } catch (Throwable $e) {
            error_log('Royal AI calculo ruta: '.$e->getMessage());
            return ['ok'=>false,'status'=>'route_calculation_failed'];
        }
    }
    if (!is_numeric($charge) || !is_finite((float)$charge) || (float)$charge <= 0) return ['ok'=>false,'status'=>'invalid_freight'];
    $total = round((float)$base + (float)$charge, 2);
    $display = number_format($total, ($total === floor($total) ? 0 : 2), '.', ',');
    return [
        'ok'=>true, 'status'=>'quoted', 'product'=>$name, 'city'=>$destination,
        'price_hnl'=>$total, 'product_base_hnl_internal'=>(float)$base,
        'duration_minutes_google'=>$local ? null : (int)ceil($seconds/60),
        'duration_minutes_billed'=>$billMinutes, 'freight_hnl_internal'=>(float)$charge,
        'customer_text'=>'La '.$name.' puesta en '.$destination.' le sale en L'.$display.', ya con entrega incluida.',
        'route_shared_status'=>'requires_logistics_validation'
    ];
}
}
