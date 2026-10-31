<?php
declare(strict_types=1);
require_once __DIR__ . '/royal_media_core.php';

// Motor de diagnostico administrativo, exclusivamente de lectura.
// No es un endpoint publico para bots, no vende ni autoriza promociones.
$productos = [];
$error = '';
$modoJson = (string)($_GET['formato'] ?? '') === 'json';

function rarc_tipo(string $nombre): string {
    $n = mb_strtolower(trim($nombre), 'UTF-8');
    $n = preg_replace('/\s+/u', ' ', $n) ?? $n;

    // Identificar regalías antes de clasificar ventas normales.
    if (strpos($n, 'regalia codigo') !== false ||
        strpos($n, 'regalía código') !== false ||
        strpos($n, 'regalia código') !== false ||
        strpos($n, 'regalía codigo') !== false) {
        return 'regalia_codigo';
    }

    if (strpos($n, 'regalia sueter') !== false ||
        strpos($n, 'regalía suéter') !== false ||
        strpos($n, 'regalia suéter') !== false ||
        strpos($n, 'regalía sueter') !== false) {
        return 'regalia_sueter';
    }

    if (strpos($n, 'caja regalia') !== false ||
        strpos($n, 'caja regalía') !== false) {
        return 'caja_regalia';
    }

    if (preg_match('/combo\s+su[eé]ter\s*p\s*2/u', $n)) {
        return 'combo_p2';
    }

    if (preg_match('/combo\s+su[eé]ter\s*p\s*3/u', $n)) {
        return 'combo_p3';
    }

    return 'venta_normal';
}
function rarc_etiqueta(string $tipo): string {
    $map = [
        'venta_normal' => 'Venta normal',
        'combo_p2' => 'Combo promocional de 2 pacas',
        'combo_p3' => 'Combo promocional de 3 pacas',
        'caja_regalia' => 'Caja de regalía (autorización administrativa)',
        'regalia_codigo' => 'Paca Código destinada a regalía',
        'regalia_sueter' => 'Bolsa pequeña de suéter (cortesía)',
    ];

    return $map[$tipo] ?? 'Requiere revisión';
}
try {
    $sql = 'SELECT c.id, c.nombre, c.activo, c.stock_actual, c.precio, '
         . 'COALESCE(m.listo_ia,0) AS listo_ia '
         . 'FROM wa_catalogo_productos c '
         . 'LEFT JOIN royal_media_catalogo_maestro m '
         . 'ON m.producto_id=c.id AND m.empresa_id=c.empresa_id '
         . 'WHERE c.empresa_id=? ORDER BY c.nombre, c.id';
    $s = rm_stmt($conn, $sql, 'i', [$rm_empresa]);
    $filas = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    foreach ($filas as $fila) {
        $tipo = rarc_tipo((string)$fila['nombre']);
        // Identificación por ID oficial para Royal Prestige.
// No modifica nombres, precios ni inventario.
if ((int)$rm_empresa === 1) {
    if ((int)$fila['id'] === 15) {
        $tipo = 'caja_regalia';
    } elseif ((int)$fila['id'] === 16) {
        $tipo = 'regalia_codigo';
    }
}
        $activo = (int)$fila['activo'] === 1;
        $stock = max(0, (int)$fila['stock_actual']);
        $listo = (int)$fila['listo_ia'] === 1;
        $precio = (string)$fila['precio'];
        $precioValido = is_numeric($precio) && (float)$precio > 0;
        $bloqueos = [];
        $verificaciones = [];
        if (!$activo) $bloqueos[] = 'Producto inactivo en el catalogo oficial.';
        if (!$listo) $bloqueos[] = 'Ficha comercial maestra pendiente.';
        if ($stock === 0) $bloqueos[] = 'Sin stock oficial en esta categoria.';
        if ($tipo === 'venta_normal') {
            if (!$precioValido) $bloqueos[] = 'Precio de venta no confirmado.';
            $verificaciones[] = 'Revalidar precio, existencias y unidad fisica antes de ofrecer.';
            $verificaciones[] = 'Verificar por separado cualquier cortesia o descuento.';
        } elseif ($tipo === 'combo_p2' || $tipo === 'combo_p3') {
            if (!$precioValido) $bloqueos[] = 'Precio promocional no confirmado.';
            $verificaciones[] = 'Verificar promocion vigente y autorizada: activo=1 no confirma vigencia.';
            $verificaciones[] = 'Confirmar disponibilidad real de ' . ($tipo === 'combo_p2' ? '2' : '3') . ' pacas fisicas y su relacion con el stock de Paca Sueter.';
            $verificaciones[] = 'Verificar reglas de descuento de inventario y evitar doble conteo.';
            $verificaciones[] = 'Las cortesias no se presumen incluidas en promociones.';
        } else {
            $verificaciones[] = 'Verificar autorizacion administrativa y condiciones de cortesia vigentes.';
            $verificaciones[] = 'Confirmar inventario de cortesia y asignacion fisica antes de prometer entrega.';
            $verificaciones[] = 'Un precio de L 0.01 o L 0.00 NO autoriza cobrar ni regalar.';
            if ($tipo === 'caja_regalia') {
    $verificaciones[] = 'Confirmar elegibilidad de la compra, autorización administrativa y tipo de caja asignado.';
    $verificaciones[] = 'La caja de regalía depende de la disponibilidad y no se promete automáticamente.';
} elseif ($tipo === 'regalia_codigo') {
    $verificaciones[] = 'Confirmar que administración destinó esta mercadería de Paca Código a regalía para la descarga correspondiente.';
    $verificaciones[] = 'La misma categoría puede destinarse a venta normal en otras operaciones; verificar su asignación actual.';
    $verificaciones[] = 'El precio nominal L 0.01 no representa precio de venta al público.';
} elseif ($tipo === 'regalia_sueter') {
    $verificaciones[] = 'Confirmar bolsa pequeña de suéter preparada y autorizada.';
    $verificaciones[] = 'No tratar la cortesía como una paca completa.';
}
        }
        // Ningun registro es autorizacion automatica para vender o regalar.
        $productos[] = [
            'producto_id' => (int)$fila['id'],
            'nombre' => (string)$fila['nombre'],
            'tipo' => $tipo,
            'tipo_etiqueta' => rarc_etiqueta($tipo),
            'activo' => $activo,
            'ficha_preparada' => $listo,
            'stock_oficial' => $stock,
            'precio_oficial_hnl' => $precio,
            'precio_positivo' => $precioValido,
            'bloqueos_detectados' => $bloqueos,
            'validaciones_pendientes' => $verificaciones,
            'autorizado_para_ofrecer' => false,
            'motivo_autorizacion' => 'Diagnostico de solo lectura: no existe verificacion integral de promociones, cortesias, reservas y stock fisico.',
        ];
    }
} catch (Throwable $e) {
    error_log('Royal AI reglas comerciales: ' . $e->getMessage());
    $error = 'No se pudo consultar el catalogo. Revisa el registro de errores del servidor.';
}

