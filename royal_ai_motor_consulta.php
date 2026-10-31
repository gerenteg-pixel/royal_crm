<?php
declare(strict_types=1);
require_once __DIR__ . '/royal_media_core.php';

// Royal AI Sales: consulta administrativa independiente y exclusivamente de lectura.
// NO es un endpoint publico para bots. NO autoriza ventas, descuentos o regalias.
if (!$rm_admin) {
    http_response_code(403);
    exit('Acceso exclusivo de administracion.');
}

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

$productoId = filter_input(INPUT_GET, 'producto_id', FILTER_VALIDATE_INT);
$productoId = ($productoId !== false && $productoId !== null && $productoId > 0) ? $productoId : 0;
$modoJson = (string)($_GET['formato'] ?? '') === 'json';
$error = '';
$producto = null;
$catalogo = [];

function raim_tipo(int $id, int $empresa, string $nombre): string {
    // IDs confirmados para Royal Prestige, empresa 1. No se aplican a otras empresas.
    if ($empresa === 1 && $id === 16) return 'regalia_codigo';
    if ($empresa === 1 && $id === 15) return 'caja_regalia';
    if ($empresa === 1 && $id === 13) return 'regalia_sueter';
    $n = mb_strtolower(trim($nombre), 'UTF-8');
    $n = preg_replace('/\s+/u', ' ', $n) ?? $n;
    if (preg_match('/combo\s+su[eé]ter\s*p\s*2/u', $n)) return 'combo_p2';
    if (preg_match('/combo\s+su[eé]ter\s*p\s*3/u', $n)) return 'combo_p3';
    if (preg_match('/regal[ií]a\s+c[oó]digo/u', $n)) return 'regalia_codigo';
    if (preg_match('/caja\s+regal[ií]a/u', $n)) return 'caja_regalia';
    if (preg_match('/regal[ií]a\s+su[eé]ter/u', $n)) return 'regalia_sueter';
    return 'venta_normal';
}

function raim_reglas(string $tipo): array {
    $base = [
        'Revalidar existencias, precio y unidad fisica antes de informar disponibilidad.',
        'No crear fichas, ordenes, reservas, despachos ni modificar inventario desde esta consulta.',
        'No prometer regalos, descuentos, flete ni condiciones sin autorizacion vigente.',
    ];
    switch ($tipo) {
        case 'combo_p2':
            return array_merge($base, [
                'Combo de 2 pacas completas. La logica de cantidades pertenece al CRM original.',
                'El precio del catalogo es unitario. No recalcular ni sobrescribir el total del CRM.',
                'Verificar promocion vigente, existencia fisica y reglas de descuento de inventario.',
                'Las cortesias no se presumen incluidas en promociones.',
            ]);
        case 'combo_p3':
            return array_merge($base, [
                'Combo de 3 pacas completas. La logica de cantidades pertenece al CRM original.',
                'El precio unitario puede tener redondeo decimal; NO cambiar el importe comercial del CRM.',
                'Verificar promocion vigente, existencia fisica y reglas de descuento de inventario.',
                'Las cortesias no se presumen incluidas en promociones.',
            ]);
        case 'regalia_codigo':
            return array_merge($base, [
                'Mercaderia equivalente a Paca Codigo; en este registro se destina a regalia.',
                'El valor nominal del catalogo NO es un precio de venta al publico.',
                'La administracion decide por descarga si se asigna como regalia o venta normal.',
                'Confirmar autorizacion y disponibilidad antes de prometer entrega.',
            ]);
        case 'caja_regalia':
            return array_merge($base, [
                'Cortesia sujeta a autorizacion y disponibilidad, no venta normal.',
                'La caja puede corresponder a ACC Mixto, Brick Brack o Juguete, segun asignacion.',
                'Comprar una paca no garantiza automaticamente la entrega de una caja.',
                'El valor nominal del catalogo NO es un precio de venta.',
            ]);
        case 'regalia_sueter':
            return array_merge($base, [
                'Bolsa pequena de piezas de sueter; NO es una paca completa.',
                'Cortesia condicionada a preparacion, disponibilidad y autorizacion.',
                'El valor nominal del catalogo NO es un precio de venta.',
            ]);
        default:
            return array_merge($base, [
                'No asumir que una compra incluye cortesia; confirmar cada beneficio.',
            ]);
    }
}

