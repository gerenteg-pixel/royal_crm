<?php
declare(strict_types=1);
/* Simulador SOLO LECTURA. Colocar en /public_html/whatsapp/ junto a conexion_whatsapp.php.
   No envia mensajes, no modifica asignaciones, no ejecuta IA. */
ini_set('display_errors', '0');
require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../empresa_core.php';
require_permiso('ver_whatsapp_panel');
require_modulo('whatsapp_activo');
require_once __DIR__.'/conexion_whatsapp.php';
require_once __DIR__.'/auth_whatsapp.php';
wa_require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
function rai_preview_reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$role = strtolower(trim((string)($_SESSION['rol'] ?? '')));
if (!in_array($role, ['admin', 'superadmin'], true)) {
    rai_preview_reply(403, ['ok'=>false, 'error'=>'Solo administracion']);
}
$empresa = (int)wa_empresa_id();
if ($empresa <= 0 || !isset($connWa) || !($connWa instanceof mysqli)) {
    rai_preview_reply(503, ['ok'=>false, 'error'=>'Conexion no disponible']);
}
$config = require __DIR__.'/royal_ai_sales_config.php';
if (($config['mode'] ?? '') !== 'simulation' || ($config['outbound_enabled'] ?? true) !== false) {
    rai_preview_reply(409, ['ok'=>false, 'error'=>'El simulador exige envios deshabilitados']);
}
$limit = min(300, max(10, (int)($config['max_rows'] ?? 200)));
$window = min(1800, max(60, (int)($config['lookback_seconds'] ?? 300)));
$agents = [];
$instances = [];
foreach (($config['agents'] ?? []) as $id=>$a) {
    $instance = trim((string)($a['instance_id'] ?? ''));
    $enabled = ($a['enabled'] ?? false) === true;
    $agents[(string)$id] = [
        'name' => (string)($a['name'] ?? ('Agente '.$id)),
        'configured' => $enabled && $instance !== '',
        'instance_id' => $instance,
        'inbound_seen' => 0,
        'outbound_seen' => 0,
        'candidate_conversations' => 0,
        'status' => $enabled && $instance !== '' ? 'observacion' : 'sin_configurar',
    ];
    if ($enabled && $instance !== '') $instances[$instance][] = (string)$id;
}
try {
    /* No usar el contenido del mensaje ni devolver telefono: solo agregados.
       Esta consulta observa el CANAL, no atribuye mensajes a agentes IA. */
    $sql = 'SELECT instance_id, direction, event_time, enviado_por FROM wa_messages WHERE empresa_id=? ORDER BY id DESC LIMIT '.$limit;
    $stmt = $connWa->prepare($sql);
    if (!$stmt) throw new RuntimeException('No se pudo preparar consulta');
    $stmt->bind_param('i', $empresa);
    if (!$stmt->execute()) throw new RuntimeException('No se pudo ejecutar consulta');
    $res = $stmt->get_result();
    $cutoff = time() - $window;
    $unique = [];
    $sampled = 0;
    while ($row = $res->fetch_assoc()) {
        $sampled++;
        $instance = trim((string)($row['instance_id'] ?? ''));
        if (!isset($instances[$instance])) continue;
        $ts = strtotime((string)($row['event_time'] ?? ''));
        if (!$ts || $ts < $cutoff || $ts > time()+60) continue;
        $dir = (string)($row['direction'] ?? '');
        if (!in_array($dir, ['in','out'], true)) continue;
        foreach ($instances[$instance] as $id) {
            if ($dir === 'in') {
                $agents[$id]['inbound_seen']++;
                $phone = (string)($row['enviado_por'] ?? '');
                if ($phone !== '') $unique[$id][hash('sha256', $phone)] = true;
            } else {
                $agents[$id]['outbound_seen']++;
            }
        }
    }
    $stmt->close();
    foreach ($agents as $id=>&$a) {
        $a['candidate_conversations'] = count($unique[$id] ?? []);
    }
    unset($a);
    rai_preview_reply(200, [
        'ok'=>true, 'mode'=>'simulation', 'outbound_enabled'=>false,
        'message'=>'Solo observacion de canales. Ninguna actividad se atribuye a la IA.',
        'window_seconds'=>$window, 'sample_limit'=>$limit, 'rows_scanned'=>$sampled,
        'agents'=>array_values($agents), 'observed_at'=>date('c'),
    ]);
} catch (Throwable $e) {
    error_log('Royal AI Sales preview: '.$e->getMessage());
    rai_preview_reply(503, ['ok'=>false, 'error'=>'Simulador no disponible']);
}
