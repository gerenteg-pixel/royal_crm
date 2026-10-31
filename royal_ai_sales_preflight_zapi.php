<?php
declare(strict_types=1);
/** Royal AI Sales: diagnostico CLI de lectura de secretos; no envia mensajes. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
ini_set('display_errors', '0');
header_remove();
function rai_preflight_out(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
}
try {
    $configPath = __DIR__ . '/royal_ai_sales_auto_config.php';
    if (!is_file($configPath)) throw new RuntimeException('auto_config_no_encontrado');
    $cfg = require $configPath;
    if (!is_array($cfg)) throw new RuntimeException('auto_config_invalido');
    $agent = $cfg['agents'][1] ?? [];
    $instance = (string)($agent['instance_id'] ?? '');
    $phone = (string)($agent['allowed_phones'][0] ?? '');
    $safe = (int)($cfg['company_id'] ?? 0) === 1
        && $instance === '3F8EA469A3BB11D54983B63E388305A1'
        && strcasecmp((string)($agent['human_owner'] ?? ''), 'Jefry12') === 0
        && $phone === '50432775549'
        && count($agent['allowed_phones'] ?? []) === 1
        && ($cfg['send_enabled'] ?? null) === false
        && ($agent['send_enabled'] ?? null) === false;
    // __DIR__ = public_html/whatsapp; dirname(__DIR__, 2) = cuenta de dominio.
    $secretPath = dirname(__DIR__, 2) . '/zapi_secrets/zapi_secrets.php';
    $exists = is_file($secretPath);
    $readable = $exists && is_readable($secretPath);
    $hasToken = false;
    if ($readable) {
        $secret = (static function (string $file): mixed { return require $file; })($secretPath);
        $hasToken = is_array($secret) && isset($secret['client_token'])
            && is_string($secret['client_token']) && trim($secret['client_token']) !== '';
        unset($secret);
    }
    rai_preflight_out([
        'ok' => $safe && $hasToken,
        'mode' => 'diagnostico_sin_envio',
        'configuracion_prueba_segura' => $safe,
        'archivo_secreto_existe' => $exists,
        'archivo_secreto_legible' => $readable,
        'client_token_disponible' => $hasToken,
        'sends' => 0,
    ]);
} catch (Throwable $e) {
    error_log('Royal AI preflight: ' . $e->getMessage());
    rai_preflight_out(['ok'=>false,'mode'=>'diagnostico_fallido','error'=>'revision_necesaria','sends'=>0]);
}
