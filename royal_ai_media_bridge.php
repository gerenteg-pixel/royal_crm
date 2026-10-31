<?php
/**
 * Royal AI Sales - puente de lectura de fotos para agentes IA.
 * Libreria interna: NO es un endpoint HTTP. No modifica Royal Chat.
 * El worker autorizado debe validar el piloto, destinatario y la instancia
 * y suministrar un callback de envio que registre el mensaje en Royal Chat.
 */
declare(strict_types=1);

function royal_ai_media_fotos(mysqli $conn, int $empresaId, string $producto, int $limite = 3): array
{
    if ($empresaId <= 0 || trim($producto) === '') {
        throw new InvalidArgumentException('Empresa o producto no valido.');
    }
    $limite = max(1, min(5, $limite));
    $nombre = '%' . trim($producto) . '%';
    $sql = "SELECT a.id AS archivo_id, a.ruta_archivo, a.mime_type,
                   p.id AS paca_id, p.codigo_paca, p.descripcion,
                   p.caracteristicas_ia, p.condicion_ia, c.nombre AS producto_nombre
            FROM royal_media_pacas p
            INNER JOIN wa_catalogo_productos c
                    ON c.id = p.producto_id AND c.empresa_id = p.empresa_id
            INNER JOIN royal_media_archivos a
                    ON a.paca_id = p.id AND a.empresa_id = p.empresa_id
            WHERE p.empresa_id = ? AND p.estado = 'disponible'
              AND p.lista_ia = 1 AND c.stock_actual > 0
              AND c.nombre LIKE ? AND a.tipo = 'foto'
              AND a.mime_type IN ('image/jpeg','image/png','image/webp')
            ORDER BY p.id DESC, a.id DESC LIMIT {$limite}";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar el catalogo de IA.');
    }
    $stmt->bind_param('is', $empresaId, $nombre);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $root = realpath(__DIR__ . '/royal_media_uploads');
    if (!$root) return [];
    $out = [];
    foreach ($rows as $row) {
        $path = realpath(__DIR__ . '/' . (string)$row['ruta_archivo']);
        if (!$path || strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0 || !is_file($path)) continue;
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > 8 * 1024 * 1024) continue;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $mime !== $row['mime_type']) continue;
        $row['ruta_local_validada'] = $path;
        $out[] = $row;
    }
    return $out;
}

/**
 * Genera payload Z-API para foto validada. No realiza envio ni registra mensajes.
 * El callback del worker debe usar instancia autorizada y persistir historial.
 */
function royal_ai_media_payload_zapi(array $foto, string $telefono, string $caption = ''): array
{
    $path = (string)($foto['ruta_local_validada'] ?? '');
    $mime = (string)($foto['mime_type'] ?? '');
    if (!is_file($path) || !in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
        throw new InvalidArgumentException('Foto no validada.');
    }
    $actual = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($actual !== $mime || filesize($path) > 8 * 1024 * 1024) {
        throw new RuntimeException('El archivo cambio desde su validacion.');
    }
    $bytes = file_get_contents($path);
    if ($bytes === false || $bytes === '') throw new RuntimeException('No se pudo leer la fotografia.');
    $payload = ['phone' => $telefono, 'image' => 'data:' . $mime . ';base64,' . base64_encode($bytes)];
    if ($caption !== '') $payload['caption'] = mb_substr($caption, 0, 900);
    return $payload;
}