if ($modoJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    http_response_code($error === '' ? 200 : 500);
    echo json_encode([
        'ok' => $error === '',
        'empresa_id' => $rm_empresa,
        'modo' => 'diagnostico_interno_solo_lectura',
        'cantidad' => count($productos),
        'autorizacion_ventas_automaticas' => false,
        'productos' => $productos,
        'error' => $error === '' ? null : $error,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
include __DIR__ . '/header.php';
?>
<style>
.rarc{color:#eaf2ff;background:linear-gradient(135deg,#07101f,#10223e);border-radius:18px;padding:22px;min-height:70vh}
.rarc *{box-sizing:border-box}.rarc h1,.rarc h2{color:#fff}.rarc .muted{color:#adc3df}.rarc .links{display:flex;gap:9px;flex-wrap:wrap;margin:14px 0}.rarc .btn{display:inline-block;background:#2863e9;color:#fff;padding:10px 14px;border-radius:9px;text-decoration:none;font-weight:700}.rarc .notice{background:#322b19;color:#ffdea0;border:1px solid #635027;padding:13px;border-radius:10px;margin:14px 0}.rarc .error{background:#51252c;color:#ffe2e2;padding:12px;border-radius:10px}.rarc .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:12px}.rarc .card{background:#102139;border:1px solid #2c4465;border-radius:13px;padding:16px}.rarc .card h2{margin:0 0 8px;font-size:18px}.rarc .meta{color:#b9cbe3;font-size:13px;line-height:1.6}.rarc .warn{color:#ffd28c}.rarc ul{padding-left:19px}.rarc li{margin:6px 0;font-size:13px;line-height:1.5}.rarc details{margin-top:12px}.rarc summary{cursor:pointer;color:#a8d3ff;font-weight:700}.rarc .pill{display:inline-block;background:#1e3555;color:#b9ddff;padding:4px 9px;border-radius:20px;font-size:12px}
</style>
<main class="rarc">
  <h1>Royal AI Sales · Diagnostico de reglas comerciales</h1>
  <p class="muted">Consulta administrativa. No autoriza ventas, combos, descuentos, regalos ni despachos.</p>
  <div class="links">
    <a class="btn" href="royal_media_inventario.php">Volver a Royal Media Inventory</a>
    <a class="btn" href="royal_ai_consulta_maestra.php">Consulta Comercial IA</a>
    <a class="btn" href="?formato=json" target="_blank" rel="noopener">Ver JSON interno</a>
  </div>
  <div class="notice">Las fichas preparadas describen los productos, pero NO confirman existencias fisicas ni promociones vigentes. Este diagnostico mantiene todas las ofertas automaticas bloqueadas hasta integrar las reglas reales del CRM.</div>
  <?php if ($error !== ''): ?><p class="error"><?=rm_h($error)?></p><?php endif; ?>
  <p class="muted"><?=count($productos)?> categorias de la empresa actual.</p>
  <div class="grid">
  <?php foreach ($productos as $p): ?>
    <article class="card">
      <h2><?=rm_h($p['nombre'])?></h2>
      <span class="pill"><?=rm_h($p['tipo_etiqueta'])?></span>
      <p class="meta">ID: <?=(int)$p['producto_id']?> · <?= $p['activo'] ? 'Activo' : 'Inactivo' ?> · <?= $p['ficha_preparada'] ? 'Ficha preparada' : 'Ficha pendiente' ?><br>
      Stock oficial: <?=(int)$p['stock_oficial']?> · Precio registrado: L <?=rm_h(number_format((float)$p['precio_oficial_hnl'],2,'.',','))?></p>
      <p class="warn"><strong>Oferta automatica: NO AUTORIZADA</strong></p>
      <?php if ($p['bloqueos_detectados']): ?>
        <details><summary>Bloqueos detectados (<?=count($p['bloqueos_detectados'])?>)</summary><ul><?php foreach ($p['bloqueos_detectados'] as $texto): ?><li><?=rm_h($texto)?></li><?php endforeach;?></ul></details>
      <?php endif; ?>
      <details><summary>Validaciones pendientes (<?=count($p['validaciones_pendientes'])?>)</summary><ul><?php foreach ($p['validaciones_pendientes'] as $texto): ?><li><?=rm_h($texto)?></li><?php endforeach;?></ul></details>
    </article>
  <?php endforeach; ?>
  </div>
</main>
<?php include __DIR__ . '/footer.php'; ?>