try {
    $s = rm_stmt($conn,
        'SELECT id, nombre FROM wa_catalogo_productos WHERE empresa_id=? ORDER BY nombre ASC, id ASC',
        'i', [$rm_empresa]);
    $catalogo = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();

    if ($productoId > 0) {
        $columnas = implode(', ', array_map(static fn(string $campo): string => 'm.`' . $campo . '`', array_keys($campos)));
        $sql = 'SELECT c.id, c.nombre, c.sku, c.precio, c.stock_actual, c.activo, '
             . 'COALESCE(m.listo_ia,0) AS listo_ia, ' . $columnas
             . ' FROM wa_catalogo_productos c LEFT JOIN royal_media_catalogo_maestro m '
             . 'ON m.empresa_id=c.empresa_id AND m.producto_id=c.id '
             . 'WHERE c.empresa_id=? AND c.id=? LIMIT 1';
        $s = rm_stmt($conn, $sql, 'ii', [$rm_empresa, $productoId]);
        $fila = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$fila) {
            $error = 'Producto no encontrado para esta empresa.';
        } else {
            $tipo = raim_tipo((int)$fila['id'], $rm_empresa, (string)$fila['nombre']);
            $activo = (int)$fila['activo'] === 1;
            $stock = (int)$fila['stock_actual'];
            $listo = (int)$fila['listo_ia'] === 1;
            $precio = (string)$fila['precio'];
            $precioValido = is_numeric($precio) && (float)$precio > 0;
            $info = [];
            foreach ($campos as $campo => $etiqueta) $info[$campo] = (string)($fila[$campo] ?? '');
            $bloqueos = [];
            if (!$activo) $bloqueos[] = 'Producto inactivo.';
            if (!$listo) $bloqueos[] = 'Ficha comercial no preparada.';
            if ($stock < 1) $bloqueos[] = 'Sin stock oficial en esta categoria.';
            if (!$precioValido) $bloqueos[] = 'Precio oficial pendiente de validacion.';
            $producto = [
                'producto_id' => (int)$fila['id'],
                'empresa_id' => $rm_empresa,
                'nombre' => (string)$fila['nombre'],
                'sku' => (string)($fila['sku'] ?? ''),
                'tipo_comercial' => $tipo,
                'activo' => $activo,
                'stock_oficial' => $stock,
                'ficha_preparada' => $listo,
                'precio_catalogo_hnl' => $precioValido ? number_format((float)$precio, 2, '.', '') : null,
                'precio_es_nominal_no_venta' => in_array($tipo, ['regalia_codigo', 'caja_regalia', 'regalia_sueter'], true),
                'informacion_comercial' => $info,
                'bloqueos_detectados' => $bloqueos,
                'validaciones_obligatorias' => raim_reglas($tipo),
                'autorizado_para_ofrecer' => false,
                'autorizado_para_vender' => false,
                'autorizado_para_regalar' => false,
                'modo' => 'diagnostico_administrativo_solo_lectura',
            ];
        }
    }
} catch (Throwable $e) {
    error_log('Royal AI motor consulta: ' . $e->getMessage());
    $error = 'No se pudo consultar el producto. Revisa el registro del servidor.';
}

