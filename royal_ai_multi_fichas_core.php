<?php
declare(strict_types=1);
/** Servicio de fichas exclusivo para agentes IA 2, 3 y 4. */
function rai_multi_ficha_crear(mysqli $wa, array $d): array {
    $empresa=1; $phone=(string)($d['telefono']??''); $owner=(string)($d['ejecutivo_venta']??'');
    $instance=(string)($d['instance_id']??'');
    $cfg=require __DIR__.'/royal_ai_sales_auto_config.php';
    $agentId=0;
    foreach ((array)($cfg['agents']??[]) as $id=>$agent) {
        if (($agent['instance_id']??'')===$instance && ($agent['human_owner']??'')===$owner && ($agent['audience']??'')==='assigned_owner') { $agentId=(int)$id; break; }
    }
    if ((int)($d['empresa_id']??0)!==$empresa || !preg_match('/^504[0-9]{8}$/',$phone) || ($agentId<2 || $agentId>30))
        throw new RuntimeException('Operacion fuera de agente autorizado');
    $agent=$cfg['agents'][$agentId];
    if (($cfg['auto_enabled']??false)!==true || ($cfg['send_enabled']??false)!==true || ($agent['auto_enabled']??false)!==true || ($agent['send_enabled']??false)!==true)
        throw new RuntimeException('Agente desactivado');
    require_once __DIR__.'/royal_ai_sales_multi_control.php';
    $slots=[$agentId=>(string)($agent['slot']??([2=>'instancia_4',3=>'instancia_3',4=>'instancia_2'][$agentId]??''))];
    if (empty(rai_agent_status($empresa,$agentId,$slots[$agentId])['active'])) throw new RuntimeException('Estacion IA inactiva');
    $source='WhatsApp '.(string)$agent['name'];
    $check=$wa->prepare('SELECT activa,nombre FROM wa_instancias WHERE empresa_id=? AND instance_id=? LIMIT 1');
    if (!$check) throw new RuntimeException('No se puede validar instancia');
    $check->bind_param('is',$empresa,$instance);$check->execute();$inst=$check->get_result()->fetch_assoc();$check->close();
    if (!$inst || (int)$inst['activa']!==1 || strcasecmp((string)$inst['nombre'],$owner)!==0) throw new RuntimeException('Instancia o propietario no autorizado');
    $required=['nombre_cliente','identidad','ciudad','departamento','direccion_completa','referencias','referencia_telefonica_1','referencia_telefonica_2','fuente_venta','nombre_chat','foto_identidad'];
    foreach ($required as $field) if (trim((string)($d[$field]??''))==='') throw new RuntimeException('Falta dato verificado: '.$field);
    if (($d['compra_confirmada']??false)!==true || ($d['foto_identidad_verificada']??false)!==true || ($d['cotizacion_verificada']??false)!==true)
        throw new RuntimeException('Falta confirmacion de compra, identidad o cotizacion');
    if ((string)$d['fuente_venta']!==$source) throw new RuntimeException('Origen no autorizado');
    $productId=(int)($d['producto_id']??0); $qty=(int)($d['cantidad']??0); $freight=(float)($d['flete']??-1);
    if ($productId<1||$qty<1||$qty>3||!is_finite($freight)||$freight<0) throw new RuntimeException('Producto, cantidad o flete no valido');
    $photo=(string)$d['foto_identidad'];
    if (!preg_match('~^whatsapp/identidades/empresa_1/identidad_[A-Za-z0-9_.-]+\.(jpg|png|webp)$~',$photo)) throw new RuntimeException('Ruta de identidad no admitida');
    if (!is_file(dirname(__DIR__).'/'.$photo)) throw new RuntimeException('Foto de identidad no localizada');
    $idem=(string)($d['idempotency_key']??'');
    if (!preg_match('/^multi_[a-zA-Z0-9:_-]{8,90}$/',$idem)) throw new RuntimeException('Clave de idempotencia requerida');
    $marker='[MULTI_IA_KEY:'.$idem.']';
    $lockName='multi_ficha_'.hash('sha256',$empresa.':'.$phone.':'.$idem);
    $lk=$wa->prepare('SELECT GET_LOCK(?, 5) AS adquirido');$lk->bind_param('s',$lockName);$lk->execute();$got=(int)($lk->get_result()->fetch_assoc()['adquirido']??0);$lk->close();
    if ($got!==1) throw new RuntimeException('Otra solicitud de ficha esta en proceso');
    try {
        $wa->begin_transaction();
        try {
            $like='%'.$marker.'%';
            $s=$wa->prepare('SELECT id FROM wa_fichas_venta WHERE empresa_id=? AND telefono=? AND referencias LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $s->bind_param('iss',$empresa,$phone,$like);$s->execute();$prior=$s->get_result()->fetch_assoc();$s->close();
            if ($prior) {
                $s=$wa->prepare("SELECT asignado_a FROM royal_preaprobaciones WHERE empresa_id=? AND tipo='ficha' AND ficha_id=? ORDER BY id DESC LIMIT 1");
                $fid=(int)$prior['id'];$s->bind_param('ii',$empresa,$fid);$s->execute();$pr=$s->get_result()->fetch_assoc();$s->close();
                $wa->commit();return ['ok'=>true,'ficha_id'=>$fid,'supervisor'=>(string)($pr['asignado_a']??'cristian07'),'duplicada'=>true];
            }
            $s=$wa->prepare('SELECT asignado_a FROM wa_assignments WHERE empresa_id=? AND telefono=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $s->bind_param('is',$empresa,$phone);$s->execute();$a=$s->get_result()->fetch_assoc();$s->close();
            if (!$a || strcasecmp(trim((string)$a['asignado_a']),$owner)!==0) throw new RuntimeException('La asignacion del cliente cambio');
            $s=$wa->prepare('SELECT id,nombre,precio,stock_actual FROM wa_catalogo_productos WHERE empresa_id=? AND id=? AND activo=1 LIMIT 1 FOR UPDATE');
            $s->bind_param('ii',$empresa,$productId);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
            if (!$p || (int)$p['stock_actual']<$qty || (float)$p['precio']<=0) throw new RuntimeException('Inventario no disponible');
            $unit=(float)$p['precio'];$total=round($unit*$qty+$freight,2);
            if (abs($total-(float)($d['total_confirmado']??-1))>0.009) throw new RuntimeException('Total distinto de la cotizacion');
            $date=date('Y-m-d');$name=trim((string)$d['nombre_cliente']);$ident=trim((string)$d['identidad']);
            $city=trim((string)$d['ciudad']);$dept=trim((string)$d['departamento']);$addr=trim((string)$d['direccion_completa']);
            $ref1=trim((string)$d['referencia_telefonica_1']);$ref2=trim((string)$d['referencia_telefonica_2']);
            $pay=trim((string)($d['metodo_pago']??''));if($pay!=='contado') throw new RuntimeException('Pago no validado para piloto IA');
            $warehouse='';$driver='';$source=(string)$d['fuente_venta'];$chat=(string)$d['nombre_chat'];
            $identidadExcepcion=0;$identidadAutorizadaPor=null;$identidadAutorizadaRol=null;$identidadAutorizadaAt=null;$identidadAutorizacionMotivo=null;
            $fechaVencimiento=null;$codigoFurgon=null;$productName=(string)$p['nombre'];
            $refs=trim((string)$d['referencias']).' '.$marker."\n\n[PRODUCTOS_JSON]\n".json_encode([['producto_id'=>$productId,'nombre'=>$productName,'cantidad'=>$qty,'precio_unitario'=>$unit,'subtotal'=>$unit*$qty]],JSON_UNESCAPED_UNICODE);
            $s=$wa->prepare("INSERT INTO wa_fichas_venta (empresa_id,fecha,nombre_cliente,identidad,foto_identidad,identidad_excepcion,identidad_autorizada_por,identidad_autorizada_rol,identidad_autorizada_at,identidad_autorizacion_motivo,telefono,ciudad,departamento,direccion_completa,referencias,producto_id,producto,cantidad,precio_por_paca,flete,total_pagar,referencia_telefonica_1,referencia_telefonica_2,metodo_pago,fecha_vencimiento,ejecutivo_venta,bodega,fuente_venta,motorista,codigo_furgon,telefono_chat,nombre_chat,creado_por,estado_alerta) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'nueva')");
            if (!$s) throw new RuntimeException('No se pudo preparar ficha');
            $s->bind_param('issssisssssssssisidddssssssssssss',
                $empresa,$date,$name,$ident,$photo,$identidadExcepcion,$identidadAutorizadaPor,$identidadAutorizadaRol,$identidadAutorizadaAt,$identidadAutorizacionMotivo,
                $phone,$city,$dept,$addr,$refs,$productId,$productName,$qty,$unit,$freight,$total,$ref1,$ref2,$pay,$fechaVencimiento,$owner,$warehouse,$source,$driver,$codigoFurgon,$phone,$chat,$owner);
            if (!$s->execute()) throw new RuntimeException('No se pudo insertar ficha: '.$s->error);
            $id=(int)$s->insert_id;$s->close();if($id<=0)throw new RuntimeException('Ficha sin identificador');
            $recommended=(float)($d['flete_recomendado']??-1);
            if ($recommended<0 || abs($recommended-$freight)>0.009) throw new RuntimeException('Flete sin validacion consistente');
            require_once dirname(__DIR__).'/royal_rutas_core.php';
            $cfg=royal_rutas_config($wa,$empresa);$pre=trim((string)($cfg['usuario_preaprobador']??'cristian07'));
            if($pre==='')throw new RuntimeException('Supervisor no configurado');
            $difference=$freight-$recommended;$obs='Ficha '.(string)$agent['name'].': bodega y motorista pendientes de asignacion por supervisor. Flete verificado antes de guardar.';
            $s=$wa->prepare("INSERT INTO royal_preaprobaciones (empresa_id,tipo,ficha_id,estado,flete_original,flete_recomendado,diferencia,observacion,asignado_a) VALUES (?,'ficha',?,'pendiente',?,?,?,?,?)");
            if (!$s) throw new RuntimeException('No se pudo preparar preaprobacion');
            $s->bind_param('iidddss',$empresa,$id,$freight,$recommended,$difference,$obs,$pre);
            if (!$s->execute()) throw new RuntimeException('No se pudo crear preaprobacion: '.$s->error);$s->close();
            $wa->commit();return ['ok'=>true,'ficha_id'=>$id,'supervisor'=>$pre,'total'=>$total,'duplicada'=>false];
        } catch(Throwable $e) {$wa->rollback();throw $e;}
    } finally {
        $rel=$wa->prepare('SELECT RELEASE_LOCK(?)');if($rel){$rel->bind_param('s',$lockName);$rel->execute();$rel->close();}
    }
}
