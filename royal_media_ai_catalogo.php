<?php
declare(strict_types=1);
require_once __DIR__ . '/royal_media_core.php';

// Solo lectura. Reutiliza la autenticacion y el alcance de empresa del CRM.
$busqueda = trim((string)($_GET['buscar'] ?? ''));
$busqueda = mb_substr($busqueda, 0, 100);
$modoJson = (string)($_GET['formato'] ?? '') === 'json';
$error = '';
$resultados = [];

try {
    if ($busqueda !== '') {
        $patron = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $busqueda) . '%';
        $sql = "SELECT p.id, p.codigo_paca, p.producto_id, p.estado,
                       p.descripcion, p.caracteristicas_ia, p.condicion_ia,
                       p.observaciones_ia, p.ubicacion_bodega,
                       c.nombre AS producto_nombre, c.stock_actual,
                       c.precio AS precio_catalogo, c.activo AS producto_activo,
                       (SELECT COUNT(*) FROM royal_media_archivos a
                         WHERE a.empresa_id=p.empresa_id AND a.paca_id=p.id AND a.tipo='foto') AS fotos
                FROM royal_media_pacas p
                INNER JOIN wa_catalogo_productos c
                  ON c.id=p.producto_id AND c.empresa_id=p.empresa_id
                WHERE p.empresa_id=? AND p.lista_ia=1
                  AND p.estado='disponible' AND c.stock_actual>0 AND c.activo=1
                  AND (c.nombre LIKE ? ESCAPE '!' OR p.descripcion LIKE ? ESCAPE '!'
                       OR p.caracteristicas_ia LIKE ? ESCAPE '!')
                ORDER BY c.nombre, p.id DESC LIMIT 30";
        $stmt = rm_stmt($conn, $sql, 'isss', [$rm_empresa, $patron, $patron, $patron]);
        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Nunca devolver mas fichas de un producto que unidades en su stock oficial.
        $cupos = [];
        foreach ($filas as $fila) {
            $productoId = (int)$fila['producto_id'];
            if (!isset($cupos[$productoId])) $cupos[$productoId] = (int)$fila['stock_actual'];
            if ($cupos[$productoId] < 1) continue;
            if ((int)$fila['fotos'] < 1) continue;
            if (trim((string)$fila['descripcion']) === '' ||
                trim((string)$fila['caracteristicas_ia']) === '' ||
                trim((string)$fila['condicion_ia']) === '') continue;

            $precioTexto = (string)$fila['precio_catalogo'];
            $precioValido = is_numeric($precioTexto) && (float)$precioTexto > 0;
            // El valor decimal se conserva como cadena para evitar perdida de precision.
            $precioOficial = $precioValido ? number_format((float)$precioTexto, 2, '.', '') : null;

            $cupos[$productoId]--;
            $resultados[] = [
                'paca_id' => (int)$fila['id'],
                'codigo_paca' => (string)$fila['codigo_paca'],
                'producto_id' => $productoId,
                'producto' => (string)$fila['producto_nombre'],
                'producto_activo' => (int)$fila['producto_activo'] === 1,
                'stock_oficial_producto' => (int)$fila['stock_actual'],
                'descripcion' => (string)$fila['descripcion'],
                'caracteristicas' => (string)$fila['caracteristicas_ia'],
                'condicion' => (string)$fila['condicion_ia'],
                'observaciones_ventas' => (string)($fila['observaciones_ia'] ?? ''),
                'ubicacion_interna' => (string)($fila['ubicacion_bodega'] ?? ''),
                'fotografias_registradas' => (int)$fila['fotos'],
                'ficha_url' => 'royal_media_paca.php?id=' . (int)$fila['id'],
                'precio_oficial' => $precioOficial,
                'precio_moneda' => 'HNL',
                'precio_estado' => $precioValido ? 'Precio vigente en catalogo al momento de la consulta' : 'Precio por confirmar; no ofrecer como gratuito',
            ];
        }
    }
} catch (Throwable $e) {
    error_log('Royal Media AI catalogo: ' . $e->getMessage());
    $error = 'No se pudo consultar el catalogo. Revisa el registro de errores del servidor.';
}

