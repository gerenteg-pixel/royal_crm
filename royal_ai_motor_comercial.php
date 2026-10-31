<?php
/**
 * Royal AI Sales - Motor comercial de consulta (v1, SOLO LECTURA).
 * Colocar en la raiz del CRM, junto a auth.php y empresa_core.php.
 * No crea fichas, no modifica precios, no reserva ni descuenta inventario.
 */
declare(strict_types=1);
date_default_timezone_set('America/Tegucigalpa');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/empresa_core.php';
require_login();
require_permiso('ver_productos');
$empresaId = (int) empresa_id_actual();
if ($empresaId <= 0) { http_response_code(403); exit('Empresa no valida'); }

function ai_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ai_tipo(string $nombre): array {
    $n = mb_strtoupper(trim($nombre), 'UTF-8');
    // Clasificacion informativa; NO altera la cantidad de stock ni el precio del catalogo.
    if (preg_match('/(?:^|\W)SU[EÉ]TER(?:\W|$)/u', $n) && preg_match('/(?:^|\W)P2(?:\W|$)/u', $n)) {
        return ['combo_p2', 2, 'Promocion de 2 pacas; verificar regla de cantidad en la ficha'];
    }
    if (preg_match('/(?:^|\W)SU[EÉ]TER(?:\W|$)/u', $n) && preg_match('/(?:^|\W)P3(?:\W|$)/u', $n)) {
        return ['combo_p3', 3, 'Promocion de 3 pacas; verificar ajuste comercial de redondeo'];
    }
    if (preg_match('/REGAL[IÍ]A|REGALO|CORTES[IÍ]A/u', $n)) {
        return ['cortesia', null, 'No vender automaticamente; requiere autorizacion'];
    }
    return ['normal', null, 'Producto normal; confirmar condiciones comerciales'];
}

$items = [];
$error = '';
try {
    $stmt = $conn->prepare('SELECT id, nombre, precio, stock_actual, activo FROM wa_catalogo_productos WHERE empresa_id = ? ORDER BY nombre ASC');
    if (!$stmt) { throw new RuntimeException('No se pudo preparar la consulta'); }
    $stmt->bind_param('i', $empresaId);
    if (!$stmt->execute()) { throw new RuntimeException('No se pudo consultar el catalogo'); }
    $result = $stmt->get_result();
    while ($p = $result->fetch_assoc()) {
        [$tipo, $pacas, $nota] = ai_tipo((string)$p['nombre']);
        $precio = (float)$p['precio'];
        $stock = (int)$p['stock_actual'];
        $activo = (int)$p['activo'] === 1;
        $totalTeorico = $pacas !== null ? round($precio * $pacas, 2) : null;
        $advertencias = [];
        if ($tipo === 'combo_p3' && $totalTeorico !== null && abs($totalTeorico - 7000.00) >= 0.005) {
            $advertencias[] = 'El precio unitario multiplicado por 3 da L ' . number_format($totalTeorico, 2, '.', ',') . '; el precio comercial de referencia es L 7,000.00. No se modifica ningun importe.';
        }
        if (!$activo || $stock <= 0) { $advertencias[] = 'Sin disponibilidad confirmada para ofrecer.'; }
        if ($tipo === 'cortesia') { $advertencias[] = 'Precio nominal administrativo, no precio de venta.'; }
        $items[] = [
            'id' => (int)$p['id'], 'nombre' => (string)$p['nombre'],
            'tipo' => $tipo, 'pacas_por_promocion' => $pacas,
            'precio_catalogo' => number_format($precio, 2, '.', ''),
            'total_teorico_promocion' => $totalTeorico === null ? null : number_format($totalTeorico, 2, '.', ''),
            'stock_catalogo' => $stock, 'activo' => $activo,
            'nota' => $nota, 'advertencias' => $advertencias,
            // Bloqueo intencional hasta validar reglas comerciales, disponibilidad y autorizaciones.
            'oferta_automatica_autorizada' => false
        ];
    }
    $stmt->close();
} catch (Throwable $e) {
    error_log('[Royal AI Sales] Consulta comercial fallida: ' . $e->getMessage());
    $error = 'No fue posible consultar el catalogo. Revise los registros del servidor.';
}

$isJson = isset($_GET['formato']) && $_GET['formato'] === 'json';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    if ($error !== '') { http_response_code(500); }
    echo json_encode([
        'ok' => $error === '', 'empresa_id' => $empresaId,
        'modo' => 'solo_lectura', 'ofertas_automaticas_habilitadas' => false,
        'productos' => $items, 'error' => $error === '' ? null : $error
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
include __DIR__ . '/header.php';
?>
<div class="card">
  <h2>Royal AI Sales - Motor comercial (diagnostico)</h2>
  <p>Consulta de solo lectura. No crea ventas, no altera inventario, no autoriza ofertas.</p>
  <p><strong>Empresa:</strong> <?= $empresaId ?> | <strong>Productos:</strong> <?= count($items) ?> | <strong>Ofertas automaticas:</strong> DESHABILITADAS</p>
  <p><a class="btn" href="?formato=json">Ver JSON de diagnostico</a></p>
</div>
<?php if ($error !== ''): ?><div class="card" style="color:#f87171"><?= ai_h($error) ?></div><?php endif; ?>
<div class="card" style="overflow-x:auto">
<table class="table" style="width:100%">
<thead><tr><th>Producto</th><th>Tipo</th><th>Precio catalogo</th><th>Stock catalogo</th><th>Total teorico promo</th><th>Estado IA</th></tr></thead>
<tbody>
<?php foreach ($items as $p): ?>
<tr>
<td><strong><?= ai_h($p['nombre']) ?></strong><div class="muted"><?= ai_h($p['nota']) ?></div>
<?php foreach ($p['advertencias'] as $a): ?><div style="color:#f59e0b;font-size:12px"><?= ai_h($a) ?></div><?php endforeach; ?></td>
<td><?= ai_h($p['tipo']) ?></td>
<td>L <?= ai_h($p['precio_catalogo']) ?></td>
<td><?= (int)$p['stock_catalogo'] ?></td>
<td><?= $p['total_teorico_promocion'] === null ? '—' : 'L ' . ai_h($p['total_teorico_promocion']) ?></td>
<td>No autorizada</td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="6">Sin productos para mostrar.</td></tr><?php endif; ?>
</tbody></table></div>
<?php include __DIR__ . '/footer.php'; ?>