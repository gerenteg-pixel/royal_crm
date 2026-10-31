<?php
declare(strict_types=1);
require_once __DIR__ . '/royal_media_core.php';

// Panel administrativo independiente. No modifica el catalogo oficial ni las pacas.
$campos = [
    'descripcion_comercial' => 'Descripcion comercial',
    'contenido_aproximado' => 'Contenido aproximado',
    'caracteristicas_comerciales' => 'Caracteristicas comerciales',
    'condicion_mercaderia' => 'Condicion de la mercaderia',
    'beneficios_venta' => 'Beneficios y argumentos de venta',
    'preguntas_frecuentes' => 'Preguntas frecuentes y respuestas',
    'restricciones_ia' => 'Restricciones y promesas prohibidas',
    'instrucciones_venta' => 'Instrucciones comerciales internas',
];
$aviso = '';
$error = '';
$productoSeleccionado = filter_input(INPUT_GET, 'producto_id', FILTER_VALIDATE_INT) ?: 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rm_check_csrf();
    $productoSeleccionado = filter_input(INPUT_POST, 'producto_id', FILTER_VALIDATE_INT) ?: 0;
    try {
        if ($productoSeleccionado < 1) throw new RuntimeException('Selecciona un producto valido.');
        $s = rm_stmt($conn, 'SELECT id FROM wa_catalogo_productos WHERE id=? AND empresa_id=? LIMIT 1', 'ii', [$productoSeleccionado, $rm_empresa]);
        $existe = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$existe) throw new RuntimeException('El producto no pertenece a la empresa actual.');
        $datos = [];
        foreach ($campos as $nombre => $etiqueta) {
            $valor = trim((string)($_POST[$nombre] ?? ''));
            if (mb_strlen($valor, 'UTF-8') > 15000) throw new RuntimeException('El campo ' . $etiqueta . ' supera el limite permitido.');
            $datos[$nombre] = $valor;
        }
        $listo = (string)($_POST['listo_ia'] ?? '0') === '1' ? 1 : 0;
        if ($listo) {
            foreach (['descripcion_comercial', 'contenido_aproximado', 'condicion_mercaderia', 'restricciones_ia'] as $obligatorio) {
                if ($datos[$obligatorio] === '') throw new RuntimeException('Para marcar listo para IA completa: ' . $campos[$obligatorio]);
            }
        }
        $columnas = array_keys($campos);
        $sql = 'INSERT INTO royal_media_catalogo_maestro (empresa_id, producto_id, ' . implode(', ', $columnas) . ', listo_ia) VALUES (' . implode(', ', array_fill(0, count($columnas) + 3, '?')) . ') ON DUPLICATE KEY UPDATE ';
        $actualizaciones = [];
        foreach ($columnas as $col) $actualizaciones[] = $col . '=VALUES(' . $col . ')';
        $actualizaciones[] = 'listo_ia=VALUES(listo_ia)';
        $sql .= implode(', ', $actualizaciones);
        $params = array_merge([$rm_empresa, $productoSeleccionado], array_values($datos), [$listo]);
        $stmt = rm_stmt($conn, $sql, 'ii' . str_repeat('s', count($columnas)) . 'i', $params);
        $stmt->close();
        $aviso = 'Ficha maestra guardada correctamente. No se modificaron precios ni existencias.';
    } catch (Throwable $e) {
        error_log('Royal Media catalogo maestro guardar: ' . $e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo guardar. Consulta el registro del servidor.';
    }
}