if ($modoJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    http_response_code($error !== '' ? 500 : 200);
    echo json_encode([
        'ok' => $error === '',
        'consulta' => $busqueda,
        'empresa_id' => $rm_empresa,
        'cantidad' => count($resultados),
        'productos' => $resultados,
        'aviso' => 'Informacion interna de apoyo comercial. Revalidar stock, estado activo y precio oficial antes de ofrecer o vender. No hay reservas. Los precios nulos requieren confirmacion.',
        'error' => $error !== '' ? $error : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

include __DIR__ . '/header.php';
?>
<style>
.rmai{color:#f4f8ff;background:linear-gradient(135deg,#07101f,#10223e);border-radius:20px;padding:22px;min-height:70vh;font-family:inherit}
.rmai *{box-sizing:border-box}.rmai h1{color:#fff;font-size:27px;margin:5px 0}.rmai h2{font-size:17px;color:#fff}.rmai .muted{color:#a6bddd;font-size:13px}.rmai .top{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}.rmai .card{background:#0d1c33;border:1px solid rgba(128,172,245,.18);border-radius:15px;padding:18px;margin-bottom:14px}.rmai form{display:flex;gap:10px;flex-wrap:wrap}.rmai input{flex:1;min-width:200px;border:1px solid #29466c;border-radius:10px;background:#071326;color:#fff;padding:12px}.rmai button,.rmai .btn{display:inline-block;background:#2863e9;border:0;border-radius:10px;color:#fff;padding:11px 15px;text-decoration:none;font-weight:700;cursor:pointer}.rmai .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:13px}.rmai .tag{display:inline-block;color:#80f3bd;background:#103c30;padding:5px 9px;border-radius:20px;font-size:12px}.rmai .warning{background:#3c3019;color:#ffe1a2;padding:12px;border-radius:10px;margin:12px 0}.rmai .error{background:#54232b;color:#ffe0e0;padding:12px;border-radius:10px}.rmai p{line-height:1.55}.rmai .field{margin:12px 0}.rmai .field strong{color:#bed6ff}.rmai .field div{white-space:pre-wrap;overflow-wrap:anywhere;margin-top:5px}.rmai .kicker{font-size:11px;color:#70e2ff;letter-spacing:1.3px;font-weight:800}.rmai .price{font-size:23px;font-weight:800;color:#8ff0bd;margin:10px 0 3px}
</style>
<main class="rmai">
  <div class="top"><div><div class="kicker">ROYAL CRM · ROYAL AI SALES</div><h1>Consulta comercial inteligente</h1><p class="muted">Consulta segura de pacas preparadas para IA, con precio y disponibilidad del catalogo oficial.</p></div><a class="btn" href="royal_media_inventario.php">Volver a Royal Media Inventory</a></div>
  <section class="card"><h2>Buscar mercaderia</h2><form method="get"><input type="search" name="buscar" maxlength="100" placeholder="Ej. sueter, mixta..." value="<?=rm_h($busqueda)?>" required><button type="submit">Consultar</button></form><p class="muted">Solo se muestran pacas disponibles, de productos activos, preparadas para IA y con fotografia. Precio y existencias se leen del catalogo oficial al consultar.</p></section>
  <?php if ($error !== ''): ?><div class="error"><?=rm_h($error)?></div><?php endif; ?>
  <?php if ($busqueda !== '' && $error === ''): ?>
    <p class="muted">Resultados para <strong><?=rm_h($busqueda)?></strong>: <?=count($resultados)?> ficha(s) apta(s) para consulta.</p>
    <div class="grid">
      <?php foreach ($resultados as $r): ?>
        <article class="card"><span class="tag">Preparada para IA · stock positivo · producto activo</span><h2><?=rm_h($r['producto'])?></h2><p class="muted">Paca <?=rm_h($r['codigo_paca'])?> · Stock oficial del producto: <?=(int)$r['stock_oficial_producto']?></p>
          <?php if ($r['precio_oficial'] !== null): ?>
            <div class="price">L <?=rm_h(number_format((float)$r['precio_oficial'], 2, '.', ','))?></div>
            <p class="muted">Precio oficial del catalogo CRM · HNL · Revalidar antes de cerrar la venta.</p>
          <?php else: ?>
            <div class="warning">Precio por confirmar. No anunciar como gratuito ni ofrecer un importe no validado.</div>
          <?php endif; ?>
          <div class="field"><strong>Descripcion</strong><div><?=rm_h($r['descripcion'])?></div></div>
          <div class="field"><strong>Caracteristicas</strong><div><?=rm_h($r['caracteristicas'])?></div></div>
          <div class="field"><strong>Condicion</strong><div><?=rm_h($r['condicion'])?></div></div>
          <div class="field"><strong>Observaciones para ventas</strong><div><?=rm_h($r['observaciones_ventas'])?></div></div>
          <p class="muted">Fotografias registradas: <?=(int)$r['fotografias_registradas']?></p>
          <div class="warning">Consulta informativa. Revalidar precio, existencias y disponibilidad antes de ofrecer o vender; esta consulta no reserva mercaderia.</div>
          <a class="btn" href="<?=rm_h($r['ficha_url'])?>">Abrir ficha y fotografias</a>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if (!$resultados): ?><section class="card"><p>No se encontraron pacas que cumplan todas las condiciones. Comprueba estado del producto y la paca, fotografia, informacion comercial y stock oficial.</p></section><?php endif; ?>
    <p><a class="btn" href="?buscar=<?=rawurlencode($busqueda)?>&amp;formato=json" target="_blank" rel="noopener">Ver respuesta JSON (uso interno)</a></p>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/footer.php'; ?>