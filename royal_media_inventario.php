<?php
require_once __DIR__.'/royal_media_core.php';
$error='';$ok='';
try {
 if ($_SERVER['REQUEST_METHOD']==='POST') {
  rm_check_csrf();
  $action=(string)($_POST['accion']??'');
  if ($action==='registrar') {
   $productoId=(int)($_POST['producto_id']??0); $cant=(int)($_POST['cantidad']??0);
   $ubicacion=mb_substr(trim((string)($_POST['ubicacion']??'')),0,150);
   if ($productoId<=0 || $cant<1 || $cant>100) throw new RuntimeException('Selecciona un producto y entre 1 y 100 pacas.');
   $conn->begin_transaction();
   try {
    // Serializa registros del mismo producto sin alterar su stock.
    $s=rm_stmt($conn,'SELECT id,stock_actual FROM wa_catalogo_productos WHERE id=? AND empresa_id=? FOR UPDATE','ii',[$productoId,$rm_empresa]);
    $producto=$s->get_result()->fetch_assoc();$s->close();
    if (!$producto) throw new RuntimeException('Producto no encontrado en catálogo oficial.');
    $s=rm_stmt($conn,"SELECT COUNT(*) AS total FROM royal_media_pacas WHERE empresa_id=? AND producto_id=? AND estado IN ('disponible','reservada')",'ii',[$rm_empresa,$productoId]);
    $identificadas=(int)$s->get_result()->fetch_assoc()['total'];$s->close();
    for($i=0;$i<$cant;$i++) {
     $token=bin2hex(random_bytes(32));
     $codigo='RP-'.$rm_empresa.'-'.strtoupper(substr(bin2hex(random_bytes(7)),0,12));
     $s=rm_stmt($conn,"INSERT INTO royal_media_pacas (empresa_id,lote_id,lote_item_id,producto_id,codigo_paca,qr_token,ubicacion_bodega,estado,creada_por,lista_ia) VALUES (?,NULL,NULL,?,?,?,?,'disponible',?,0)",'iissss',[$rm_empresa,$productoId,$codigo,$token,$ubicacion,(string)($_SESSION['usuario']??'crm')]);
     $id=(int)$conn->insert_id;$s->close();
     $s=rm_stmt($conn,"INSERT INTO royal_media_eventos (empresa_id,paca_id,evento,descripcion,realizado_por) VALUES (?,?,'registro','Identificación multimedia; origen histórico no identificado; sin cambio de stock',?)",'iis',[$rm_empresa,$id,(string)($_SESSION['usuario']??'crm')]);$s->close();
    }
    $conn->commit();$ok="Se identificaron $cant pacas. El stock oficial no fue modificado.";
   }catch(Throwable $e){$conn->rollback();throw $e;}
  }
  if ($action==='ubicacion') {
   $id=(int)($_POST['paca_id']??0);$ubicacion=mb_substr(trim((string)($_POST['ubicacion']??'')),0,150);
   $s=rm_stmt($conn,'UPDATE royal_media_pacas SET ubicacion_bodega=? WHERE id=? AND empresa_id=?','sii',[$ubicacion,$id,$rm_empresa]);$s->close();$ok='Ubicación actualizada.';
  }
 }
} catch(Throwable $e) { $error=$e->getMessage(); }
 // El catálogo oficial es la única fuente de existencias. Los lotes son informativos.
 $productos=rm_stmt($conn,"SELECT c.id,c.nombre,c.stock_actual,COALESCE(q.activas,0) AS identificadas FROM wa_catalogo_productos c LEFT JOIN (SELECT empresa_id,producto_id,COUNT(*) AS activas FROM royal_media_pacas WHERE estado IN ('disponible','reservada') GROUP BY empresa_id,producto_id) q ON q.empresa_id=c.empresa_id AND q.producto_id=c.id WHERE c.empresa_id=? AND c.stock_actual>0 ORDER BY c.nombre",'i',[$rm_empresa])->get_result()->fetch_all(MYSQLI_ASSOC);
 $pacas=rm_stmt($conn,"SELECT p.id,p.codigo_paca,p.estado,p.ubicacion_bodega,p.created_at,p.lista_ia,p.foto_referencial_ia,COALESCE(l.codigo_lote,'Origen no identificado') AS codigo_lote,c.nombre AS producto_nombre,(SELECT COUNT(*) FROM royal_media_archivos a WHERE a.paca_id=p.id AND a.empresa_id=p.empresa_id) AS multimedia FROM royal_media_pacas p LEFT JOIN inventario_lotes l ON l.id=p.lote_id AND l.empresa_id=p.empresa_id JOIN wa_catalogo_productos c ON c.id=p.producto_id AND c.empresa_id=p.empresa_id WHERE p.empresa_id=? ORDER BY p.id DESC LIMIT 300",'i',[$rm_empresa])->get_result()->fetch_all(MYSQLI_ASSOC);
 $productosVisibles=[];$stockTotal=0;$qrTotal=0;$productosConStock=0;
 foreach($productos as $pr){$pid=(int)$pr['id'];$productosVisibles[$pid]=['nombre'=>$pr['nombre'],'stock'=>(int)$pr['stock_actual'],'qr'=>(int)$pr['identificadas']];$stockTotal+=(int)$pr['stock_actual'];$qrTotal+=(int)$pr['identificadas'];$productosConStock++;}
