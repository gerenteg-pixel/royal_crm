<?php
declare(strict_types=1);
/** Royal AI Sales Fase 3 - motor de BORRADORES en memoria, 100% lectura.
 * Ubicar en public_html/whatsapp/ junto al config existente.
 * No llama APIs, no escribe SQL, no envia mensajes, no crea fichas.
 */
ini_set('display_errors','0');
date_default_timezone_set('America/Tegucigalpa');
require_once dirname(__DIR__).'/auth.php';
require_once dirname(__DIR__).'/empresa_core.php';
require_permiso('ver_whatsapp_panel');
require_modulo('whatsapp_activo');
require_once __DIR__.'/conexion_whatsapp.php';
require_once __DIR__.'/auth_whatsapp.php';
wa_require_login();
$role=strtolower(trim((string)($_SESSION['rol']??'')));
if (!in_array($role,['admin','superadmin'],true)) { http_response_code(403); exit('Acceso restringido.'); }
$empresa=(int)wa_empresa_id();
if ($empresa<=0 || !isset($connWa) || !($connWa instanceof mysqli)) {http_response_code(503);exit('Conexion no disponible.');}
$config=require __DIR__.'/royal_ai_sales_config.php';
if (($config['mode']??'')!=='simulation' || ($config['outbound_enabled']??true)!==false) {http_response_code(409);exit('Modo seguro requerido.');}
function rai_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function rai_intent(string $s):array {
 $s=mb_strtolower($s,'UTF-8');
 if (preg_match('/precio|cu[aá]nto|costo|vale|cotiz/i',$s)) return ['Consulta de precio','¡Con gusto! ¿Qué tipo de paca te interesa? Voy a verificar el precio autorizado antes de darte una cotización.'];
 if (preg_match('/foto|imagen|video|ver (la|las)|mostrar/i',$s)) return ['Solicita multimedia','Con gusto te comparto las fotografías o videos disponibles del producto que te interesa, una vez verificados en el catálogo.'];
 if (preg_match('/env[ií]o|flete|entrega|domicilio|ubicaci[oó]n/i',$s)) return ['Consulta de entrega','¡Claro! ¿En qué ciudad o municipio necesitas recibir tu pedido? Confirmaré disponibilidad y flete antes de cotizar.'];
 if (preg_match('/disponib|tienen|hay|existencia|pacas|mercader[ií]a/i',$s)) return ['Consulta de mercadería','¡Con gusto te ayudo! ¿Qué tipo de mercadería buscas y cuántas pacas necesitas? Confirmaré la disponibilidad real.'];
 if (preg_match('/hola|buenas|buenos d[ií]as|buenas tardes|buenas noches/i',$s)) return ['Saludo','¡Hola! Bienvenido a las pacas de ropa usada. Soy tu asesor de ventas. ¿Qué tipo de mercadería estás buscando?'];
 return ['Consulta general','Con mucho gusto te atiendo. ¿Me cuentas qué tipo de mercadería buscas o qué información necesitas?'];
}
$agents=[];$byInstance=[];
foreach (($config['agents']??[]) as $key=>$a) {
 if (($a['enabled']??false)!==true) continue;
 $instance=trim((string)($a['instance_id']??''));if ($instance==='') continue;
 $agents[(string)$key]=['name'=>(string)($a['name']??'Agente IA'),'instance'=>$instance];
 $byInstance[$instance][]=(string)$key;
}
$results=[];$error='';$rows=0;$skipped=0;
try {
 $limit=min(500,max(50,(int)($config['max_rows']??200)));
 $window=min(1800,max(60,(int)($config['lookback_seconds']??300)));
 // Una consulta acotada: los mensajes mas recientes de la empresa; sin cambios a la BD.
 $sql='SELECT id,instance_id,wa_conversation_id,direction,texto,tipo,event_time FROM wa_messages WHERE empresa_id=? ORDER BY id DESC LIMIT '.$limit;
 $st=$connWa->prepare($sql);if(!$st)throw new RuntimeException('Consulta no disponible');
 $st->bind_param('i',$empresa);if(!$st->execute())throw new RuntimeException('Consulta no disponible');
 $rs=$st->get_result();$seen=[];$cutoff=time()-$window;
 while($row=$rs->fetch_assoc()) {
  $rows++;
  $instance=(string)($row['instance_id']??'');
  if (!isset($byInstance[$instance])) continue;
  $cid=(int)($row['wa_conversation_id']??0);
  if($cid<=0){$skipped++;continue;}
  $ts=strtotime((string)($row['event_time']??''));
  if(!$ts || $ts<$cutoff || $ts>time()+60)continue;
  $key=$instance.'|'.$cid;
  // Orden descendente: el primer mensaje es el ultimo. Si es saliente,
  // NO generar borrador; humano/salida ya intervino. No prueba toma de control permanente.
  if(isset($seen[$key]))continue;
  $seen[$key]=true;
  $dir=(string)($row['direction']??'');
  if($dir!=='in')continue;
  $text=trim((string)($row['texto']??''));
  if($text==='' || mb_strlen($text,'UTF-8')>4000)continue;
  if((string)($row['tipo']??'')!=='texto' && (string)($row['tipo']??'')!=='text')continue;
  $ids=$byInstance[$instance];
  // Si dos agentes estan configurados en el mismo canal, bloquear la sugerencia.
  if(count($ids)!==1){$skipped++;continue;}
  $agent=$agents[$ids[0]];
  [$intent,$draft]=rai_intent($text);
  $results[]=['agent'=>$agent['name'],'instance'=>$instance,'intent'=>$intent,'draft'=>$draft,
   'sample'=>mb_substr($text,0,180,'UTF-8'),'time'=>$row['event_time']];
  if(count($results)>=25)break;
 }
 $st->close();
} catch(Throwable $e){error_log('Royal AI Fase3: '.$e->getMessage());$error='No se pudo consultar la actividad. Revisa el registro de errores PHP.';}
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
include dirname(__DIR__).'/header.php';
?>
<style>
.rai3{background:#081426;color:#e8f1ff;padding:20px;border-radius:16px;margin:12px 0;font-family:inherit}.rai3 *{box-sizing:border-box}.rai3 h1{font-size:23px;margin:0 0 8px}.rai3 p{color:#a9bfdc}.rai3 .flag{display:inline-block;padding:6px 10px;border:1px solid #2b547a;border-radius:10px;color:#a9dfff;margin:6px 6px 6px 0}.rai3 .item{background:#10233d;border:1px solid #2c476b;padding:15px;border-radius:12px;margin:12px 0}.rai3 .meta{font-size:12px;color:#a9bfdc}.rai3 .draft{border-left:3px solid #35c6ac;padding:10px 12px;background:#092b36;margin-top:10px;white-space:pre-wrap}.rai3 .sample{color:#e9eefa;white-space:pre-wrap;overflow-wrap:anywhere}.rai3 a{color:#8ec8ff}.rai3 .warn{color:#ffcf8c}.rai3 .btn{background:#1d5cba;border-radius:8px;color:white;padding:9px 13px;text-decoration:none;display:inline-block}
</style>
<section class="rai3">
<h1>Royal AI Sales · Laboratorio de conversaciones</h1>
<div class="flag">FASE 3 · SIMULACION</div><div class="flag">Envios desactivados</div><div class="flag">Hasta 30 agentes configurables</div>
<p>Genera sugerencias de respuesta en memoria a partir de mensajes recientes. No utiliza un modelo de IA externo, no consulta precios ni inventario y no envia mensajes. Los borradores son plantillas de prueba, no respuestas listas para clientes.</p>
<a class="btn" href="royal_ai_sales_motor_simulacion.php">Actualizar simulacion</a>
<p class="meta">Registros examinados: <?= (int)$rows ?> · Borradores: <?= count($results) ?> · Conversaciones omitidas por seguridad: <?= (int)$skipped ?>. La ausencia de borradores no implica fallo.</p>
<?php if($error):?><p class="warn"><?=rai_h($error)?></p><?php endif;?>
<?php if(!$results):?><div class="item">No hay mensajes de texto entrantes recientes que cumplan los criterios de simulacion. No se ha enviado nada.</div><?php endif;?>
<?php foreach($results as $r):?><article class="item">
 <div><strong><?=rai_h($r['agent'])?></strong> · <?=rai_h($r['intent'])?></div>
 <div class="meta">Instancia: <?=rai_h(substr($r['instance'],0,8))?>… · <?=rai_h($r['time'])?></div>
 <p class="meta">Ultimo mensaje entrante (vista limitada, solo administracion):</p>
 <div class="sample"><?=rai_h($r['sample'])?></div>
 <div class="draft"><strong>Borrador NO enviado:</strong> <?=rai_h($r['draft'])?></div>
</article><?php endforeach;?>
<p class="warn">Seguridad: no se escribe en wa_messages, wa_assignments ni en ninguna tabla. Un mensaje saliente mas reciente excluye la conversacion de esta muestra; esto NO sustituye un bloqueo real de intervencion humana. No activar respuestas automaticas con este archivo.</p>
<a href="/royal_media_inventario.php">Volver a Royal Media Inventory</a>
</section>
<?php include dirname(__DIR__).'/footer.php'; ?>
