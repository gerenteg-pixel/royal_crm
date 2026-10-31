<?php
declare(strict_types=1);
/** CLI: una preventa por ejecucion. No reintentar resultados inciertos. */
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
date_default_timezone_set('America/Tegucigalpa');
try {
    $cfg=require __DIR__.'/royal_ai_sales_auto_config.php';
    $agentId=(int)($argv[1]??0);
    if (($agentId<2 || $agentId>30)) throw new RuntimeException('ID de agente no autorizado');
    $agent=$cfg['agents'][$agentId]??[];
    $company=(int)($cfg['company_id']??0);$instance=(string)($agent['instance_id']??'');
    if ($company!==1 || ($cfg['auto_enabled']??false)!==true || ($cfg['send_enabled']??false)!==true || ($agent['send_enabled']??false)!==true) throw new RuntimeException('Envio desactivado');
    require_once __DIR__.'/royal_ai_sales_multi_control.php';
    $slots=[$agentId=>(string)($agent['slot']??([2=>'instancia_4',3=>'instancia_3',4=>'instancia_2'][$agentId]??''))];
    if (empty(rai_agent_status($company,$agentId,$slots[$agentId])['active'])) throw new RuntimeException('Estacion inactiva');
    require __DIR__.'/conexion_whatsapp.php';
    require dirname(__DIR__).'/conexion.php';
    if (!isset($connWa,$conn)||!($connWa instanceof mysqli)||!($conn instanceof mysqli)) throw new RuntimeException('BD no disponible');
    $lockName='rai_preventa_notif_'.$agentId;
    $lockStmt=$conn->prepare('SELECT GET_LOCK(?,0) AS l');
    $lockStmt->bind_param('s',$lockName);$lockStmt->execute();$lock=$lockStmt->get_result()->fetch_assoc();$lockStmt->close();
    if ((int)($lock['l']??0)!==1) { echo "ocupado\n";exit; }
    $s=$conn->prepare("SELECT p.id,p.telefono,p.conversation_id,p.producto_id,p.producto_nombre,p.cantidad FROM royal_ai_preventas p JOIN wa_catalogo_productos c ON c.id=p.producto_id AND c.empresa_id=p.empresa_id WHERE p.empresa_id=? AND p.instance_id=? AND p.estado='pendiente_stock' AND c.stock_actual>=p.cantidad ORDER BY p.id LIMIT 1");
    $s->bind_param('is',$company,$instance);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
    if (!$p) { echo "sin_preventas\n";exit; }
    $phone=(string)$p['telefono'];$id=(int)$p['id'];
    if (!preg_match('/^504[0-9]{8}$/',$phone)) {echo "telefono_invalido\n";exit;}
    $s=$connWa->prepare('SELECT asignado_a FROM wa_assignments WHERE empresa_id=? AND telefono=? ORDER BY id DESC LIMIT 1');
    $s->bind_param('is',$company,$phone);$s->execute();$owner=$s->get_result()->fetch_assoc();$s->close();
    if (!$owner || strcasecmp((string)$owner['asignado_a'],(string)($agent['human_owner']??''))!==0) {echo "asignacion_cambiada\n";exit;}
    $s=$connWa->prepare("SELECT id FROM wa_messages WHERE empresa_id=? AND instance_id=? AND wa_conversation_id=? AND enviado_por=? AND direction='in' ORDER BY id DESC LIMIT 1");
    $s->bind_param('isis',$company,$instance,$p['conversation_id'],$phone);$s->execute();$incoming=$s->get_result()->fetch_assoc();$s->close();
    if (!$incoming) {echo "sin_mensaje_original\n";exit;}
    if (empty(rai_agent_status($company,$agentId,$slots[$agentId])['active'])) {echo "estacion_detenida\n";exit;}
    // Reserva antes de cualquier llamada externa: nunca reenviar en caso incierto.
    $s=$conn->prepare("UPDATE royal_ai_preventas SET estado='stock_detectado' WHERE id=? AND empresa_id=? AND estado='pendiente_stock'");
    $s->bind_param('ii',$id,$company);$s->execute();$affected=$s->affected_rows;$s->close();
    if ($affected!==1) {echo "ya_reservada\n";exit;}
    require_once __DIR__.'/royal_ai_sales_multi_transport.php';
    $message='¡Hola! Soy '.(string)$agent['name'].'. Le escribo porque ya ingresó mercadería de '.(string)$p['producto_nombre'].'. Si todavía le interesa, puedo mostrarle las fotografías actuales y confirmarle el precio con entrega. ¿Le gustaría que se las comparta?';
    $result=rai_transport_send($conn,$connWa,$company,$instance,$phone,(int)$p['conversation_id'],(int)$incoming['id'],$message,(string)$agent['name']);
    if (($result['ok']??false)===true) {
        $s=$conn->prepare("UPDATE royal_ai_preventas SET estado='contactado',notificado_at=NOW() WHERE id=? AND estado='stock_detectado'");
        $s->bind_param('i',$id);$s->execute();$s->close();
        echo "contactado\n";
    } else {echo "resultado_incierto_no_reintentar\n";}
} catch(Throwable $e) {error_log('ROYAL_IA_PREVENTA_NOTIFICADOR '.get_class($e).': '.$e->getMessage());echo "bloqueado\n";exit(1);}
