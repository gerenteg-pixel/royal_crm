<?php
declare(strict_types=1);
/** Servicio interno. Nunca ejecutar directamente por HTTP. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); exit; }
/** Registrar SOLO despues de consentimiento expreso de preventa verificado por el controlador. */
function rai_preventa_registrar(mysqli $db,int $empresa,string $instancia,string $telefono,int $conversacion,int $productoId,int $cantidad,string $ciudad='',string $departamento=''): array {
    if ($empresa<1 || $productoId<1 || $cantidad<1 || $cantidad>3 || $conversacion<1 || !preg_match('/^504[0-9]{8}$/',$telefono) || $instancia==='') throw new InvalidArgumentException('Datos de preventa invalidos');
    $db->begin_transaction();
    try {
        $s=$db->prepare('SELECT id,nombre,stock_actual FROM wa_catalogo_productos WHERE empresa_id=? AND id=? FOR UPDATE');
        if (!$s) throw new RuntimeException('Catalogo no disponible');
        $s->bind_param('ii',$empresa,$productoId); $s->execute(); $p=$s->get_result()->fetch_assoc(); $s->close();
        if (!$p) throw new RuntimeException('Producto inexistente');
        if ((int)$p['stock_actual']>0) { $db->rollback(); return ['status'=>'stock_disponible','producto'=>$p['nombre']]; }
        $nombre=(string)$p['nombre'];
        $s=$db->prepare("SELECT id FROM royal_ai_preventas WHERE empresa_id=? AND instance_id=? AND telefono=? AND producto_id=? AND estado IN ('pendiente_stock','stock_detectado','contactado') ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $s->bind_param('issi',$empresa,$instancia,$telefono,$productoId); $s->execute(); $old=$s->get_result()->fetch_assoc(); $s->close();
        if ($old) { $db->commit(); return ['status'=>'ya_registrada','preventa_id'=>(int)$old['id'],'producto_id'=>$productoId]; }
        $s=$db->prepare("INSERT INTO royal_ai_preventas (empresa_id,instance_id,telefono,conversation_id,producto_id,producto_nombre,cantidad,ciudad,departamento,consentimiento_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
        $s->bind_param('issiiisss',$empresa,$instancia,$telefono,$conversacion,$productoId,$nombre,$cantidad,$ciudad,$departamento);
        $s->execute(); $id=(int)$db->insert_id; $s->close(); $db->commit();
        return ['status'=>'registrada','preventa_id'=>$id,'producto_id'=>$productoId];
    } catch(Throwable $e) { $db->rollback(); throw $e; }
}
/** Encola un evento durable; NO manda WhatsApp ni modifica stock. */
function rai_preventa_evento_stock(mysqli $db,int $empresa,int $productoId,int $loteId,int $anterior,int $nuevo): bool {
    if ($empresa<1 || $productoId<1 || $loteId<1 || $anterior>=1 || $nuevo<1) return false;
    $s=$db->prepare("INSERT IGNORE INTO royal_ai_preventa_eventos (empresa_id,producto_id,lote_id,stock_anterior,stock_nuevo) VALUES (?,?,?,?,?)");
    if (!$s) throw new RuntimeException('No se pudo registrar evento de inventario');
    $s->bind_param('iiiii',$empresa,$productoId,$loteId,$anterior,$nuevo); $ok=$s->execute(); $s->close(); return $ok;
}
/** Consulta pendientes para un proceso autorizado. NO autoriza enviar mensajes por si misma. */
function rai_preventas_para_stock(mysqli $db,int $empresa,int $productoId): array {
    $s=$db->prepare("SELECT p.id,p.instance_id,p.telefono,p.conversation_id,p.producto_id,p.producto_nombre,p.cantidad,p.ciudad,p.departamento FROM royal_ai_preventas p JOIN wa_catalogo_productos c ON c.empresa_id=p.empresa_id AND c.id=p.producto_id WHERE p.empresa_id=? AND p.producto_id=? AND p.estado='pendiente_stock' AND c.stock_actual>0 ORDER BY p.id ASC LIMIT 100");
    if (!$s) throw new RuntimeException('Consulta preventas no disponible');
    $s->bind_param('ii',$empresa,$productoId); $s->execute(); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $rows;
}
