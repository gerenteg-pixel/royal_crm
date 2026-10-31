<?php
declare(strict_types=1);
require_once __DIR__ . '/royal_media_core.php';

// Catalogo privado de fotografias para operadores autenticados del CRM.
// No publica archivos, no crea enlaces anonimos y no envia mensajes.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function rm_ai_fotos_responder(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$id = filter_input(INPUT_GET, 'paca_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    rm_ai_fotos_responder(400, ['ok' => false, 'error' => 'Indica un paca_id valido.']);
}

try {
    $sql = "SELECT p.id, p.codigo_paca, p.producto_id, p.estado, p.lista_ia,
                   p.descripcion, p.caracteristicas_ia, p.condicion_ia,
                   c.nombre AS producto, c.stock_actual, c.activo
            FROM royal_media_pacas p
            INNER JOIN wa_catalogo_productos c
                ON c.id = p.producto_id AND c.empresa_id = p.empresa_id
            WHERE p.id = ? AND p.empresa_id = ? LIMIT 1";
    $s = rm_stmt($conn, $sql, 'ii', [(int)$id, $rm_empresa]);
    $p = $s->get_result()->fetch_assoc();
    $s->close();

    // La misma respuesta evita revelar si una paca existe en otra empresa.
    if (!$p || (int)$p['lista_ia'] !== 1 || $p['estado'] !== 'disponible'
        || (int)$p['activo'] !== 1 || (int)$p['stock_actual'] < 1
        || trim((string)$p['descripcion']) === ''
        || trim((string)$p['caracteristicas_ia']) === ''
        || trim((string)$p['condicion_ia']) === '') {
        rm_ai_fotos_responder(404, ['ok' => false, 'error' => 'Paca no disponible para consulta de IA.']);
    }

    $s = rm_stmt($conn,
        "SELECT id, nombre_original, mime_type, tamano_bytes, created_at
         FROM royal_media_archivos
         WHERE empresa_id = ? AND paca_id = ? AND tipo = 'foto'
         ORDER BY id ASC LIMIT 100",
        'ii', [$rm_empresa, (int)$id]);
    $archivos = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();

    if (!$archivos) {
        rm_ai_fotos_responder(404, ['ok' => false, 'error' => 'No hay fotografias registradas.']);
    }

    $fotos = [];
    foreach ($archivos as $a) {
        $fotos[] = [
            'archivo_id' => (int)$a['id'],
            'nombre' => (string)$a['nombre_original'],
            'mime' => (string)$a['mime_type'],
            'tamano_bytes' => (int)($a['tamano_bytes'] ?? 0),
            'registrada_en' => (string)$a['created_at'],
            // Ruta relativa. Requiere la sesion CRM y permisos actuales.
            'url_privada_crm' => 'royal_media_archivo.php?id=' . (int)$a['id'],
        ];
    }

    rm_ai_fotos_responder(200, [
        'ok' => true,
        'empresa_id' => $rm_empresa,
        'paca_id' => (int)$p['id'],
        'codigo_paca' => (string)$p['codigo_paca'],
        'producto_id' => (int)$p['producto_id'],
        'producto' => (string)$p['producto'],
        'stock_oficial_producto' => (int)$p['stock_actual'],
        'cantidad_fotografias' => count($fotos),
        'fotografias' => $fotos,
        'aviso' => 'Solo consulta interna. Las URL requieren sesion CRM; no son enlaces publicos ni aptos para enviarse directamente por WhatsApp. Revalidar disponibilidad antes de cualquier envio.',
    ]);
} catch (Throwable $e) {
    error_log('Royal Media AI fotografias: ' . $e->getMessage());
    rm_ai_fotos_responder(500, ['ok' => false, 'error' => 'No se pudo consultar las fotografias.']);
}