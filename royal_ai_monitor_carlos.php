<?php
/** Monitor Carlos IA: solo lectura. Instalar en /public_html/whatsapp/. */
declare(strict_types=1);
ini_set('display_errors','0');
date_default_timezone_set('America/Tegucigalpa');
require_once dirname(__DIR__).'/auth.php';
require_once dirname(__DIR__).'/empresa_core.php';
require_permiso('ver_whatsapp_panel');
require_once __DIR__.'/conexion_whatsapp.php';
require_once __DIR__.'/auth_whatsapp.php';
wa_require_login();
require_modulo('whatsapp_activo');
$empresa=(int)wa_empresa_id();
$usuario=trim((string)($_SESSION['usuario']??''));
$rol=strtolower(trim((string)($_SESSION['rol']??'')));
$cfgFile=__DIR__.'/royal_ai_sales_auto_config.php';
$cfg=is_file($cfgFile)?require $cfgFile:[];
$agent=is_array($cfg)?($cfg['agents'][1]??[]):[];
$instancia=trim((string)($agent['instance_id']??''));
$propietario=trim((string)($agent['human_owner']??''));
$nombre=trim((string)($agent['name']??'Carlos IA'));
if ($empresa<=0 || $instancia==='' || $propietario==='' || (int)($cfg['company_id']??0)!==$empresa || !(in_array($rol,['admin','superadmin','gestion'],true)||strcasecmp($usuario,$propietario)===0)) {
    http_response_code(403);exit('Acceso no autorizado al monitor.');
}
function mon_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function mon_query(mysqli $db,string $sql,string $types,array $params):array{
    $st=$db->prepare($sql);if(!$st)throw new RuntimeException('Consulta no disponible');
    if($types!=='')$st->bind_param($types,...$params);
    if(!$st->execute()){ $st->close();throw new RuntimeException('No se pudo consultar'); }
    $rs=$st->get_result();$out=$rs?$rs->fetch_all(MYSQLI_ASSOC):[];$st->close();return $out;
}
$base=" AND EXISTS (SELECT 1 FROM wa_assignments a WHERE a.empresa_id=m.empresa_id AND a.telefono COLLATE utf8mb4_unicode_ci=m.enviado_por COLLATE utf8mb4_unicode_ci AND LOWER(TRIM(a.asignado_a))=LOWER(TRIM(?)) AND a.id=(SELECT MAX(a2.id) FROM wa_assignments a2 WHERE a2.empresa_id=a.empresa_id AND a2.telefono COLLATE utf8mb4_unicode_ci=a.telefono COLLATE utf8mb4_unicode_ci))";
if(isset($_GET['ajax'])){
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private, max-age=0');header('X-Content-Type-Options: nosniff');
    try{
        if($_GET['ajax']==='chats'){
            $sql="SELECT m.enviado_por AS telefono,MAX(m.id) AS ultimo_id,MAX(m.event_time) AS ultima_fecha,COUNT(*) AS total_mensajes, SUM(CASE WHEN m.direction='in' THEN 1 ELSE 0 END) AS recibidos FROM wa_messages m WHERE m.empresa_id=? AND m.instance_id=? AND m.enviado_por REGEXP '^504[0-9]{8}$'".$base." GROUP BY m.enviado_por ORDER BY ultimo_id DESC LIMIT 80";
            $rows=mon_query($connWa,$sql,'iss',[$empresa,$instancia,$propietario]);
            echo json_encode(['ok'=>true,'chats'=>$rows,'actualizado'=>date('H:i:s')],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
        }
        if($_GET['ajax']==='mensajes'){
            $tel=preg_replace('/\D/','',(string)($_GET['telefono']??''));
            if(!preg_match('/^504[0-9]{8}$/',$tel)){http_response_code(400);throw new RuntimeException('Teléfono inválido');}
            $sql="SELECT m.id,m.direction,m.tipo,m.texto,m.event_time,m.enviado_por,m.status_envio FROM wa_messages m WHERE m.empresa_id=? AND m.instance_id=? AND m.enviado_por=?".$base." ORDER BY m.id DESC LIMIT 100";
            $rows=mon_query($connWa,$sql,'isss',[$empresa,$instancia,$tel,$propietario]);
            echo json_encode(['ok'=>true,'mensajes'=>array_reverse($rows),'telefono'=>$tel],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
        }
        http_response_code(400);throw new RuntimeException('Operación no disponible');
    }catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Monitor en vivo · <?=mon_h($nombre)?></title><style>
:root{font-family:Inter,system-ui,Arial,sans-serif;color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#0b1220;color:#edf3ff}.top{padding:20px 26px;background:#111c2d;border-bottom:1px solid #27364d;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}.top h1{font-size:21px;margin:0 0 5px}.muted{color:#9fb0c7;font-size:13px}.pill{padding:7px 12px;border-radius:50px;background:#173c33;color:#87e6b8;font-size:12px}.wrap{display:grid;grid-template-columns:330px 1fr;min-height:calc(100vh - 90px)}aside{border-right:1px solid #27364d;background:#101a29}.side-title{padding:18px 20px;border-bottom:1px solid #27364d;font-weight:700}.chat-item{padding:15px 20px;border-bottom:1px solid #243145;cursor:pointer}.chat-item:hover,.chat-item.active{background:#20324a}.chat-item strong{display:block;font-size:14px}.chat-item small{display:block;color:#9fb0c7;margin-top:5px}main{display:flex;flex-direction:column;min-height:70vh}.conversation-title{padding:17px 22px;border-bottom:1px solid #27364d;font-weight:700}.messages{padding:22px;flex:1;overflow:auto;max-height:calc(100vh - 185px);display:flex;flex-direction:column;gap:13px}.bubble{max-width:78%;border-radius:15px;padding:11px 14px;background:#263750;align-self:flex-start;white-space:pre-wrap;overflow-wrap:anywhere;font-size:14px;line-height:1.5}.bubble.out{background:#164c44;align-self:flex-end}.bubble .meta{font-size:11px;color:#b8c6d6;margin-top:7px}.empty{color:#a5b5cb;padding:25px}.error{color:#ffb2b2}.note{padding:10px 22px;border-top:1px solid #27364d;color:#9fb0c7;font-size:12px}@media(max-width:700px){.wrap{grid-template-columns:1fr}aside{max-height:220px;overflow:auto;border-right:0;border-bottom:1px solid #27364d}.messages{max-height:55vh}.bubble{max-width:94%}}
</style></head><body><header class="top"><div><h1>Monitor en vivo · <?=mon_h($nombre)?></h1><div class="muted">Instancia de <?=mon_h($propietario)?> · Empresa <?=mon_h($empresa)?> · Supervisión de solo lectura</div></div><span class="pill" id="estado">Actualizando…</span></header><div class="wrap"><aside><div class="side-title">Conversaciones <span id="cantidad" class="muted"></span></div><div id="chats" class="empty">Cargando conversaciones…</div></aside><main><div class="conversation-title" id="titulo">Selecciona una conversación</div><div class="messages" id="mensajes"><div class="empty">Aquí podrás observar los mensajes sin marcarlos como leídos ni modificar asignaciones.</div></div><div class="note">Actualización automática cada 8 segundos. Este monitor no envía mensajes, no ejecuta IA y no modifica datos.</div></main></div><script>
let seleccionado='',ultimaLista='',ultimaConversacion='';const $=id=>document.getElementById(id);function el(tag,cls,text){const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e;}
async function pedir(tipo,telefono=''){const u=new URL(location.href);u.search='';u.searchParams.set('ajax',tipo);if(telefono)u.searchParams.set('telefono',telefono);const r=await fetch(u.toString(),{credentials:'same-origin',cache:'no-store'});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Error de consulta');return j;}
async function cargarChats(){try{const d=await pedir('chats');$('estado').textContent='En vivo · '+d.actualizado;$('cantidad').textContent='('+d.chats.length+')';const firma=JSON.stringify(d.chats.map(c=>[c.telefono,c.ultimo_id]));if(firma!==ultimaLista){ultimaLista=firma;const caja=$('chats');caja.replaceChildren();if(!d.chats.length)caja.append(el('div','empty','No hay conversaciones asignadas a este agente en esta instancia.'));for(const c of d.chats){const item=el('div','chat-item'+(seleccionado===c.telefono?' active':''));item.append(el('strong','',c.telefono),el('small','',`${c.total_mensajes} mensajes · ${c.ultima_fecha||''}`));item.onclick=()=>{seleccionado=c.telefono;ultimaConversacion='';cargarChats();cargarMensajes();};caja.append(item);}if(seleccionado&&!d.chats.some(c=>c.telefono===seleccionado)){seleccionado='';ultimaConversacion='';$('titulo').textContent='Selecciona una conversación';$('mensajes').replaceChildren(el('div','empty','La conversación ya no está asignada a este agente.'));}}}catch(e){$('estado').textContent='Sin conexión';$('chats').replaceChildren(el('div','empty error',e.message));}}
async function cargarMensajes(){if(!seleccionado)return;const actual=seleccionado;try{const d=await pedir('mensajes',actual);if(actual!==seleccionado)return;$('titulo').textContent='Conversación · '+actual;const firma=JSON.stringify(d.mensajes.map(m=>[m.id,m.status_envio]));if(firma===ultimaConversacion)return;ultimaConversacion=firma;const caja=$('mensajes');const alFinal=caja.scrollHeight-caja.scrollTop-caja.clientHeight<110;caja.replaceChildren();if(!d.mensajes.length)caja.append(el('div','empty','Sin mensajes disponibles.'));for(const m of d.mensajes){const out=m.direction==='out';const b=el('div','bubble'+(out?' out':''));b.append(el('div','',m.texto||`[${m.tipo||'Mensaje multimedia'}]`),el('div','meta',`${out?'Salida':'Cliente'} · ${m.event_time||''}${m.status_envio?' · '+m.status_envio:''}`));caja.append(b);}if(alFinal)caja.scrollTop=caja.scrollHeight;}catch(e){$('mensajes').replaceChildren(el('div','empty error',e.message));}}
async function actualizar(){await cargarChats();await cargarMensajes();}actualizar();setInterval(actualizar,8000);
</script></body></html>