$multimediaTotal=0;
foreach ($pacas as $p) if ((int)$p['multimedia']>0) $multimediaTotal++;
// Cargar el layout oficial en el alcance principal, igual que wa_equipos_admin.php.
include __DIR__ . '/header.php';
// Los mensajes se muestran dentro del contenedor oscuro del módulo.
?>
<style>
/* Tema aislado de Royal Media: no modifica estilos globales del CRM. */
.rmi{width:100%;max-width:none;box-sizing:border-box;--bg:#07101f;--panel:#0d1b31;--line:rgba(100,150,255,.20);--text:#f8fbff;--muted:#9bb0ce;--blue:#2f6df6;--cyan:#27d7ff;--green:#19d37d;color:var(--text);font-family:inherit;background:radial-gradient(circle at 80% 0,rgba(47,109,246,.15),transparent 32%),linear-gradient(140deg,#07101f,#0a172b);padding:22px;border-radius:22px;min-height:75vh}
.rmi *{box-sizing:border-box} .rmi .err{background:#57202b;color:#ffe4e6;padding:12px;border-radius:10px}.rmi .ok{background:#0b4837;color:#c8ffe7;padding:12px;border-radius:10px}.rmi .rmi-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}.rmi .rmi-kicker{font-size:11px;font-weight:800;letter-spacing:1.5px;color:#7de9ff}.rmi h1{font-size:28px;margin:5px 0;color:#fff}.rmi h2{font-size:19px;margin:0 0 7px;color:#fff}.rmi p{line-height:1.55}.rmi .rmi-muted{color:var(--muted);font-size:13px;margin:0}.rmi .rmi-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.rmi .rmi-metric{background:rgba(4,12,27,.76);border:1px solid var(--line);border-radius:16px;padding:17px}.rmi .rmi-metric span{font-size:11px;color:var(--muted);display:block}.rmi .rmi-metric strong{font-size:30px;color:#fff;display:block;margin-top:7px}.rmi .rmi-panel{background:linear-gradient(180deg,rgba(13,27,49,.98),rgba(8,19,36,.98));border:1px solid var(--line);border-radius:19px;padding:20px;margin-bottom:15px;box-shadow:0 16px 38px rgba(0,0,0,.16)}.rmi .rmi-panelhead{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px}.rmi .rmi-grid{display:grid;grid-template-columns:1.2fr 1.2fr .7fr;gap:12px}.rmi label{display:block;color:#cbdaf0;font-weight:700;font-size:12px;margin-bottom:6px}.rmi input,.rmi select{display:block;width:100%;padding:12px;border-radius:11px;border:1px solid rgba(118,160,220,.25);background:#071326;color:#fff;min-width:0}.rmi select option{background:#0b1930;color:#fff}.rmi input:focus,.rmi select:focus{outline:2px solid #2f6df6;outline-offset:1px}.rmi .rmi-btn,.rmi button.rmi-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;cursor:pointer;background:linear-gradient(135deg,#1857de,#2f6df6);color:#fff;border:1px solid rgba(145,184,255,.2);border-radius:11px;padding:11px 15px;font-size:12px;font-weight:800}.rmi .rmi-btn.ghost{background:#122440}.rmi .rmi-btn:disabled{opacity:.45;cursor:not-allowed}.rmi .rmi-actions{display:flex;gap:7px;flex-wrap:wrap}.rmi .rmi-note{border:1px solid rgba(255,216,74,.18);background:rgba(255,216,74,.065);border-radius:12px;padding:12px;color:#d9c9a0;font-size:12px;margin:14px 0}.rmi .rmi-tablewrap{overflow:auto;max-width:100%}.rmi table{width:100%;border-collapse:collapse;min-width:650px;background:transparent;color:#e5efff}.rmi th{background:#091528;color:#8ca8ce;font-size:11px;text-align:left;padding:12px}.rmi td{padding:12px;border-bottom:1px solid rgba(140,173,220,.09);font-size:12px}.rmi td small{display:block;color:#7e9abe;margin-top:4px}.rmi .rmi-chip{display:inline-block;padding:5px 9px;border-radius:99px;background:rgba(25,211,125,.11);color:#79f1b8;font-size:11px;font-weight:800}.rmi .rmi-chip.warn{background:rgba(255,216,74,.1);color:#f6d77a}.rmi .rmi-chip.off{background:rgba(156,174,203,.1);color:#aebed2}.rmi details summary{cursor:pointer;color:#b9d9ff;font-weight:800;font-size:13px;padding:5px 0}.rmi .rmi-empty{padding:20px;text-align:center;color:#9bb0ce}.rmi .rmi-products{display:flex;gap:9px;flex-wrap:wrap}.rmi .rmi-product{padding:12px 15px;border-radius:13px;background:#09172d;border:1px solid var(--line);min-width:150px}.rmi .rmi-product strong{display:block;font-size:22px;color:#fff}.rmi .rmi-product small{color:#a8bfdc}.rmi .rmi-help{font-size:12px;color:#a3bbdb;margin-top:9px}.rmi .rmi-toplinks{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:1000px){.rmi .rmi-metrics{grid-template-columns:repeat(2,1fr)}.rmi .rmi-grid{grid-template-columns:1fr 1fr}}@media(max-width:650px){.rmi{padding:12px}.rmi .rmi-grid,.rmi .rmi-metrics{grid-template-columns:1fr}.rmi h1{font-size:23px}}
</style>
<div class="rmi" id="royal-media-inventory">
 <?php if ($error): ?><p class="err"><?=rm_h($error)?></p><?php endif; ?>
 <?php if ($ok): ?><p class="ok"><?=rm_h($ok)?></p><?php endif; ?>
 <div class="rmi-top">
  <div><div class="rmi-kicker">● ROYAL CRM · INVENTARIO INTELIGENTE</div><h1>Royal Media Inventory</h1><p class="rmi-muted">Identificación de pacas, fotografías, videos y códigos QR vinculados al inventario oficial.</p></div>
  <div class="rmi-toplinks">

    <a class="rmi-btn"
       href="royal_media_ai_catalogo.php">
        🤖 Royal AI Sales
    </a>
    <a class="rmi-btn"
   href="royal_media_carga_masiva.php">
    📸 Carga masiva de fotografías
</a>
<!-- ROYAL AI - CENTRO DE OPERACIONES DEFINITIVO -->
<a class="rmi-btn"
   href="/royal_ai_command_center_3d.php">
    🧠 Centro de Operaciones IA
</a>

<!-- ROYAL AI - MONITOR DE CONVERSACIONES -->
<a class="rmi-btn ghost"
   href="https://royallprestigue.com/whatsapp/royal_ai_sales_preview.php">
    💬 Monitor de Conversaciones IA
</a>
<!-- ROYAL AI - LABORATORIO DE CONVERSACIONES -->
<a class="rmi-btn ghost"
   href="/whatsapp/royal_ai_sales_motor_simulacion.php">
    🧠 Laboratorio de Conversaciones IA
</a>
<!-- ROYAL AI SALES - SIMULADOR CON OPENAI -->
<a class="rmi-btn ghost"
   href="/royal_ai_simulador.php">
    🤖 Royal AI Sales · Simulador
</a>

<!-- ROYAL AI SALES - PRUEBA CARLOS IA CON WHATSAPP -->
<a class="rmi-btn"
   href="/whatsapp/royal_ai_sales_puente_prueba.php">
    💬 Prueba Carlos IA · WhatsApp
</a>
    <a class="rmi-btn ghost"
       href="royal_media_catalogo_maestro.php">
        📚 Catálogo Maestro IA
    </a>
    
    <a class="rmi-btn"
   href="royal_ai_consulta_maestra.php">
    🧠 Consulta Comercial IA
</a>

    <a class="rmi-btn"
       href="royal_ai_motor_comercial.php">
        🤖 Motor Comercial IA · Diagnóstico
    </a>

    <a class="rmi-btn ghost"
       href="royal_ai_reglas_comerciales.php">
        📋 Diagnóstico de Reglas Comerciales
    </a>

    <a class="rmi-btn ghost"
       href="royal_media_guia.php">
        Guía
    </a>

    <a class="rmi-btn ghost"
       href="javascript:history.back()">
        Volver a Equipos WhatsApp
    </a>

</div>
 </div>
 <div class="rmi-metrics">
  <div class="rmi-metric"><span>Unidades en stock oficial</span><strong><?= (int)$stockTotal ?></strong></div>
  <div class="rmi-metric"><span>Productos con existencia</span><strong><?= (int)$productosConStock ?></strong></div>
  <div class="rmi-metric"><span>Pacas con QR activo</span><strong><?= (int)$qrTotal ?></strong></div>
  <div class="rmi-metric"><span>Pacas con multimedia (últimas 300)</span><strong><?= (int)$multimediaTotal ?></strong></div>
 </div>
 <p class="rmi-muted" style="margin-bottom:16px">*Resumen calculado sobre los productos incluidos en los primeros 500 registros de lotes consultados; no es un inventario general exhaustivo.</p>
 <section class="rmi-panel">
  <div class="rmi-panelhead"><div><h2>Mercadería disponible</h2><p class="rmi-muted">Productos con existencia positiva en el catálogo oficial.</p></div><span class="rmi-chip">Stock oficial</span></div>
  <div class="rmi-products">
   <?php $hayStock=false;foreach($productosVisibles as $pr):if($pr['stock']<=0)continue;$hayStock=true; ?>
   <div class="rmi-product"><small><?=rm_h($pr['nombre'])?></small><strong><?=(int)$pr['stock']?></strong><small><?=(int)$pr['qr']?> con QR activo</small></div>
   <?php endforeach;if(!$hayStock):?><div class="rmi-empty">No hay productos con existencia positiva en el catálogo oficial.</div><?php endif;?>
  </div>
 </section>
  <section class="rmi-panel">
   <div class="rmi-panelhead"><div><h2>Identificar nuevas pacas</h2><p class="rmi-muted">Cada paca recibe un QR único. No necesitas conocer su lote histórico.</p></div><span class="rmi-chip">Sin modificar stock</span></div>
   <form method="post" id="rmi-form">
    <input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="accion" value="registrar">
    <div class="rmi-grid">
<div>
  <label for="rmi-producto">1. Producto oficial</label>
  <select id="rmi-producto" name="producto_id" required>
    <option value="">Selecciona un producto</option>
    <?php foreach($productosVisibles as $pid=>$pr): ?>
      <option value="<?=(int)$pid?>">
        <?=rm_h($pr['nombre'])?>
      </option>
    <?php endforeach; ?>
  </select>
</div>
     <div><label>2. Procedencia</label><input value="Origen histórico no identificado" readonly></div>
     <div><label for="rmi-cantidad">3. Cantidad física</label><input id="rmi-cantidad" name="cantidad" type="number" min="1" max="100" value="1" required></div>
    </div>
    <div style="margin-top:12px;max-width:450px"><label for="rmi-ubicacion">Ubicación en bodega (opcional)</label><input id="rmi-ubicacion" name="ubicacion" maxlength="150" placeholder="Ej. Pasillo A, estante 2"></div>
    <div id="rmi-cupo" class="rmi-help">Selecciona el producto para comprobar el cupo.</div>
    <div class="rmi-note">Verifica físicamente cada unidad antes de identificarla. La identificación QR no reserva, vende ni modifica inventario; la disponibilidad debe volver a comprobarse al ofrecer.</div>
    <button class="rmi-btn" type="submit" id="rmi-submit">Registrar e identificar pacas</button>
   </form>
  </section>
 <section class="rmi-panel">
  <div class="rmi-panelhead"><div><h2>Pacas identificadas</h2><p class="rmi-muted">Últimos 300 registros. Accede a fotografías, videos y QR.</p></div></div>
  <div class="rmi-tablewrap"><table><thead><tr><th>Paca</th><th>Producto y lote</th><th>Estado</th><th>IA</th><th>Ubicación</th><th>Multimedia</th><th>Referencia IA</th><th>Acciones</th></tr></thead><tbody>
  <?php foreach($pacas as $p):?><tr><td><strong><?=rm_h($p['codigo_paca'])?></strong><small><?=rm_h($p['created_at'])?></small></td><td><?=rm_h($p['producto_nombre'])?><small><?=rm_h($p['codigo_lote'])?></small></td><td><span class="rmi-chip <?= $p['estado']==='disponible'?'':'warn' ?>"><?=rm_h($p['estado'])?></span></td><td><span class="rmi-chip <?= (int)$p['lista_ia']===1?'':'warn' ?>"><?= (int)$p['lista_ia']===1?'Lista':'Pendiente' ?></span></td><td><?=rm_h($p['ubicacion_bodega'])?></td><td><?=(int)$p['multimedia']?> archivo(s)</td><td><span class="rmi-chip <?= (int)($p['foto_referencial_ia']??0)===1?'':'off' ?>"><?= (int)($p['foto_referencial_ia']??0)===1?'Histórica autorizada':'No autorizada' ?></span></td><td><div class="rmi-actions"><a class="rmi-btn" href="royal_media_paca.php?id=<?=(int)$p['id']?>">Ver / fotos</a><a class="rmi-btn ghost" href="royal_media_qr.php?id=<?=(int)$p['id']?>" target="_blank" rel="noopener">Imprimir QR</a></div></td></tr><?php endforeach;?>
  <?php if(!$pacas):?><tr><td colspan="8" class="rmi-empty">Todavía no hay pacas identificadas. Puedes registrar la primera desde el formulario superior.</td></tr><?php endif;?></tbody></table></div>
 </section>
</div>
<script>
(function(){
  const producto = document.getElementById('rmi-producto');
  const cantidad = document.getElementById('rmi-cantidad');
  const info = document.getElementById('rmi-cupo');
  const boton = document.getElementById('rmi-submit');

  function actualizar(){
    cantidad.max = '100';
    boton.disabled = !producto.value;
    info.textContent = producto.value
      ? 'Registra la cantidad física verificada. No modifica el inventario oficial.'
      : 'Selecciona el producto que deseas identificar.';
  }

  producto.addEventListener('change', actualizar);

  document.getElementById('rmi-form').addEventListener('submit', function(e){
    const n = Number(cantidad.value);

    if (!producto.value || !Number.isInteger(n) || n < 1 || n > 100) {
      e.preventDefault();
      alert('Selecciona un producto e ingresa una cantidad entre 1 y 100 pacas.');
    }
  });

  actualizar();
})();
</script>
<?php include __DIR__ . '/footer.php'; ?>