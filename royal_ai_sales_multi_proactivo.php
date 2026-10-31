<?php
declare(strict_types=1);
/** Motor independiente de seguimiento: ejecucion CLI, sin tocar webhook ni RR. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('America/Tegucigalpa');
function rp_rows(mysqli $db,string $sql,string $types='',array $params=[]): array {
    $s=$db->prepare($sql); if (!$s) throw new RuntimeException('Consulta no disponible');
    if ($types) $s->bind_param($types,...$params);
    if (!$s->execute()) throw new RuntimeException('Consulta fallida');
    $r=$s->get_result();$out=$r?$r->fetch_all(MYSQLI_ASSOC):[];$s->close();return $out;
}
function rp_enqueue(mysqli $db,int $empresa,string $instance,string $phone,string $event,string $ref,string $message,string $due): void {
    $s=$db->prepare("INSERT IGNORE INTO royal_ai_sales_tareas (empresa_id,instance_id,telefono,tipo,referencia,contenido,programado_at,estado) VALUES (?,?,?,?,?,?,?,'pendiente')");
    if (!$s) throw new RuntimeException('Cola no instalada');
    $s->bind_param('issssss',$empresa,$instance,$phone,$event,$ref,$message,$due);$s->execute();$s->close();
}
try {
    $config=require __DIR__.'/royal_ai_sales_auto_config.php';
    $agentId=(int)($argv[1]??0);
    if (($agentId<2 || $agentId>30 || !isset($config['agents'][$agentId]))) throw new RuntimeException('ID de agente no autorizado');
    $agent=$config['agents'][$agentId]??[];$company=(int)($config['company_id']??0);
    $instance=(string)($agent['instance_id']??'');$owner=(string)($agent['human_owner']??'');
    if ($company!==1 || $instance==='' || $owner==='' ) throw new RuntimeException('Agente no autorizado');
    require_once __DIR__.'/royal_ai_sales_multi_control.php';
    $slots=[$agentId=>(string)($agent['slot']??([2=>'instancia_4',3=>'instancia_3',4=>'instancia_2'][$agentId]??''))];
    if ( empty(rai_agent_status($company,$agentId,$slots[$agentId])['active'])) throw new RuntimeException('Estacion no activa');
    $db=(static function(){require __DIR__.'/conexion_whatsapp.php';return $connWa??null;})();
    $crm=(static function(){require dirname(__DIR__).'/conexion.php';return $conn??null;})();
    if (!$db instanceof mysqli || !$crm instanceof mysqli) throw new RuntimeException('Conexion no disponible');
    $lockName='rai_proactivo_'.hash('sha256',$company.'|'.$instance);
    $locked=rp_rows($db,'SELECT GET_LOCK(?,0) AS ok','s',[$lockName]);
    if ((int)($locked[0]['ok']??0)!==1) {echo "ocupado\n";exit;}
    try {
        // Detecta promesas del propietario con fecha vencida. No contacta promesas de otros agentes.
        $clients=rp_rows($crm,"SELECT id,nombre,telefono,fecha_seguimiento,hora_seguimiento FROM clientes WHERE empresa_id=? AND estado_gestion='promesa' AND COALESCE(NULLIF(gestionado_agente_por,''),creado_por)=? AND fecha_seguimiento IS NOT NULL AND fecha_seguimiento<>'0000-00-00' AND CONCAT(fecha_seguimiento,' ',COALESCE(NULLIF(hora_seguimiento,''),'09:00:00'))<=NOW() ORDER BY fecha_seguimiento ASC LIMIT 40",'is',[$company,$owner]);
        $count=0;
        foreach ($clients as $client) {
            $phone=preg_replace('/\D/','',(string)$client['telefono']);
            if (strlen($phone)===8) $phone='504'.$phone;
            if (!preg_match('/^504[0-9]{8}$/',$phone)) continue;
            $assignment=rp_rows($db,'SELECT instance_id,asignado_a FROM wa_assignments WHERE empresa_id=? AND telefono=? ORDER BY id DESC LIMIT 1','is',[$company,$phone]);
            if (!$assignment || (string)$assignment[0]['instance_id']!==$instance || strcasecmp((string)$assignment[0]['asignado_a'],$owner)!==0) continue;
            // Solo contactos con una conversacion iniciada por el cliente en esta instancia.
            $in=rp_rows($db,"SELECT id FROM wa_messages WHERE empresa_id=? AND instance_id=? AND enviado_por=? AND direction='in' ORDER BY id DESC LIMIT 1",'iss',[$company,$instance,$phone]);
            if (!$in) continue;
            $due=(string)$client['fecha_seguimiento'].' '.(trim((string)$client['hora_seguimiento'])?:'09:00:00');
            $text='¡Buen día! Le saluda '.(string)$agent['name'].'. Le escribo para retomar nuestra conversación sobre las pacas. ¿Todavía le interesa la mercadería o le gustaría que le ayude a encontrar una opción?';
            rp_enqueue($db,$company,$instance,$phone,'promesa','cliente:'.$client['id'].':'.$due,$text,$due);$count++;
        }
        // Por defecto, observar la cola SIN enviar. La activacion es independiente del chat entrante.
        $canSend=($config['proactive_enabled']??false)===true && ($config['send_enabled']??false)===true && ($agent['send_enabled']??false)===true && is_file(__DIR__.'/royal_ai_sales_multi_transport.php');
        $tasks=rp_rows($db,"SELECT id,telefono,contenido FROM royal_ai_sales_tareas WHERE empresa_id=? AND instance_id=? AND estado='pendiente' AND programado_at<=NOW() ORDER BY programado_at,id LIMIT 1",'is',[$company,$instance]);
        if (!$canSend || !$tasks) {echo json_encode(['ok'=>true,'encoladas'=>$count,'pendientes'=>count($tasks),'envios'=>0,'modo'=>$canSend?'sin_tareas':'observacion'])."\n";exit;}
        $t=$tasks[0];$phone=(string)$t['telefono'];
        if (!preg_match('/^504[0-9]{8}$/',$phone)) throw new RuntimeException('Telefono invalido');
        $assignment=rp_rows($db,'SELECT instance_id,asignado_a FROM wa_assignments WHERE empresa_id=? AND telefono=? ORDER BY id DESC LIMIT 1','is',[$company,$phone]);
        if (!$assignment || (string)$assignment[0]['instance_id']!==$instance || strcasecmp((string)$assignment[0]['asignado_a'],$owner)!==0) throw new RuntimeException('Asignacion cambio; no enviar');
        // Resolver y comprobar el mensaje entrante ANTES de reservar la tarea.
        $conv=rp_rows($db,"SELECT id,wa_conversation_id FROM wa_messages WHERE empresa_id=? AND instance_id=? AND enviado_por=? AND direction='in' ORDER BY id DESC LIMIT 1",'iss',[$company,$instance,$phone]);
        $incomingId=(int)($conv[0]['id']??0);
        $cid=(int)($conv[0]['wa_conversation_id']??0);
        if ($incomingId<=0 || $cid<=0) throw new RuntimeException('Conversacion entrante invalida; tarea sin enviar');
        require_once __DIR__.'/royal_ai_sales_multi_transport.php';
        if (!function_exists('rai_transport_send')) throw new RuntimeException('Transporte de texto no disponible');
        if (empty(rai_agent_status($company,$agentId,$slots[$agentId])['active'])) throw new RuntimeException('Estacion detenida');
        // Eventos observables en Royal Chat, asociados a la instancia y al telefono real.
        $followEvent=static function(string $activity) use ($db,$company,$instance,$phone): void {
            try {
                $stmt=$db->prepare('INSERT INTO royal_ai_follow_eventos (empresa_id,instance_id,telefono,actividad,creado_at) VALUES (?,?,?,?,NOW())');
                if ($stmt) { $stmt->bind_param('isss',$company,$instance,$phone,$activity); $stmt->execute(); $stmt->close(); }
            } catch (Throwable $ignored) {}
        };
        $followEvent('Abrió conversación para seguimiento');
        // Reserva atomica: ante un fallo posterior NO reenviar automaticamente.
        $s=$db->prepare("UPDATE royal_ai_sales_tareas SET estado='intentando',intentado_at=NOW() WHERE id=? AND estado='pendiente'");
        if (!$s) throw new RuntimeException('No se pudo reservar la tarea');
        $s->bind_param('i',$t['id']);
        if (!$s->execute()) {$s->close();throw new RuntimeException('Reserva fallida');}
        $reserved=$s->affected_rows;$s->close();
        if ($reserved!==1) throw new RuntimeException('Reserva no adquirida');
        $state='incierto';
        try {
            $r=rai_transport_send($crm,$db,$company,$instance,$phone,$cid,$incomingId,(string)$t['contenido'],(string)$agent['name']);
            $state=($r['ok']??false)?'enviado':'incierto';
            if ($state==='enviado') $followEvent('Mensaje de seguimiento enviado');
        } catch (Throwable $sendError) {
            // Puede haber aceptacion remota aunque falle el registro local.
            error_log('CARLOS_PROACTIVO_ENVIO '.get_class($sendError).' '.$sendError->getMessage());
        }
        $s=$db->prepare("UPDATE royal_ai_sales_tareas SET estado=?,finalizado_at=NOW() WHERE id=? AND estado='intentando'");
        if (!$s) throw new RuntimeException('No se pudo finalizar tarea; revisar estado manualmente');
        $s->bind_param('si',$state,$t['id']);
        if (!$s->execute()) {$s->close();throw new RuntimeException('No se pudo finalizar tarea; NO REINTENTAR');}
        $s->close();
        echo json_encode(['ok'=>($state==='enviado'),'estado'=>$state,'envios'=>$state==='enviado'?1:0])."\n";
    } finally {rp_rows($db,'SELECT RELEASE_LOCK(?) AS ok','s',[$lockName]);}
} catch (Throwable $e) {error_log('CARLOS_PROACTIVO '.get_class($e).' '.$e->getMessage());echo json_encode(['ok'=>false,'estado'=>'bloqueado','envios'=>0])."\n";exit(1);}
