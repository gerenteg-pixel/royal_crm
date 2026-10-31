<?php
declare(strict_types=1);
/* Instalar JUNTO a panel_whatsapp.php. Solo lectura, sin modificar asignaciones. */
ini_set('display_errors', '0');
require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../empresa_core.php';
require_permiso('ver_whatsapp_panel');
require_once __DIR__.'/conexion_whatsapp.php';
require_once __DIR__.'/auth_whatsapp.php';
wa_require_login();
require_modulo('whatsapp_activo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
$role = strtolower(trim((string)($_SESSION['rol'] ?? '')));
if (!in_array($role, ['admin','superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Solo administración']);
    exit;
}
$empresa = (int)wa_empresa_id();
if ($empresa <= 0 || !isset($connWa) || !($connWa instanceof mysqli)) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Conexión no disponible']);
    exit;
}
try {
    /* Limitado a los últimos 300 mensajes de esta empresa; no retorna teléfonos ni contenido. */
    $stmt=$connWa->prepare('SELECT instance_id, direction, event_time FROM wa_messages WHERE empresa_id = ? ORDER BY id DESC LIMIT 300');
    if (!$stmt) throw new RuntimeException('Consulta no disponible');
    $stmt->bind_param('i',$empresa);
    if (!$stmt->execute()) throw new RuntimeException('No se pudo consultar');
    $res=$stmt->get_result();
    $cutoff=time()-300;
    $totals=[];
    while ($m=$res->fetch_assoc()) {
        $instance=trim((string)($m['instance_id']??''));
        $dir=(string)($m['direction']??'');
        $time=strtotime((string)($m['event_time']??''));
        if ($instance==='' || !in_array($dir,['in','out'],true) || !$time || $time<$cutoff || $time>time()+60) continue;
        if (!isset($totals[$instance])) $totals[$instance]=['in'=>0,'out'=>0,'total'=>0];
        $totals[$instance][$dir]++;
        $totals[$instance]['total']++;
    }
    $stmt->close();
    echo json_encode(['ok'=>true,'window_seconds'=>300,'sample_limit'=>300,'instances'=>$totals,'observed_at'=>date('c')],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch(Throwable $e) {
    error_log('Royal AI Chat monitor: '.$e->getMessage());
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Monitor no disponible']);
}
