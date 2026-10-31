<?php
require_once __DIR__.'/royal_media_core.php';
$id=(int)($_GET['id']??$_POST['id']??0);
$p=rm_paca($conn,$rm_empresa,$id);if(!$p){http_response_code(404);exit('Paca no encontrada');}
$error='';$ok='';
try {
 if ($_SERVER['REQUEST_METHOD']==='POST') {
  rm_check_csrf();$accion=(string)($_POST['accion']??'');
  if ($accion==='subir') {
   if (!isset($_FILES['archivo']) || $_FILES['archivo']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('No se recibió un archivo válido. Revisa también los límites de subida de PHP.');
   $f=$_FILES['archivo'];if ($f['size']<=0 || $f['size']>50*1024*1024) throw new RuntimeException('Archivo vacío o mayor de 50 MB.');
   $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
   $allowed=['image/jpeg'=>['foto','jpg'],'image/png'=>['foto','png'],'image/webp'=>['foto','webp'],'video/mp4'=>['video','mp4'],'video/webm'=>['video','webm']];
   if (!isset($allowed[$mime])) throw new RuntimeException('Solo JPG, PNG, WEBP, MP4 y WEBM.');
   [$tipo,$ext]=$allowed[$mime];
   $relative='royal_media_uploads/'.$rm_empresa.'/'.$id;
   $base=__DIR__.'/'.$relative;
   if (!is_dir($base) && !mkdir($base,0750,true) && !is_dir($base)) throw new RuntimeException('No se pudo crear la carpeta de archivos.');
   $name=bin2hex(random_bytes(16)).'.'.$ext;$path=$base.'/'.$name;
   if (!move_uploaded_file($f['tmp_name'],$path)) throw new RuntimeException('No se pudo guardar el archivo.');
   @chmod($path,0640);
   try {
    $s=rm_stmt($conn,'INSERT INTO royal_media_archivos (empresa_id,paca_id,tipo,ruta_archivo,nombre_original,mime_type,tamano_bytes,creado_por) VALUES (?,?,?,?,?,?,?,?)','iissssis',[$rm_empresa,$id,$tipo,$relative.'/'.$name,mb_substr(basename((string)$f['name']),0,255),$mime,(int)$f['size'],(string)($_SESSION['usuario']??'crm')]);$s->close();
    $s=rm_stmt($conn,"INSERT INTO royal_media_eventos (empresa_id,paca_id,evento,descripcion,realizado_por) VALUES (?,?,'archivo_agregado',?,?)",'iiss',[$rm_empresa,$id,$tipo.' agregado',(string)($_SESSION['usuario']??'crm')]);$s->close();
   } catch(Throwable $e){@unlink($path);throw $e;}
   $ok='Archivo guardado correctamente.';
  }
  if ($accion==='comercial') {
   $descripcion=mb_substr(trim((string)($_POST['descripcion']??'')),0,10000);
   $caracteristicas=mb_substr(trim((string)($_POST['caracteristicas_ia']??'')),0,5000);
   $condicion=mb_substr(trim((string)($_POST['condicion_ia']??'')),0,100);
   $observaciones=mb_substr(trim((string)($_POST['observaciones_ia']??'')),0,5000);
   $lista=isset($_POST['lista_ia'])?1:0;
   $s=rm_stmt($conn,'SELECT stock_actual FROM wa_catalogo_productos WHERE id=? AND empresa_id=?','ii',[(int)$p['producto_id'],$rm_empresa]);$stock=(int)($s->get_result()->fetch_assoc()['stock_actual']??0);$s->close();
   $s=rm_stmt($conn,"SELECT COUNT(*) AS total FROM royal_media_archivos WHERE empresa_id=? AND paca_id=? AND tipo='foto'",'ii',[$rm_empresa,$id]);$fotos=(int)$s->get_result()->fetch_assoc()['total'];$s->close();
   if($lista && ($stock<1 || $p['estado']!=='disponible' || $fotos<1 || $descripcion==='' || $caracteristicas==='' || $condicion==='')) throw new RuntimeException('Para habilitar IA: paca disponible, stock oficial positivo, fotografía y descripción, características y condición completas.');
   $s=rm_stmt($conn,'UPDATE royal_media_pacas SET descripcion=?,caracteristicas_ia=?,condicion_ia=?,observaciones_ia=?,lista_ia=? WHERE id=? AND empresa_id=?','ssssiii',[$descripcion,$caracteristicas,$condicion,$observaciones,$lista,$id,$rm_empresa]);$s->close();
   $ok='Ficha comercial guardada.';
  }
   if ($accion==='referencial_ia') {
    if (!$rm_admin) throw new RuntimeException('Solo administracion puede autorizar referencias historicas.');
    $habilitar=isset($_POST['foto_referencial_ia'])?1:0;
    if ($habilitar) {
     $s=rm_stmt($conn,"SELECT COUNT(*) AS total FROM royal_media_archivos WHERE empresa_id=? AND paca_id=? AND tipo='foto'",'ii',[$rm_empresa,$id]);
     $fotos=(int)($s->get_result()->fetch_assoc()['total']??0);$s->close();
     if ($fotos<1) throw new RuntimeException('Esta paca no tiene fotografias para autorizar.');
    }
    $s=rm_stmt($conn,'UPDATE royal_media_pacas SET foto_referencial_ia=? WHERE id=? AND empresa_id=?','iii',[$habilitar,$id,$rm_empresa]);$s->close();
    $s=rm_stmt($conn,"INSERT INTO royal_media_eventos (empresa_id,paca_id,evento,descripcion,realizado_por) VALUES (?,?,'referencia_ia',?,?)",'iiss',[$rm_empresa,$id,$habilitar?'Fotografias autorizadas como referencia historica':'Autorizacion de referencia historica retirada',(string)($_SESSION['usuario']??'crm')]);$s->close();
    $ok=$habilitar?'Fotografias autorizadas solo como referencia historica.':'Autorizacion historica retirada.';
   }
  if ($accion==='ubicacion') {
   $ubicacion=mb_substr(trim((string)($_POST['ubicacion']??'')),0,150);
   $s=rm_stmt($conn,'UPDATE royal_media_pacas SET ubicacion_bodega=? WHERE id=? AND empresa_id=?','sii',[$ubicacion,$id,$rm_empresa]);$s->close();$ok='Ubicación guardada.';
  }
 }
} catch(Throwable $e){$error=$e->getMessage();}
$p=rm_paca($conn,$rm_empresa,$id);
$archivos=rm_stmt($conn,'SELECT id,tipo,nombre_original,created_at FROM royal_media_archivos WHERE empresa_id=? AND paca_id=? ORDER BY id DESC','ii',[$rm_empresa,$id])->get_result()->fetch_all(MYSQLI_ASSOC);
// Igual que Royal Media Inventory: encabezado oficial cargado en el alcance principal.
include __DIR__ . '/header.php';
?>
<style>
.rmp{--line:rgba(100,150,255,.20);--muted:#a6bddb;color:#f8fbff;background:radial-gradient(circle at 85% 0%,rgba(47,109,246,.14),transparent 35%),linear-gradient(140deg,#07101f,#0a172b);padding:22px;border-radius:22px;min-height:75vh;width:100%;box-sizing:border-box}
.rmp *{box-sizing:border-box}.rmp h1,.rmp h2,.rmp p,.rmp label{color:inherit}.rmp h1{font-size:27px;margin:5px 0}.rmp h2{font-size:18px;margin:0 0 8px}.rmp p{line-height:1.5}.rmp .rmp-muted{color:var(--muted);font-size:13px}.rmp .rmp-kicker{color:#7de9ff;font-weight:800;font-size:11px;letter-spacing:1.3px}.rmp .rmp-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}.rmp .rmp-links{display:flex;gap:8px;flex-wrap:wrap}.rmp .rmp-card{background:linear-gradient(180deg,rgba(13,27,49,.98),rgba(8,19,36,.98));border:1px solid var(--line);border-radius:18px;padding:20px;margin-bottom:15px;box-shadow:0 16px 38px rgba(0,0,0,.12)}.rmp .rmp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.rmp label{display:block;font-weight:700;font-size:12px;color:#cbdaf0;margin:12px 0 6px}.rmp input:not([type=checkbox]),.rmp textarea{width:100%;min-width:0;background:#071326;color:#fff;border:1px solid rgba(118,160,220,.25);border-radius:11px;padding:12px;font:inherit}.rmp textarea{resize:vertical}.rmp input[type=file]{color:#bcd3ee}.rmp input[type=checkbox]{accent-color:#2f6df6;width:17px;height:17px;vertical-align:middle;margin-right:8px}.rmp input:focus,.rmp textarea:focus{outline:2px solid #2f6df6;outline-offset:1px}.rmp .rmp-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;text-decoration:none;cursor:pointer;background:linear-gradient(135deg,#1857de,#2f6df6);color:#fff;border:1px solid rgba(145,184,255,.2);border-radius:11px;padding:11px 15px;font-size:12px;font-weight:800}.rmp .rmp-btn.ghost{background:#122440}.rmp .rmp-field{margin-bottom:13px}.rmp .rmp-field p{margin:0}.rmp .rmp-chip{display:inline-block;background:rgba(25,211,125,.11);color:#79f1b8;padding:5px 9px;border-radius:99px;font-size:12px;font-weight:700}.rmp .rmp-chip.pending{background:rgba(255,216,74,.1);color:#f6d77a}.rmp .rmp-alert{padding:12px 15px;border-radius:11px;margin-bottom:15px}.rmp .rmp-alert.error{background:#57202b;color:#ffe4e6}.rmp .rmp-alert.success{background:#0b4837;color:#c8ffe7}.rmp .rmp-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}.rmp .rmp-media{background:#071326;border:1px solid var(--line);border-radius:13px;padding:12px;min-width:0}.rmp .rmp-media img,.rmp .rmp-media video{width:100%;max-height:270px;object-fit:contain;border-radius:9px;background:#030a14}.rmp .rmp-media a{color:#91c7ff}.rmp .rmp-media p{overflow-wrap:anywhere}.rmp .rmp-check{display:flex;align-items:center;gap:7px;margin:12px 0;color:#cbdaf0}.rmp .rmp-check label{margin:0;display:inline;font-weight:600}.rmp .rmp-detail{margin:10px 0;color:#c9daf2}.rmp .rmp-detail strong{color:#fff}@media(max-width:760px){.rmp{padding:12px}.rmp .rmp-grid{grid-template-columns:1fr}.rmp h1{font-size:22px}.rmp .rmp-card{padding:15px}}
</style>
<div class="rmp" id="royal-media-paca">
 <?php if($error):?><div class="rmp-alert error"><?=rm_h($error)?></div><?php endif;?>
 <?php if($ok):?><div class="rmp-alert success"><?=rm_h($ok)?></div><?php endif;?>
 <div class="rmp-head">
  <div><div class="rmp-kicker">● ROYAL CRM · FICHA MULTIMEDIA INTELIGENTE</div><h1>Paca <?=rm_h($p['codigo_paca'])?></h1><p class="rmp-muted" style="margin:0">Expediente real de mercadería para Royal AI Sales.</p></div>
  <div class="rmp-links"><a class="rmp-btn ghost" href="royal_media_inventario.php">Volver a Royal Media Inventory</a><a class="rmp-btn ghost" href="royal_media_guia.php">Guía</a></div>
 </div>
 <section class="rmp-card"><div class="rmp-grid">
  <div>
   <h2>Identificación de la paca</h2>
   <p class="rmp-detail"><strong>Producto:</strong> <?=rm_h($p['producto_nombre'])?></p>
   <p class="rmp-detail"><strong>Procedencia:</strong> <?=rm_h($p['codigo_lote'] ?: 'Origen histórico no identificado')?></p>
   <p class="rmp-detail"><strong>Estado:</strong> <span class="rmp-chip"><?=rm_h($p['estado'])?></span></p>
   <p class="rmp-detail"><strong>Ubicación:</strong> <?=rm_h($p['ubicacion_bodega'] ?: 'Sin especificar')?></p>
   <p class="rmp-detail"><strong>Preparación IA:</strong> <span class="rmp-chip <?=((int)$p['lista_ia']===1?'':'pending')?>"><?=((int)$p['lista_ia']===1?'Lista para IA':'Pendiente')?></span></p>
   <a class="rmp-btn" target="_blank" rel="noopener" href="royal_media_qr.php?id=<?=$id?>">Imprimir QR tamaño carta</a>
  </div>
  <div><h2>Ubicación en bodega</h2><form method="post">
   <input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="ubicacion">
   <label for="rmp-location">Ubicación</label><input id="rmp-location" name="ubicacion" maxlength="150" value="<?=rm_h($p['ubicacion_bodega'])?>">
   <p><button class="rmp-btn" type="submit">Guardar ubicación</button></p>
  </form></div>
 </div></section>
 <section class="rmp-card"><h2>Información comercial para Royal AI Sales</h2><p class="rmp-muted">La IA solo debe describir características confirmadas y consultar precios y stock oficiales al atender.</p>
 <form method="post"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="comercial">
  <div class="rmp-field"><label for="rmp-description">Descripción real de la mercadería</label><textarea id="rmp-description" name="descripcion" maxlength="10000" rows="4" required><?=rm_h($p['descripcion'])?></textarea></div>
  <div class="rmp-field"><label for="rmp-characteristics">Características observadas</label><textarea id="rmp-characteristics" name="caracteristicas_ia" maxlength="5000" rows="3" required><?=rm_h($p['caracteristicas_ia'])?></textarea></div>
  <div class="rmp-field"><label for="rmp-condition">Condición real</label><input id="rmp-condition" name="condicion_ia" maxlength="100" value="<?=rm_h($p['condicion_ia'])?>" placeholder="Ej. Usada, condición revisada" required></div>
  <div class="rmp-field"><label for="rmp-observations">Observaciones para ventas (opcional)</label><textarea id="rmp-observations" name="observaciones_ia" maxlength="5000" rows="3"><?=rm_h($p['observaciones_ia'])?></textarea></div>
  <div class="rmp-check"><input id="rmp-ai" type="checkbox" name="lista_ia" value="1" <?=((int)$p['lista_ia']===1?'checked':'')?>><label for="rmp-ai">Lista para ofrecer por IA (requiere fotografía y datos completos)</label></div>
  <button class="rmp-btn" type="submit">Guardar información comercial</button>
 </form></section>
  <section class="rmp-card"><h2>Fotografias de carga anterior (solo referencia)</h2>
   <p class="rmp-muted">Autoriza exclusivamente fotos verificadas de mercaderia anterior. No habilita ventas, no modifica el stock y no cambia el estado de la paca.</p>
   <?php if($rm_admin): ?>
   <form method="post"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="referencial_ia">
    <div class="rmp-check"><input type="checkbox" id="rmp-referencia" name="foto_referencial_ia" value="1" <?=((int)($p['foto_referencial_ia']??0)===1?'checked':'')?>><label for="rmp-referencia">Confirmo que estas fotos pertenecen a una carga anterior y autorizo su uso referencial por IA</label></div>
    <button class="rmp-btn" type="submit">Guardar autorizacion referencial</button>
   </form>
   <?php else: ?><p class="rmp-muted">Solo administracion puede autorizar estas fotografias.</p><?php endif; ?>
  </section>
 <section class="rmp-card"><h2>Agregar fotografías o videos</h2><p class="rmp-muted">Archivo JPG, PNG, WEBP, MP4 o WEBM (máximo 50 MB)</p>
 <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="subir"><label for="rmp-upload">Seleccionar archivo</label><input id="rmp-upload" type="file" name="archivo" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" required><p><button class="rmp-btn" type="submit">Subir archivo</button></p></form>
 </section>
 <section class="rmp-card"><h2>Galería privada</h2>
  <?php if($archivos):?><div class="rmp-gallery">
   <?php foreach($archivos as $a):?><div class="rmp-media"><p><strong><?=rm_h($a['tipo'])?></strong> · <?=rm_h($a['nombre_original'])?></p>
    <?php if($a['tipo']==='foto'):?><a href="royal_media_archivo.php?id=<?=(int)$a['id']?>" target="_blank" rel="noopener"><img loading="lazy" src="royal_media_archivo.php?id=<?=(int)$a['id']?>" alt="Fotografía de paca"></a>
    <?php else:?><video controls preload="none" src="royal_media_archivo.php?id=<?=(int)$a['id']?>"></video><?php endif;?>
    <p class="rmp-muted"><?=rm_h($a['created_at'])?></p>
   </div><?php endforeach;?>
  </div><?php else:?><p class="rmp-muted">Sin multimedia todavía.</p><?php endif;?>
 </section>
</div>
<?php include __DIR__ . '/footer.php'; ?>