$productos = [];
$actual = null;
$fuenteCodigo = null;
$esRegaliaCodigo = false;
try {
    $s = rm_stmt($conn, 'SELECT c.id, c.nombre, c.activo, c.stock_actual, m.id AS maestro_id, m.listo_ia FROM wa_catalogo_productos c LEFT JOIN royal_media_catalogo_maestro m ON m.producto_id=c.id AND m.empresa_id=c.empresa_id WHERE c.empresa_id=? ORDER BY c.nombre ASC, c.id ASC', 'i', [$rm_empresa]);
    $productos = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    if ($productoSeleccionado < 1 && $productos) $productoSeleccionado = (int)$productos[0]['id'];
    if ($productoSeleccionado > 0) {
        $s = rm_stmt($conn, 'SELECT c.id, c.nombre, c.precio, c.stock_actual, c.activo, m.* FROM wa_catalogo_productos c LEFT JOIN royal_media_catalogo_maestro m ON m.producto_id=c.id AND m.empresa_id=c.empresa_id WHERE c.id=? AND c.empresa_id=? LIMIT 1', 'ii', [$productoSeleccionado, $rm_empresa]);
        $actual = $s->get_result()->fetch_assoc() ?: null;
        if ($actual) {
    $actual['id'] = $productoSeleccionado;
}
        $s->close();
    }
    // Solo prepara una propuesta visible para REGALIA CODIGO; no copia ni guarda nada.
   if ($actual && (int)$actual['id'] === 16 && (int)$rm_empresa === 1) {
        $esRegaliaCodigo = true;
        $s = rm_stmt($conn, "SELECT m.descripcion_comercial, m.contenido_aproximado, m.caracteristicas_comerciales, m.condicion_mercaderia, m.beneficios_venta, m.preguntas_frecuentes FROM wa_catalogo_productos c JOIN royal_media_catalogo_maestro m ON m.producto_id=c.id AND m.empresa_id=c.empresa_id WHERE c.empresa_id=? AND c.nombre IN ('Paca Codigo','Paca Código') ORDER BY c.id ASC LIMIT 1", 'i', [$rm_empresa]);
        $fuenteCodigo = $s->get_result()->fetch_assoc() ?: null;
        $s->close();
    }
} catch (Throwable $e) {
    error_log('Royal Media catalogo maestro leer: ' . $e->getMessage());
    $error = 'No se pudo cargar el catalogo. Verifica que la tabla nueva exista y revisa el registro del servidor.';
}
include __DIR__ . '/header.php';
?>
<style>
.rmcm{background:linear-gradient(135deg,#07101f,#10223e);color:#edf5ff;border-radius:18px;padding:22px;min-height:70vh}.rmcm *{box-sizing:border-box}.rmcm h1,.rmcm h2{color:#fff}.rmcm .muted{color:#afc3df}.rmcm .layout{display:grid;grid-template-columns:minmax(220px,300px) minmax(0,1fr);gap:16px}.rmcm .card{background:#0e1d34;border:1px solid #28415f;border-radius:14px;padding:16px}.rmcm .item{display:block;color:#edf5ff;text-decoration:none;padding:11px;border-bottom:1px solid #273a55}.rmcm .item:hover,.rmcm .selected{background:#19355c}.rmcm .status{font-size:12px;color:#a6bddd}.rmcm label{display:block;font-weight:700;margin:15px 0 7px}.rmcm textarea{display:block;width:100%;min-height:95px;background:#071326;border:1px solid #355477;border-radius:9px;padding:11px;color:#fff;font:inherit;resize:vertical}.rmcm button,.rmcm .btn{display:inline-block;background:#2863e9;color:#fff;border:0;border-radius:9px;padding:11px 16px;text-decoration:none;font-weight:700;cursor:pointer}.rmcm .notice{padding:12px;border-radius:9px;background:#143e31;color:#adf2d0;margin:12px 0}.rmcm .error{padding:12px;border-radius:9px;background:#572832;color:#ffdddd;margin:12px 0}.rmcm .hint{font-size:12px;color:#afc3df;margin-top:4px}.rmcm .toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:12px 0}.rmcm .toggle{display:flex;align-items:center;gap:9px;margin:18px 0}.rmcm .toggle input{width:18px;height:18px}.rmcm .toggle label{margin:0}.rmcm .regalia-info{background:#112b3b;border:1px solid #31738b;border-radius:12px;padding:14px;margin:14px 0}.rmcm .regalia-info p{margin:8px 0;color:#c5e7f1}.rmcm .regalia-info button{background:#157e80}@media(max-width:780px){.rmcm .layout{grid-template-columns:1fr}}
</style>
<main class="rmcm">
  <h1>Catálogo Maestro · Royal AI Sales</h1>
  <p class="muted">Preparación comercial por categoría. Los precios y existencias permanecen en el catálogo oficial del CRM. Este panel no activa ventas automáticas.</p>
  <div class="toolbar"><a class="btn" href="royal_media_inventario.php">Volver a Royal Media Inventory</a><span class="muted"><?=count($productos)?> productos de la empresa actual</span></div>
  <?php if ($aviso !== ''): ?><div class="notice"><?=rm_h($aviso)?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="error"><?=rm_h($error)?></div><?php endif; ?>
  <div class="layout">
    <aside class="card"><h2>Productos oficiales</h2>
      <?php foreach ($productos as $p): ?>
        <a class="item <?=((int)$p['id'] === $productoSeleccionado ? 'selected' : '')?>" href="?producto_id=<?=(int)$p['id']?>">
          <strong><?=rm_h($p['nombre'])?></strong><br>
          <span class="status">Stock: <?=(int)$p['stock_actual']?> · <?=((int)($p['listo_ia'] ?? 0) === 1 ? 'Ficha preparada' : 'Pendiente')?> · <?=((int)$p['activo'] === 1 ? 'Activo' : 'Inactivo')?></span>
        </a>
      <?php endforeach; ?>
    </aside>
    <section class="card">
      <?php if ($actual): ?>
        <h2><?=rm_h($actual['nombre'])?></h2>
        <p class="muted">Precio oficial: L <?=rm_h(number_format((float)$actual['precio'], 2, '.', ','))?> · Stock: <?=(int)$actual['stock_actual']?> · Producto <?=((int)$actual['activo'] === 1 ? 'activo' : 'inactivo')?>.</p>
        <?php if ($esRegaliaCodigo): ?>
          <div class="regalia-info">
            <strong>Regalía Código · propuesta de ficha comercial</strong>
            <p>Es mercadería de la categoría Paca Código, destinada a regalía únicamente cuando administración lo autorice. El valor nominal no es un precio de venta. La asignación puede variar según la descarga y la disponibilidad.</p>
            <?php if ($fuenteCodigo): ?>
              <button type="button" id="rmcm-copiar-codigo">Preparar campos desde Paca Código (sin guardar)</button>
              <p class="hint">Se copiarán los seis campos descriptivos y se propondrán restricciones e instrucciones específicas de regalía. Revisa todo antes de guardar. Los campos existentes no se alteran hasta que confirmes.</p>
            <?php else: ?>
              <p class="hint">No se encontró una ficha maestra de Paca Código para copiar. Puedes completar los ocho campos manualmente.</p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <form method="post" action="?producto_id=<?=(int)$productoSeleccionado?>">
          <input type="hidden" name="csrf" value="<?=rm_csrf()?>">
          <input type="hidden" name="producto_id" value="<?=(int)$productoSeleccionado?>">
          <?php foreach ($campos as $nombre => $etiqueta): ?>
            <label for="<?=rm_h($nombre)?>"><?=rm_h($etiqueta)?></label>
            <textarea id="<?=rm_h($nombre)?>" name="<?=rm_h($nombre)?>" maxlength="15000"><?=rm_h($actual[$nombre] ?? '')?></textarea>
          <?php endforeach; ?>
          <div class="toggle"><input id="listo_ia" type="checkbox" name="listo_ia" value="1" <?=((int)($actual['listo_ia'] ?? 0) === 1 ? 'checked' : '')?>><label for="listo_ia">Información comercial preparada para IA</label></div>
          <p class="hint">Marcar esta casilla no publica automáticamente el producto ni modifica el filtro de disponibilidad del módulo actual. Las pacas individuales siguen requiriendo sus propias validaciones.</p>
          <button type="submit">Guardar ficha maestra</button>
        </form>
      <?php else: ?><p>No hay un producto válido seleccionado para esta empresa.</p><?php endif; ?>
    </section>
  </div>
</main>
<?php if ($esRegaliaCodigo && $fuenteCodigo): ?>
<script>
(function(){
  'use strict';
  const fuente = <?=json_encode($fuenteCodigo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
  const restricciones = 'REGALIA CODIGO: Solo puede ofrecerse o entregarse como regalía si administración lo autoriza expresamente y confirma disponibilidad para esa operación. El precio nominal del catálogo no es precio de venta al público. No prometer gratuidad, disponibilidad, cantidades, calidad, marcas ni contenido específico. Si se decide vender al público, consultar el producto y precio comercial vigente y solicitar confirmación; no utilizar el valor nominal de la regalía.';
  const instrucciones = 'Mercadería equivalente a Paca Código en su descripción física; su condición de regalía depende de la descarga y de la autorización administrativa. No transferir stock ni cambiar precio desde esta ficha. No ofrecer automáticamente por comprar una paca. Confirmar con administración la regalía, disponibilidad y condiciones antes de comunicarla al cliente.';
  const btn = document.getElementById('rmcm-copiar-codigo');
  if (!btn) return;
  btn.addEventListener('click', function(){
    if (!confirm('¿Preparar los ocho campos de REGALÍA CÓDIGO? Esto reemplazará el texto que esté visible en el formulario, pero NO guardará cambios hasta que pulses Guardar ficha maestra.')) return;
    ['descripcion_comercial','contenido_aproximado','caracteristicas_comerciales','condicion_mercaderia','beneficios_venta','preguntas_frecuentes'].forEach(function(k){
      const el = document.getElementById(k);
      if (el) el.value = String(fuente[k] || '');
    });
    document.getElementById('restricciones_ia').value = restricciones;
    document.getElementById('instrucciones_venta').value = instrucciones;
    document.getElementById('listo_ia').checked = false;
    alert('Propuesta cargada en el formulario. Revisa los ocho campos y marca Lista para IA solamente cuando corresponda. Todavía no se ha guardado nada.');
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>