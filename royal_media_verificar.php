<?php
require_once __DIR__.'/royal_media_core.php';
$result=null;$error='';
if (isset($_GET['token'])) {
 $token=(string)$_GET['token'];
 if(preg_match('/^[a-f0-9]{64}$/D',$token)){
  $s=rm_stmt($conn,'SELECT id FROM royal_media_pacas WHERE empresa_id=? AND qr_token=?','is',[$rm_empresa,$token]);$r=$s->get_result()->fetch_assoc();$s->close();
  if($r)$result=rm_paca($conn,$rm_empresa,(int)$r['id']);else $error='Paca no encontrada en esta empresa.';
 }else{$error='QR inválido.';}
}

if($_SERVER['REQUEST_METHOD']==='POST'){
 rm_check_csrf();$value=trim((string)($_POST['codigo']??''));
 if(preg_match('/^RM1:([0-9]+):([a-f0-9]{64})$/D',$value,$m)){
  if((int)$m[1]!==$rm_empresa){$error='El código corresponde a otra empresa.';}
  else{$s=rm_stmt($conn,'SELECT id FROM royal_media_pacas WHERE empresa_id=? AND qr_token=?','is',[$rm_empresa,$m[2]]);$r=$s->get_result()->fetch_assoc();$s->close();if($r)$result=rm_paca($conn,$rm_empresa,(int)$r['id']);else $error='Código no registrado.';}
 }else{
  $s=rm_stmt($conn,'SELECT id FROM royal_media_pacas WHERE empresa_id=? AND codigo_paca=?','is',[$rm_empresa,$value]);$r=$s->get_result()->fetch_assoc();$s->close();if($r)$result=rm_paca($conn,$rm_empresa,(int)$r['id']);else $error='Código no válido o no registrado.';
 }
}
rm_head('Verificar QR en bodega');
if($error)echo '<p class="err">'.rm_h($error).'</p>';
?><section class="card"><h2>Verificar identificación</h2><p class="muted">Usa un lector QR USB o un lector móvil que copie el contenido del QR en el campo. También puedes escribir el código de la paca. La validación de orden de salida se conectará en la siguiente fase.</p><form method="post"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><label>Contenido QR o código de paca</label><input name="codigo" autofocus required><p><button>Verificar</button></p></form></section><?php if($result):?><section class="card"><h2>Identificación encontrada</h2><p><strong><?=rm_h($result['codigo_paca'])?></strong> · <?=rm_h($result['producto_nombre'])?></p><p>Lote: <?=rm_h($result['codigo_lote'])?></p><p>Estado registrado: <strong><?=rm_h($result['estado'])?></strong></p><p class="muted">La identificación física es válida; esto NO confirma todavía que pertenezca a una orden de salida.</p><a class="btn" href="royal_media_paca.php?id=<?=(int)$result['id']?>">Ver ficha y fotografías</a></section><?php endif;rm_foot(); ?>