if ($modoJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    http_response_code($error === '' ? 200 : ($productoId > 0 && $error === 'Producto no encontrado para esta empresa.' ? 404 : 500));
    echo json_encode([
        'ok' => $error === '',
        'empresa_id' => $rm_empresa,
        'producto' => $producto,
        'autorizacion_automatica' => false,
        'aviso' => 'Consulta interna exclusivamente de lectura. No autoriza ventas, promociones, regalias ni despachos.',
        'error' => $error === '' ? null : $error,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
include __DIR__ . '/header.php';
?>
<style>
.raim{background:linear-gradient(135deg,#07101f,#10223e);color:#edf5ff;border-radius:18px;padding:22px;min-height:65vh}
.raim *{box-sizing:border-box}.raim h1,.raim h2{color:#fff}.raim .muted{color:#aec4e2}.raim .actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:15px 0}.raim select{background:#071326;color:#fff;border:1px solid #355477;border-radius:9px;padding:11px;min-width:240px;max-width:100%}.raim .btn,.raim button{display:inline-block;background:#2863e9;color:white;border:0;border-radius:9px;padding:11px 16px;text-decoration:none;font-weight:700;cursor:pointer}.raim .card{background:#0e1d34;border:1px solid #28415f;border-radius:14px;padding:17px;margin:13px 0}.raim .warn{color:#ffcf8c}.raim .error{color:#ffcccc}.raim .fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:12px}.raim .field{background:#071326;border:1px solid #28415f;border-radius:10px;padding:13px;white-space:pre-wrap;overflow-wrap:anywhere}.raim .field strong{display:block;margin-bottom:9px}.raim li{margin:7px 0}
</style>
<main class="raim">
<h1>Royal AI Sales · Motor de consulta comercial</h1>
<p class="muted">Diagnostico administrativo privado. Consulta el catalogo oficial y los ocho campos comerciales sin modificar datos.</p>
<div class="actions"><a class="btn" href="royal_media_inventario.php">Volver a Royal Media Inventory</a><a class="btn" href="royal_ai_reglas_comerciales.php">Reglas comerciales</a></div>
<form method="get" class="actions">
<label for="producto_id">Producto oficial:</label>
<select id="producto_id" name="producto_id" required><option value="">Seleccionar producto</option>
<?php foreach ($catalogo as $p): ?><option value="<?=(int)$p['id']?>" <?=((int)$p['id'] === $productoId ? 'selected' : '')?>><?=rm_h($p['nombre'])?> (ID <?=(int)$p['id']?>)</option><?php endforeach; ?>
</select><button type="submit">Consultar</button>
<?php if ($productoId > 0): ?><a class="btn" href="?producto_id=<?=$productoId?>&amp;formato=json" target="_blank" rel="noopener">Ver JSON interno</a><?php endif; ?>
</form>
<p class="warn"><strong>Ofertas automaticas deshabilitadas.</strong> Esta consulta no autoriza ventas, regalos, combos ni descuentos.</p>
<?php if ($error !== ''): ?><p class="error"><?=rm_h($error)?></p><?php endif; ?>
<?php if ($producto !== null): ?>
<section class="card">
<h2><?=rm_h($producto['nombre'])?></h2>
<p class="muted">ID <?=(int)$producto['producto_id']?> · Tipo: <?=rm_h($producto['tipo_comercial'])?> · <?= $producto['activo'] ? 'Activo' : 'Inactivo' ?> · Stock oficial: <?=(int)$producto['stock_oficial']?> · <?= $producto['ficha_preparada'] ? 'Ficha preparada' : 'Ficha pendiente' ?></p>
<p><strong>Precio catalogo:</strong> <?= $producto['precio_catalogo_hnl'] !== null ? 'L ' . rm_h($producto['precio_catalogo_hnl']) : 'Por confirmar' ?><?= $producto['precio_es_nominal_no_venta'] ? ' · VALOR NOMINAL, NO PRECIO DE VENTA' : '' ?></p>
<?php if ($producto['bloqueos_detectados']): ?><h3>Bloqueos detectados</h3><ul><?php foreach ($producto['bloqueos_detectados'] as $v): ?><li><?=rm_h($v)?></li><?php endforeach; ?></ul><?php endif; ?>
<h3>Validaciones comerciales</h3><ul><?php foreach ($producto['validaciones_obligatorias'] as $v): ?><li><?=rm_h($v)?></li><?php endforeach; ?></ul>
</section>
<h2>Ocho campos comerciales</h2><div class="fields">
<?php foreach ($campos as $clave => $etiqueta): ?><div class="field"><strong><?=rm_h($etiqueta)?></strong><?=rm_h($producto['informacion_comercial'][$clave] !== '' ? $producto['informacion_comercial'][$clave] : 'Sin informacion registrada.')?></div><?php endforeach; ?>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/footer.php'; ?>
