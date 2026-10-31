<?php
declare(strict_types=1);
/** Royal AI Sales: envio manual aislado, solo numero de prueba. */
ini_set('display_errors', '0');
date_default_timezone_set('America/Tegucigalpa');
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/empresa_core.php';
require_permiso('ver_whatsapp_panel');
require_modulo('whatsapp_activo');
require_once __DIR__ . '/conexion_whatsapp.php';
require_once __DIR__ . '/auth_whatsapp.php';
wa_require_login();
if (!in_array(strtolower((string)($_SESSION['rol'] ?? '')), ['admin', 'superadmin'], true)) {
    http_response_code(403); exit('Solo administracion');
}
if (!isset($connWa) || !($connWa instanceof mysqli) || (int)wa_empresa_id() !== 1) {
    http_response_code(403); exit('Empresa no autorizada');
}
$empresa = 1;
$instance = '3F8EA469A3BB11D54983B63E388305A1';
$phone = '50432775549';
$owner = 'Jefry12';
$saludo = '¡Hola, buenas tardes! Mi nombre es Carlos, soy tu asesor de ventas. Con mucho gusto te atiendo. ¿En qué puedo ayudarte hoy?';
// Mantener APAGADO hasta auditar el backend de envio y aprobar la prueba.
const ROYAL_AI_SALES_PERMITIR_ENVIO_MANUAL = true;
function rai_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rai_owner_ok(?array $row, string $expected): bool {
    return $row !== null && strcasecmp(trim((string)($row['asignado_a'] ?? '')), $expected) === 0;
}
function rai_assignment(mysqli $db, int $empresa, string $phone): ?array {
    $q = $db->prepare('SELECT id, asignado_a, instance_id FROM wa_assignments WHERE empresa_id=? AND telefono=? LIMIT 1');
    if (!$q) throw new RuntimeException('No se pudo consultar asignacion');
    $q->bind_param('is', $empresa, $phone);
    $q->execute();
    $row = $q->get_result()->fetch_assoc() ?: null;
    $q->close();
    return $row;
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['rai_send_csrf'])) $_SESSION['rai_send_csrf'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['rai_send_csrf'];
$error = ''; $ok = ''; $last = null; $assigned = false; $used = false; $assignment = null; $everUsed = false; $lockHeld = false;
try {
    $config = require __DIR__ . '/royal_ai_sales_config.php';
    if (!is_array($config) || ($config['mode'] ?? '') !== 'simulation' || ($config['outbound_enabled'] ?? true) !== false) {
        throw new RuntimeException('Configuracion de simulacion no valida');
    }
    $agent = $config['agents'][1] ?? [];
    if (($agent['enabled'] ?? false) !== true || (string)($agent['instance_id'] ?? '') !== $instance) {
        throw new RuntimeException('Carlos no esta vinculado a Jefry12');
    }
    // Validar que el identificador de canal corresponda a Jefry12 en esta empresa.
    $q = $connWa->prepare('SELECT nombre, activa FROM wa_instancias WHERE empresa_id=? AND instance_id=? LIMIT 1');
    $q->bind_param('is', $empresa, $instance); $q->execute();
    $instRow = $q->get_result()->fetch_assoc(); $q->close();
    if (!$instRow || (int)$instRow['activa'] !== 1 || strcasecmp(trim((string)$instRow['nombre']), $owner) !== 0) {
        throw new RuntimeException('La instancia privada no corresponde a Jefry12');
    }
    $q = $connWa->prepare("SELECT id, wa_conversation_id, enviado_por FROM wa_messages WHERE empresa_id=? AND instance_id=? AND direction='in' ORDER BY id DESC LIMIT 1000");
    $q->bind_param('is', $empresa, $instance); $q->execute();
    $rows = $q->get_result(); $cid = 0;
    while ($m = $rows->fetch_assoc()) {
        $p = preg_replace('/\D+/', '', (string)$m['enviado_por']);
        if (strlen($p) === 8) $p = '504' . $p;
        if ($p === $phone) { $cid = (int)$m['wa_conversation_id']; break; }
    }
    $q->close();
    if (!$cid) throw new RuntimeException('No se encontro mensaje entrante del numero de prueba');
    $q = $connWa->prepare('SELECT id, direction, texto FROM wa_messages WHERE empresa_id=? AND instance_id=? AND wa_conversation_id=? ORDER BY id DESC LIMIT 1');
    $q->bind_param('isi', $empresa, $instance, $cid); $q->execute();
    $last = $q->get_result()->fetch_assoc(); $q->close();
    if (!$last || $last['direction'] !== 'in') throw new RuntimeException('Ultimo mensaje no es entrante: posible intervencion humana');
    // Propietario humano y canal de entrada son conceptos independientes.
    // La asignacion puede guardar el instance_id historico del canal RR.
    $assignment = rai_assignment($connWa, $empresa, $phone);
    $assigned = rai_owner_ok($assignment, $owner);
    if (!$assigned) throw new RuntimeException('Responsable humano Jefry12 no verificado; envio bloqueado');
    $incomingId = (int)$last['id'];
    $q = $connWa->prepare('SELECT id, estado FROM royal_ai_sales_envios WHERE empresa_id=? AND instance_id=? AND incoming_message_id=? LIMIT 1');
    $q->bind_param('isi', $empresa, $instance, $incomingId); $q->execute();
    $used = $q->get_result()->fetch_assoc() ?: false; $q->close();
    // Una sola prueba total para este numero, aunque llegue otro mensaje despues.
    $q = $connWa->prepare('SELECT id, estado FROM royal_ai_sales_envios WHERE empresa_id=? AND instance_id=? AND telefono=? ORDER BY id DESC LIMIT 1');
    if (!$q) throw new RuntimeException('No se pudo verificar el historial de intentos');
    $q->bind_param('iss', $empresa, $instance, $phone); $q->execute();
    $everUsed = $q->get_result()->fetch_assoc() ?: false; $q->close();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!ROYAL_AI_SALES_PERMITIR_ENVIO_MANUAL) throw new RuntimeException('Cortacircuito apagado: ningun mensaje fue enviado');
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('CSRF invalido');
        if ((string)($_POST['confirmacion'] ?? '') !== 'ENVIAR SOLO PRUEBA') throw new RuntimeException('Confirmacion incorrecta');
        if ($used || $everUsed) throw new RuntimeException('Ya existe un intento para este numero; prueba unica bloqueada');
        if (trim((string)($last['texto'] ?? '')) !== 'Hola buenas tardes') throw new RuntimeException('El ultimo mensaje no es el saludo de prueba aprobado');
        // Serializar administradores para que dos mensajes distintos no disparen dos envios.
        $lockName = 'rai_carlos_unico_1_5549';
        $q = $connWa->prepare('SELECT GET_LOCK(?, 0) AS acquired');
        if (!$q) throw new RuntimeException('No se pudo obtener bloqueo de prueba');
        $q->bind_param('s', $lockName); $q->execute();
        $lockResult = $q->get_result()->fetch_assoc(); $q->close();
        if ((int)($lockResult['acquired'] ?? 0) !== 1) throw new RuntimeException('Otra operacion esta en curso; envio bloqueado');
        $lockHeld = true;
        $q = $connWa->prepare('SELECT id FROM royal_ai_sales_envios WHERE empresa_id=? AND instance_id=? AND telefono=? LIMIT 1');
        $q->bind_param('iss', $empresa, $instance, $phone); $q->execute();
        $previous = $q->get_result()->fetch_assoc(); $q->close();
        if ($previous) throw new RuntimeException('Ya existe un intento anterior; envio bloqueado');
        $actor = (string)($_SESSION['usuario'] ?? 'admin');
        $q = $connWa->prepare("INSERT INTO royal_ai_sales_envios (empresa_id,instance_id,telefono,wa_conversation_id,incoming_message_id,mensaje,estado,creado_por) VALUES (?,?,?,?,?,?,'reservado',?)");
        $q->bind_param('issiiss', $empresa, $instance, $phone, $cid, $incomingId, $saludo, $actor);
        if (!$q->execute()) throw new RuntimeException('No se pudo reservar envio (posible duplicado)');
        $sendId = $connWa->insert_id; $q->close();
        // Comprobar nuevamente la propiedad humana justo antes del intento.
        $freshAssignment = rai_assignment($connWa, $empresa, $phone);
        $q = $connWa->prepare('SELECT id,direction FROM wa_messages WHERE empresa_id=? AND instance_id=? AND wa_conversation_id=? ORDER BY id DESC LIMIT 1');
        $q->bind_param('isi', $empresa, $instance, $cid); $q->execute();
        $fresh = $q->get_result()->fetch_assoc(); $q->close();
        if (!rai_owner_ok($freshAssignment, $owner) || (int)($fresh['id'] ?? 0) !== $incomingId || ($fresh['direction'] ?? '') !== 'in') {
            $q = $connWa->prepare("UPDATE royal_ai_sales_envios SET estado='rechazado',finalizado_at=NOW(),respuesta_resumen='Asignacion o conversacion cambiada' WHERE id=?");
            $q->bind_param('i', $sendId); $q->execute(); $q->close();
            throw new RuntimeException('Asignacion o conversacion cambiada; envio bloqueado');
        }
        $q = $connWa->prepare("UPDATE royal_ai_sales_envios SET estado='intentando',intentado_at=NOW() WHERE id=? AND estado='reservado'");
        $q->bind_param('i', $sendId); $q->execute();
        $changed = $q->affected_rows; $q->close();
        if ($changed !== 1) throw new RuntimeException('No se pudo marcar intento; envio bloqueado');
        // El backend resuelve exclusivamente la instancia indicada, nunca RR.
        // No modificar wa_assignments: la funcion existente retorna si ya hay asignado_a.
        require_once __DIR__ . '/wa_enviar_mensaje_backend.php';
        if (!function_exists('wa_enviar_texto_backend')) throw new RuntimeException('Backend de envio no disponible');
        try { $result = wa_enviar_texto_backend($phone, $saludo, 'Carlos IA', $instance); }
        catch (Throwable $e) { $result = ['ok'=>false, 'message'=>'Excepcion durante intento']; error_log('Royal AI Sales envio incierto: ' . $e->getMessage()); }
        $accepted = ($result['ok'] ?? false) === true;
        $status = $accepted ? 'aceptado' : 'incierto';
        $summary = mb_substr((string)($result['message'] ?? 'Sin confirmacion'), 0, 450, 'UTF-8');
        $q = $connWa->prepare('UPDATE royal_ai_sales_envios SET estado=?,finalizado_at=NOW(),respuesta_resumen=? WHERE id=?');
        $q->bind_param('ssi', $status, $summary, $sendId); $q->execute(); $q->close();
        $ok = $accepted ? 'Proveedor acepto el envio. Verificar telefono.' : 'Resultado incierto: NO reintentar.';
        $used = ['id'=>$sendId, 'estado'=>$status];
    }
} catch (Throwable $e) { $error = $e->getMessage(); error_log('Royal AI Sales envio manual: ' . $error); }
finally {
    if ($lockHeld) {
        try { $connWa->query("SELECT RELEASE_LOCK('rai_carlos_unico_1_5549')"); }
        catch (Throwable $ignored) { error_log('Royal AI Sales: error liberando lock'); }
    }
}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
include dirname(__DIR__) . '/header.php';
?>
<section style="max-width:900px;margin:30px auto;padding:25px;background:#10233b;color:#fff;border-radius:14px;font-family:Arial,sans-serif">
<h1>Royal AI Sales - Primer envio controlado Carlos IA (V3)</h1>
<p>Empresa 1 | Jefry12 | Destinatario de prueba terminado en 5549</p>
<p style="color:#ffd78c">Estado del cortacircuito: <strong><?=ROYAL_AI_SALES_PERMITIR_ENVIO_MANUAL?'HABILITADO':'APAGADO (seguro)'?></strong></p>
<?php if ($error): ?><p style="color:#ff9d9d"><?=rai_h($error)?></p><?php endif; ?>
<?php if ($ok): ?><p style="color:#92efb5"><?=rai_h($ok)?></p><?php endif; ?>
<p><strong>Ultimo mensaje:</strong> <?=rai_h($last['texto']??'No encontrado')?></p>
<p><strong>Respuesta aprobada:</strong> <?=rai_h($saludo)?></p>
<p><strong>Responsable humano Jefry12:</strong> <?=$assigned?'verificado':'no verificado'?></p>
<p><strong>Instancia en asignacion:</strong> <?=rai_h($assignment['instance_id']??'sin dato')?> (puede ser RR; no se modifica)</p>
<p><strong>Canal de mensaje entrante:</strong> Jefry12 (instancia privada verificada)</p>
<p><strong>Intento anterior para este mensaje:</strong> <?=$used?rai_h($used['estado']):'ninguno'?></p>
<p><strong>Prueba unica previa a este numero:</strong> <?=$everUsed?rai_h($everUsed['estado']):'ninguna'?></p>
<?php if (!$error && !$used && !$everUsed && $assigned): ?>
<form method="post"><input type="hidden" name="csrf" value="<?=rai_h($csrf)?>"><p>Para confirmar escribe exactamente: <code>ENVIAR SOLO PRUEBA</code></p><input name="confirmacion" required autocomplete="off" style="padding:9px;width:270px"><button type="submit" <?=ROYAL_AI_SALES_PERMITIR_ENVIO_MANUAL?'':'disabled'?> style="padding:10px;margin-left:8px">Enviar saludo una sola vez</button></form>
<?php endif; ?>
<p style="color:#ffd78c">Unico envio MANUAL autorizado a este numero de prueba. No hay envios automaticos. Ante resultado incierto, no reintentar. El boton solo funciona tras confirmacion explicita.</p>
<a href="/royal_media_inventario.php" style="color:#9bd3ff">Volver al CRM</a>
</section>
<?php include dirname(__DIR__) . '/footer.php'; ?>
