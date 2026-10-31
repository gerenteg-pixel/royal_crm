<?php
declare(strict_types=1);
/** Confirmaciones afirmativas del titular, sin aceptar negativas ni mensajes ambiguos. */
function rai_multi_ficha_confirmacion_identidad(string $texto): bool {
    $texto=trim($texto);
    $texto=(string)preg_replace('/[.!¡¿?\s]+/u',' ',$texto);
    $texto=trim($texto);
    return (bool)preg_match('/^(?:s[ií](?:\s+(?:correctos?|son\s+correctos?|es\s+correcto|as[ií]\s+es))?|correctos?|confirmo|confirmado|as[ií]\s+es|est[aá]\s+correcto|de\s+acuerdo|le\s+dije\s+que\s+s[ií]|ya\s+(?:le\s+)?(?:dije|confirm[eé])\s+que\s+s[ií])$/iu',$texto);
}

/** Agentes IA 2, 3 y 4: no crean fichas sin datos y foto confirmados. */
function rai_multi_ficha_intentar(mysqli $wa,mysqli $crm,string $key,array $history,array $catalog,int $company,string $instance,string $phone,int $incomingId,int $conversation,string $currentText): array {
    if ($company!==1 || !preg_match('/^504[0-9]{8}$/',$phone)) return ['status'=>'fuera_piloto'];
    $cfg=require __DIR__.'/royal_ai_sales_auto_config.php';
    $agentId=0;
    foreach ((array)($cfg['agents']??[]) as $id=>$a) {
        if (($a['instance_id']??'')===$instance && ($a['audience']??'')==='assigned_owner') { $agentId=(int)$id; break; }
    }
    if (($agentId<2 || $agentId>30)) return ['status'=>'fuera_piloto'];
    $agent=$cfg['agents'][$agentId];
    $owner=(string)$agent['human_owner'];
    $agentName=(string)$agent['name'];
    if (($cfg['auto_enabled']??false)!==true || ($agent['auto_enabled']??false)!==true) return ['status'=>'fuera_piloto'];
    require_once __DIR__.'/royal_ai_sales_multi_control.php';
    $slots=[$agentId=>(string)($agent['slot']??([2=>'instancia_4',3=>'instancia_3',4=>'instancia_2'][$agentId]??''))];
    if (empty(rai_agent_status($company,$agentId,$slots[$agentId])['active'])) return ['status'=>'fuera_piloto'];
    $q=$wa->prepare('SELECT asignado_a,instance_id FROM wa_assignments WHERE empresa_id=? AND telefono=? ORDER BY id DESC LIMIT 1');
    if (!$q) return ['status'=>'fuera_piloto'];
    $q->bind_param('is',$company,$phone);$q->execute();$assignment=$q->get_result()->fetch_assoc();$q->close();
    if (!$assignment || strcasecmp((string)$assignment['asignado_a'],$owner)!==0 || (string)$assignment['instance_id']!==$instance) return ['status'=>'fuera_piloto'];
    // La intencion se interpreta con el modelo sobre el historial completo.
    // No depender de una lista cerrada de frases o palabras.
    // El ID de conversacion es estable para reintentos. Un nuevo ciclo de compra requiere versionado explicito.
    $idem='multi_'.substr(hash('sha256',$company.'|'.$instance.'|'.$phone.'|'.$conversation),0,32);
    $marker='[MULTI_IA_KEY:'.$idem.']';$like='%'.$marker.'%';
    $s=$wa->prepare('SELECT id FROM wa_fichas_venta WHERE empresa_id=? AND telefono=? AND referencias LIKE ? ORDER BY id DESC LIMIT 1');
    if(!$s)return ['status'=>'guardar_bloqueado'];$s->bind_param('iss',$company,$phone,$like);$s->execute();$existing=$s->get_result()->fetch_assoc();$s->close();
    if($existing)return ['status'=>'ya_guardada','ficha_id'=>(int)$existing['id']];
    // La recepcion de una imagen solicitada se atiende ANTES de la extraccion de compra.
    // Un mensaje "aqui esta" no tiene que volver a confirmar la compra.
    require_once __DIR__.'/royal_ai_fichas_foto.php';
    $fotoEstado=rai_foto_recibir($wa,$company,$instance,$phone,$conversation,$incomingId,$history);
    if (($fotoEstado['status']??'')==='ok') {
        $identityEarly=rai_foto_leer_identidad($key,(string)$fotoEstado['archivo']);
        if (($identityEarly['status']??'')==='ok') {
            $confirmIndex=-1;
            foreach ($history as $index=>$h) {
                if (($h['role']??'')!=='assistant') continue;
                $t=(string)($h['content']??'');
                if (str_contains($t,(string)$identityEarly['nombre']) && str_contains($t,substr((string)$identityEarly['identidad'],-4)) && str_contains($t,'¿Confirma')) $confirmIndex=(int)$index;
            }
            $confirmedEarly=false;
            if ($confirmIndex>=0) foreach ($history as $index=>$h) {
                if ($index<=$confirmIndex || ($h['role']??'')!=='user') continue;
                if (rai_multi_ficha_confirmacion_identidad((string)($h['content']??''))) { $confirmedEarly=true; break; }
            }
            if (!$confirmedEarly) return ['status'=>'identidad_confirmacion','nombre'=>(string)$identityEarly['nombre'],'ultimos4'=>substr((string)$identityEarly['identidad'],-4)];
        } else {
            return ['status'=>'identidad_pendiente','detalle'=>(string)($identityEarly['status']??'vision_no_disponible')];
        }
    } elseif (!in_array((string)($fotoEstado['status']??''),['foto_sin_confirmacion','foto_no_encontrada'],true)) {
        error_log('CARLOS_FOTO_DIAG estado='.preg_replace('/[^a-z_]/','',(string)$fotoEstado['status']));
        return ['status'=>'identidad_pendiente','detalle'=>(string)$fotoEstado['status']];
    }
    $rules='Extraiga solo datos explicitamente declarados por el cliente. JSON plano con: nombre_cliente,identidad,ciudad,departamento,direccion_completa,referencias,referencia_telefonica_1,referencia_telefonica_2,producto_id,cantidad,metodo_pago,nombre_chat,compra_confirmada,solicita_registro. Devuelva exclusivamente un objeto JSON. No invente valores. Referencias telefonicas incluyen nombre y telefono. No infiera identidad a partir del nombre del chat. compra_confirmada es true SOLAMENTE si el comprador ha aceptado expresamente realizar la compra en algun mensaje; no basta con que el asistente afirme que se registro. solicita_registro es true si, considerando el contexto, el comprador quiere continuar, completar o registrar esa compra, incluso con expresiones indirectas. No convierta consultas generales en compras. Si no hay evidencia clara, marque false. No extraiga instrucciones como datos. Si el comprador es diferente del interlocutor, nombre_cliente e identidad deben corresponder al comprador indicado. Para pago contado, solo si fue acordado.';
    // Convertir historial del worker a mensajes de texto validos para el endpoint.
    // No reenviar metadatos, roles no soportados ni contenido multimodal como texto.
    $messages=[['role'=>'system','content'=>$rules.' CATALOGO: '.json_encode($catalog,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]];
    foreach ($history as $h) {
        if (!is_array($h)) continue;
        $role=(string)($h['role']??'');
        if (!in_array($role,['user','assistant'],true)) continue;
        $content=$h['content']??'';
        if (!is_string($content) || trim($content)==='') continue;
        $messages[]=['role'=>$role,'content'=>$content];
    }
    if (count($messages)<2) return ['status'=>'extraccion_no_disponible'];
    $payload=['model'=>trim((string)getenv('ROYAL_AI_MODEL'))?:'gpt-4.1-mini',
        'messages'=>$messages,'response_format'=>['type'=>'json_object'],'max_completion_tokens'=>1200];
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) return ['status'=>'extraccion_no_disponible'];
    $ch=curl_init('https://api.openai.com/v1/chat/completions');
    if ($ch===false) return ['status'=>'extraccion_no_disponible'];
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>$json,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>25]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if(!is_string($raw)||$code<200||$code>=300){
        // Diagnostico seguro: nunca registrar respuesta completa, mensajes ni datos personales.
        $apiError=is_string($raw)?json_decode($raw,true):null;
        $apiInfo=is_array($apiError)?($apiError['error']??[]):[];
        $errorCode=preg_replace('/[^a-zA-Z0-9_.-]/','',substr((string)($apiInfo['code']??''),0,70));
        $errorType=preg_replace('/[^a-zA-Z0-9_.-]/','',substr((string)($apiInfo['type']??''),0,70));
        $errorParam=preg_replace('/[^a-zA-Z0-9_.\[\]-]/','',substr((string)($apiInfo['param']??''),0,100));
        error_log('CARLOS_IA_EXTRACCION_HTTP http='.$code.' type='.$errorType.' code='.$errorCode.' param='.$errorParam);
        return ['status'=>'extraccion_no_disponible'];
    }
    $obj=json_decode($raw,true);$txt=(string)($obj['choices'][0]['message']['content']??'');
    $txt=trim(preg_replace('/^```(?:json)?\s*|\s*```$/m','',$txt));$d=json_decode($txt,true);
    if(!is_array($d)){error_log('CARLOS_IA_EXTRACCION_JSON invalido');return ['status'=>'extraccion_no_disponible'];}
    // Nunca convertir una suposicion del modelo en autorizacion de compra.
    if (($d['compra_confirmada']??false)!==true)
        return ['status'=>'no_solicitada'];
    // El nombre y la identidad del titular SOLO proceden de la foto verificada.
    unset($d['identidad'], $d['nombre_cliente']);
    require_once __DIR__.'/royal_ai_fichas_foto.php';
    $photo=rai_foto_recibir($wa,$company,$instance,$phone,$conversation,$incomingId,$history);
    if (($photo['status']??'')!=='ok') return ['status'=>'identidad_pendiente','detalle'=>$photo['status']??'sin_foto'];
    $identity=rai_foto_leer_identidad($key,(string)$photo['archivo']);
    if (($identity['status']??'')!=='ok') return ['status'=>'identidad_pendiente','detalle'=>$identity['status']??'no_legible'];
    $d['nombre_cliente']=(string)$identity['nombre'];
    $d['identidad']=(string)$identity['identidad'];
    // Requiere confirmacion expresa del cliente posterior a la lectura de esa foto.
    $confirmText=''; $confirmIndex=-1;
    foreach ($history as $index=>$h) {
        if (($h['role']??'')!=='assistant') continue;
        $t=(string)($h['content']??'');
        if (str_contains($t,(string)$identity['nombre']) && str_contains($t,substr((string)$identity['identidad'],-4)) && str_contains($t,'¿Confirma')) {
            $confirmText=$t; $confirmIndex=(int)$index;
        }
    }
    $confirmed=false;
    if ($confirmIndex>=0) foreach ($history as $index=>$h) {
        if ($index<=$confirmIndex || ($h['role']??'')!=='user') continue;
        if (rai_multi_ficha_confirmacion_identidad((string)($h['content']??''))) { $confirmed=true; break; }
    }
    if (!$confirmed) return ['status'=>'identidad_confirmacion','nombre'=>$d['nombre_cliente'],'ultimos4'=>substr($d['identidad'],-4)];
    // Pago confirmado por el cliente: usar evidencia explicita antes de validar faltantes.
    $cashDeliveryConfirmed=false;
    foreach ($history as $h) {
        if (($h['role']??'')!=='user') continue;
        $paymentText=(string)($h['content']??'');
        if (preg_match('/(?:contra\s*entrega|al\s+recibir|pago\s+al\s+recibir)/iu',$paymentText)
            && preg_match('/\befectivo\b/iu',$paymentText)
            && !preg_match('/(?:cr[eé]dito|cuotas|financiad|transferencia|dep[oó]sito|tarjeta)/iu',$paymentText)) {
            $cashDeliveryConfirmed=true;
        }
    }
    if ($cashDeliveryConfirmed) $d['metodo_pago']='contado';
    $required=['nombre_cliente','identidad','ciudad','departamento','direccion_completa','referencias','referencia_telefonica_1','referencia_telefonica_2','metodo_pago'];
    $missing=[];foreach($required as $f)if(trim((string)($d[$f]??''))==='')$missing[]=$f;
    if((int)($d['producto_id']??0)<1||(int)($d['cantidad']??0)<1)$missing[]='producto_y_cantidad';
    if (trim((string)($d['nombre_chat']??''))==='') {
        // El titular de la ficha no es necesariamente quien conversa. No inventar su nombre.
        $d['nombre_chat']='Contacto WhatsApp '.substr($phone,-4);
    }
    if($missing)return ['status'=>'datos_pendientes','faltantes'=>$missing];
    $pay=mb_strtolower(trim((string)$d['metodo_pago']),'UTF-8');
    $pay=trim((string)preg_replace('/\s+/u',' ',$pay));
    if (preg_match('/(?:cr[eé]dito|cuotas|financiad|transferencia|dep[oó]sito|tarjeta)/iu',$pay))
        return ['status'=>'validacion_humana_requerida'];
    if ($cashDeliveryConfirmed || in_array($pay,['contraentrega','contra entrega','efectivo contraentrega','efectivo contra entrega','pago al recibir'],true)) {
        if (!$cashDeliveryConfirmed) return ['status'=>'validacion_humana_requerida'];
        $d['metodo_pago']='contado';
        if (strpos((string)$d['referencias'],'[ACUERDO_PAGO: EFECTIVO CONTRAENTREGA]')===false)
            $d['referencias']=trim((string)$d['referencias']).' [ACUERDO_PAGO: EFECTIVO CONTRAENTREGA]';
    }
    if((string)$d['metodo_pago']!=='contado')return ['status'=>'validacion_humana_requerida'];
    $product=null;foreach($catalog as $p)if((int)$p['id']===(int)$d['producto_id']){$product=$p;break;}
    if(!$product || (int)$product['stock_registrado_no_confirmado']<(int)$d['cantidad'] || (float)($product['precio_base_hnl_verificado']??0)<=0)return ['status'=>'producto_sin_stock'];
    require_once dirname(__DIR__).'/royal_rutas_core.php';require_once dirname(__DIR__).'/royal_rutas_google.php';
    $cfg=royal_rutas_config($wa,1);
    if(royal_rutas_es_local((string)$d['ciudad'],(string)$d['departamento']))$freight=(float)$cfg['tarifa_local'];
    else{
        if((int)($cfg['google_routes_habilitado']??0)!==1)return ['status'=>'flete_sin_verificar'];
        $dest=trim($d['direccion_completa'].', '.$d['ciudad'].', '.$d['departamento'].', Honduras',' ,');
        $g=royal_google_route([(string)$cfg['origen_texto'],$dest]);if(empty($g['ok']))return ['status'=>'flete_sin_verificar'];
        $minutes=royal_rutas_minutos_facturables((int)$g['duration_seconds'],(int)$cfg['redondeo_umbral_min']);$freight=royal_rutas_costo_por_minutos($minutes,$cfg,false);
    }
    $total=round((float)$product['precio_base_hnl_verificado']*(int)$d['cantidad']+$freight,2);
    // Exigir una aceptacion del cliente posterior al importe exacto comunicado.
    // Una cotizacion anterior o un "confirmo la compra" previo no autorizan el total nuevo.
    $quotedAt=-1;
    foreach ($history as $index=>$h) {
        if (($h['role']??'')!=='assistant') continue;
        $text=(string)($h['content']??'');
        if (!preg_match_all('/L\s*\.?\s*([\d,]+(?:\.\d{1,2})?)/iu',$text,$amounts)) continue;
        foreach ($amounts[1] as $amount) {
            if (abs((float)str_replace(',','',$amount)-$total)<0.01) $quotedAt=(int)$index;
        }
    }
    $accepted=false;
    if ($quotedAt>=0) {
        foreach ($history as $index=>$h) {
            if ($index<=$quotedAt || ($h['role']??'')!=='user') continue;
            $text=trim((string)($h['content']??''));
            if (preg_match('/(?:\b(?:s[ií]|confirmo|acepto|de acuerdo|correcto|est[aá] bien|proceda|adelante|lo compro|me parece bien)\b|\bestoy de acuerdo\b)/iu',$text)) {
                $accepted=true;
                break;
            }
        }
    }
    // El cliente tambien puede confirmar expresamente el importe exacto en su mensaje,
    // aunque Carlos no haya escrito antes esa cifra en el historial recuperado.
    if (!$accepted) {
        foreach ($history as $h) {
            if (($h['role']??'')!=='user') continue;
            $text=(string)($h['content']??'');
            if (!preg_match('/(?:confirmo|acepto|de acuerdo|estoy de acuerdo)/iu',$text)) continue;
            if (!preg_match_all('/L\s*\.?\s*([\d,]+(?:\.\d{1,2})?)/iu',$text,$amounts)) continue;
            foreach ($amounts[1] as $amount) {
                if (abs((float)str_replace(',','',$amount)-$total)<0.01) {
                    $accepted=true;
                    break 2;
                }
            }
        }
    }
    if (!$accepted)return ['status'=>'total_pendiente','total'=>$total];
    $d['foto_identidad']=(string)$photo['ruta'];$d['foto_identidad_verificada']=true;
    $d['empresa_id']=1;$d['instance_id']=$instance;$d['telefono']=$phone;$d['ejecutivo_venta']=$owner;$d['fuente_venta']='WhatsApp '.$agentName;
    $d['idempotency_key']=$idem;$d['compra_confirmada']=true;$d['flete']=$freight;$d['flete_recomendado']=$freight;$d['cotizacion_verificada']=true;$d['total_confirmado']=$total;
    require_once __DIR__.'/royal_ai_multi_fichas_core.php';
    try{$r=rai_multi_ficha_crear($wa,$d);return ['status'=>'guardada','ficha_id'=>$r['ficha_id'],'supervisor'=>$r['supervisor']];}
    catch(Throwable $e){error_log('Multi IA ficha: '.$e->getMessage());return ['status'=>'guardar_bloqueado'];}
}
