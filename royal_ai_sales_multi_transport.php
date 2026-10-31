<?php
declare(strict_types=1);
/** Transporte multiagente independiente de Carlos. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
function rai_transport_send(mysqli $crm, mysqli $wa, int $company, string $instance, string $phone, int $conversation, int $incoming, string $answer, string $agentName): array {
    if ($company!==1 || $instance==='' || !preg_match('/^504[0-9]{8}$/',$phone)
        || $conversation<=0 || $incoming<=0 || trim($answer)==='' || mb_strlen($answer,'UTF-8')>1600) {
        throw new RuntimeException('Envio fuera de instancia autorizada');
    }
    $cfg=require __DIR__.'/royal_ai_sales_auto_config.php';
    $agentId=0; $ownerName=''; $slot='';
    foreach (($cfg['agents']??[]) as $id=>$candidate) {
        if ((int)$id<2 || (int)$id>30 || !is_array($candidate)) continue;
        if (($candidate['name']??'')!==$agentName || ($candidate['instance_id']??'')!==$instance) continue;
        $agentId=(int)$id;
        $ownerName=(string)($candidate['human_owner']??'');
        $slot=(string)($candidate['slot']??([2=>'instancia_4',3=>'instancia_3',4=>'instancia_2'][$agentId]??''));
        break;
    }
    if (!$agentId || $ownerName==='' || !preg_match('/^instancia_[0-9]+$/',$slot)) throw new RuntimeException('Agente no autorizado');
    require_once __DIR__.'/royal_ai_sales_multi_control.php';
    if (empty(rai_agent_status($company,$agentId,$slot)['active'])) throw new RuntimeException('Estacion IA inactiva');
    if (($cfg['agents'][$agentId]['instance_id']??'')!==$instance || ($cfg['agents'][$agentId]['human_owner']??'')!==$ownerName || ($cfg['agents'][$agentId]['audience']??'')!=='assigned_owner') throw new RuntimeException('Audiencia no autorizada');
    if (($cfg['auto_enabled']??false)!==true || ($cfg['send_enabled']??false)!==true || ($cfg['agents'][$agentId]['auto_enabled']??false)!==true || ($cfg['agents'][$agentId]['send_enabled']??false)!==true) throw new RuntimeException('Envio desactivado');
    $q=$wa->prepare('SELECT instance_id,token,proveedor,activa,nombre,agente_fijo,modo_asignacion FROM wa_instancias WHERE empresa_id=? AND instance_id=? LIMIT 1');
    if (!$q) throw new RuntimeException('No se pudo resolver cuenta de envio');
    $q->bind_param('is',$company,$instance);
    if (!$q->execute()) { $q->close(); throw new RuntimeException('Consulta de instancia fallida'); }
    $row=$q->get_result()->fetch_assoc(); $q->close();
    if (!$row || (int)$row['activa']!==1) throw new RuntimeException('Instancia inactiva');
    $fixedOwner=trim((string)($row['agente_fijo']??''));
    $validOwner=$fixedOwner!==''
        ? strcasecmp($fixedOwner,$ownerName)===0 && (string)($row['modo_asignacion']??'')==='fijo'
        : strcasecmp(trim((string)$row['nombre']),$ownerName)===0;
    if (!$validOwner) throw new RuntimeException('Instancia o propietario no autorizado');
    $provider=strtolower(trim((string)$row['proveedor']));
    if ($provider!=='zapi' && $provider!=='meta') throw new RuntimeException('Proveedor no permitido');
    if ($provider==='zapi' && trim((string)$row['token'])==='') throw new RuntimeException('Token Z-API no configurado');
    if ($provider==='meta' && !preg_match('/^[0-9]{8,25}$/',$instance)) throw new RuntimeException('Phone Number ID Meta invalido');
    $q=$wa->prepare('SELECT wa_lead_id,wa_contact_id,wa_conversation_id,instance_id,direction,enviado_por FROM wa_messages WHERE id=? AND empresa_id=? LIMIT 1');
    if (!$q) throw new RuntimeException('Consulta de mensaje original no disponible');
    $q->bind_param('ii',$incoming,$company);
    if (!$q->execute()) { $q->close(); throw new RuntimeException('Consulta del mensaje original fallida'); }
    $orig=$q->get_result()->fetch_assoc(); $q->close();
    if (!$orig || (int)$orig['wa_conversation_id']!==$conversation || (string)$orig['instance_id']!==$instance || (string)$orig['direction']!=='in' || (string)$orig['enviado_por']!==$phone) throw new RuntimeException('Mensaje de origen no corresponde al contacto autorizado');
    $q=$wa->prepare('SELECT asignado_a,instance_id FROM wa_assignments WHERE empresa_id=? AND telefono=? ORDER BY id DESC LIMIT 1');
    if (!$q) throw new RuntimeException('No se pudo validar propietario');
    $q->bind_param('is',$company,$phone);
    if (!$q->execute()) { $q->close(); throw new RuntimeException('No se pudo consultar propietario'); }
    $owner=$q->get_result()->fetch_assoc(); $q->close();
    if (!$owner || (string)$owner['instance_id']!==$instance || strcasecmp(trim((string)$owner['asignado_a']),$ownerName)!==0) throw new RuntimeException('Propietario cambiado');
    $privatePath=dirname(__DIR__,2).'/royal_private/royal_ai_sales_config.php';
    $private=is_file($privatePath)?require $privatePath:[];
    $headers=['Content-Type: application/json'];
    if ($provider==='zapi') {
        $sharedPath=dirname(__DIR__,2).'/zapi_secrets/zapi_secrets.php';
        $shared=[];
        if (is_file($sharedPath) && is_readable($sharedPath)) {
            $loaded=(static function(string $path){return require $path;})($sharedPath);
            if (is_array($loaded)) $shared=$loaded;
        }
        $envToken=getenv('ROYAL_ZAPI_CLIENT_TOKEN');
        $clientToken=trim((string)(($envToken!==false && trim((string)$envToken)!=='')?$envToken:($shared['client_token']??(is_array($private)?($private['zapi_client_token']??''):''))));
        if ($clientToken==='') throw new RuntimeException('Client-Token Z-API no disponible en configuracion compartida');
        $url='https://api.z-api.io/instances/'.rawurlencode($instance).'/token/'.rawurlencode((string)$row['token']).'/send-text';
        $headers[]='Client-Token: '.$clientToken;
        $payload=['phone'=>$phone,'message'=>$answer];
    } else {
        $access=trim((string)(getenv('ROYAL_META_ACCESS_TOKEN')?:(is_array($private)?($private['meta_access_token']??''):'')));
        if ($access==='') throw new RuntimeException('Falta ROYAL_META_ACCESS_TOKEN');
        $url='https://graph.facebook.com/v23.0/'.rawurlencode($instance).'/messages';
        $headers[]='Authorization: Bearer '.$access;
        $payload=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$phone,'type'=>'text','text'=>['preview_url'=>false,'body'=>$answer]];
    }
    // Ultimo control inmediatamente antes de contactar al proveedor.
    if (empty(rai_agent_status($company,$agentId,$slot)['active'])) throw new RuntimeException('Estacion detenida antes de enviar');
    if (!function_exists('curl_init')) throw new RuntimeException('cURL no disponible');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25]);
    $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    $response=is_string($raw)?json_decode($raw,true):null;
    if ($err!=='' || $code<200 || $code>=300 || !is_array($response)) return ['ok'=>false,'uncertain'=>true,'http'=>$code];
    if (($response['error']??null) || ($response['success']??true)===false) return ['ok'=>false,'uncertain'=>true,'http'=>$code];
    $providerId=$provider==='meta'?($response['messages'][0]['id']??''):($response['messageId']??($response['zaapId']??''));
    if (!is_string($providerId) || trim($providerId)==='') return ['ok'=>false,'uncertain'=>true,'http'=>$code];
    $lead=(int)($orig['wa_lead_id']??0); $contact=(int)($orig['wa_contact_id']??0);
    $rawPayload=json_encode(['provider'=>$provider,'provider_message_id'=>$providerId,'http_code'=>$code,'royal_ai_sales'=>true],JSON_UNESCAPED_UNICODE);
    $q=$wa->prepare("INSERT INTO wa_messages (empresa_id,wa_conversation_id,instance_id,wa_lead_id,wa_contact_id,meta_message_id,direction,tipo,texto,media_url,media_mime,media_sha256,enviado_por,nombre_mostrar,status_envio,raw_payload,event_time) VALUES (?,?,?,?,?,?,'out','text',?,'','','',?,?,'enviado',?,UTC_TIMESTAMP() - INTERVAL 6 HOUR)");
    if (!$q) throw new RuntimeException('Proveedor acepto envio; registro local no disponible: NO REINTENTAR');
    $q->bind_param('iisiisssss',$company,$conversation,$instance,$lead,$contact,$providerId,$answer,$phone,$agentName,$rawPayload);
    if (!$q->execute()) { $q->close(); throw new RuntimeException('Proveedor acepto envio; registro local fallo: NO REINTENTAR'); }
    $q->close();
    return ['ok'=>true,'uncertain'=>false,'http'=>$code];
}
