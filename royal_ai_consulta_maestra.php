<?php
declare(strict_types=1);
require_once __DIR__ . '/royal_media_core.php';

// Consulta administrativa de solo lectura. No sustituye al buscador de pacas.
$campos = [
 'descripcion_comercial'=>'Descripcion comercial',
 'contenido_aproximado'=>'Contenido aproximado',
 'caracteristicas_comerciales'=>'Caracteristicas comerciales',
 'condicion_mercaderia'=>'Condicion de la mercaderia',
 'beneficios_venta'=>'Beneficios y argumentos de venta',
 'preguntas_frecuentes'=>'Preguntas frecuentes',
 'restricciones_ia'=>'Restricciones IA',
 'instrucciones_venta'=>'Instrucciones de venta',
];
$buscar = mb_substr(trim((string)($_GET['buscar'] ?? '')), 0, 100, 'UTF-8');
$modoJson = (string)($_GET['formato'] ?? '') === 'json';
$productos = [];
$error = '';
try {
 $sql = 'SELECT c.id, c.nombre, c.precio, c.stock_actual, c.activo, m.listo_ia, '
      . implode(', ', array_map(static fn($x) => 'm.'.$x, array_keys($campos)))
      . ' FROM wa_catalogo_productos c LEFT JOIN royal_media_catalogo_maestro m '
      . 'ON m.empresa_id=c.empresa_id AND m.producto_id=c.id WHERE c.empresa_id=?';
 $params = [$rm_empresa]; $types = 'i';
 if ($buscar !== '') {
  $patron = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $buscar).'%';
  $sql .= " AND (c.nombre LIKE ? ESCAPE '!' OR m.descripcion_comercial LIKE ? ESCAPE '!')";
  $params[]=$patron; $params[]=$patron; $types.='ss';
 }
 $sql .= ' ORDER BY c.nombre ASC, c.id ASC LIMIT 100';
 $s=rm_stmt($conn,$sql,$types,$params);
 $filas=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
 foreach ($filas as $f) {
  $info=[];
  foreach ($campos as $clave=>$etiqueta) $info[$clave]=(string)($f[$clave] ?? '');
  $precio=(string)$f['precio'];
  $precioValido=is_numeric($precio) && (float)$precio>0;
  $listo=(int)($f['listo_ia'] ?? 0)===1;
  $activo=(int)$f['activo']===1;
  $stock=(int)$f['stock_actual'];
  $productos[]=[
   'producto_id'=>(int)$f['id'], 'nombre'=>(string)$f['nombre'],
   'activo'=>$activo, 'stock_oficial'=>$stock,
   'ficha_preparada'=>$listo,
   'precio_oficial'=>$precioValido ? number_format((float)$precio,2,'.','') : null,
   'moneda'=>'HNL',
   'estado_consulta'=>!$listo ? 'ficha_pendiente' : (!$activo ? 'producto_inactivo' : ($stock<1 ? 'sin_stock' : (!$precioValido ? 'precio_por_confirmar' : 'requiere_validacion_comercial'))),
   'informacion_comercial'=>$info,
  ];
 }
} catch(Throwable $e) {
 error_log('Royal AI consulta maestra: '.$e->getMessage());
 $error='No se pudo consultar el catalogo maestro. Revisa el registro del servidor.';
}
$aviso='Solo lectura y uso administrativo. Una ficha preparada no implica disponibilidad. No autoriza venta, promociones ni cortesias. Revalidar stock, precio, reglas de combos y autorizaciones antes de ofrecer.';
if ($modoJson) {
 header('Content-Type: application/json; charset=utf-8');
 header('Cache-Control: no-store, private');
 header('X-Content-Type-Options: nosniff');
 http_response_code($error!=='' ? 500 : 200);
 echo json_encode(['ok'=>$error==='','empresa_id'=>$rm_empresa,'consulta'=>$buscar,'cantidad'=>count($productos),'productos'=>$productos,'aviso'=>$aviso,'error'=>$error ?: null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
 exit;
}
include __DIR__.'/header.php';
?>
<style>
.racm{background:linear-gradient(135deg,#07101f,#10223e);color:#edf5ff;border-radius:18px;padding:22px;min-height:70vh}.racm *{box-sizing:border-box}.racm h1,.racm h2{color:#fff}.racm .muted{color:#aec4e2}.racm .card{background:#0e1d34;border:1px solid #28415f;border-radius:14px;padding:16px;margin:12px 0}.racm input{background:#071326;color:white;border:1px solid #355477;border-radius:9px;padding:11px;min-width:240px}.racm .btn,.racm button{display:inline-block;background:#2863e9;color:white;border:0;border-radius:9px;padding:11px 16px;text-decoration:none;font-weight:700;cursor:pointer}.racm .actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.racm .warn{color:#ffe0a0}.racm pre{white-space:pre-wrap;overflow-wrap:anywhere;font:inherit;font-size:13px;color:#e6f1ff}.racm details{margin-top:10px}.racm summary{cursor:pointer;font-weight:bold;color:#9cc6ff}.racm .error{color:#ffd5d5}
</style>
<main class="racm">
 <h1>Royal AI Sales - Consulta del Catalogo Maestro</h1>
 <p class="muted">Vista administrativa de las fichas comerciales oficiales. No envia mensajes ni modifica inventario.</p>
 <div class="actions"><a class="btn" href="royal_media_inventario.php">Volver a Royal Media Inventory</a><a class="btn" href="royal_media_catalogo_maestro.php">Editar Catalogo Maestro</a></div>
 <form method="get" class="card actions"><input type="search" name="buscar" maxlength="100" value="<?=rm_h($buscar)?>" placeholder="Buscar categoria o descripcion"><button type="submit">Consultar</button><a class="btn" href="royal_ai_consulta_maestra.php">Ver todas</a></form>
 <p class="warn"><?=rm_h($aviso)?></p>
 <?php if($error!==''):?><p class="error"><?=rm_h($error)?></p><?php endif;?>
 <p class="muted"><?=count($productos)?> categoria(s) encontrada(s).</p>
 <?php foreach($productos as $p): ?>
 <section class="card"><h2><?=rm_h($p['nombre'])?></h2>
 <p class="muted">ID <?= (int)$p['producto_id']?> · <?= $p['activo']?'Activo':'Inactivo' ?> · Stock oficial: <?= (int)$p['stock_oficial']?> · <?= $p['ficha_preparada']?'Ficha preparada':'Ficha pendiente' ?></p>
 <p><strong>Precio oficial:</strong> <?= $p['precio_oficial']!==null ? 'L '.rm_h($p['precio_oficial']) : 'Por confirmar (no interpretar como gratis)' ?></p>
 <p class="warn">Estado: <?=rm_h($p['estado_consulta'])?>. Los combos y cortesias requieren validar sus reglas comerciales por separado.</p>
 <details><summary>Ver los 8 campos comerciales</summary>
 <?php foreach($campos as $clave=>$etiqueta): ?><div><strong><?=rm_h($etiqueta)?></strong><pre><?=rm_h($p['informacion_comercial'][$clave])?></pre></div><?php endforeach;?>
 </details></section>
 <?php endforeach;?>
 <p><a class="btn" href="?buscar=<?=rawurlencode($buscar)?>&amp;formato=json" target="_blank" rel="noopener">Ver JSON administrativo</a></p>
</main>
<?php include __DIR__.'/footer.php'; ?>