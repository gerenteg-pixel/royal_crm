<?php
declare(strict_types=1);
/** Envio Z-API de una foto validada, a clientes del agente multi autorizado. Un intento sin reintentos. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

function rai_transport_photo(mysqli $wa, int $company, string $instance, string $phone, int $conversation, int $incoming, array $photo): array {
    if ($company !== 1 || !preg_match('/^504[0-9]{8}$/', $phone) || $conversation <= 0 || $incoming <= 0) {
        throw new RuntimeException('Destino de fotografia no autorizado');
    }
    $cfg=require __DIR__.'/royal_ai_sales_auto_config.php';
    $agentId=0; $ownerName=''; $slot=''; $agentName='';
    foreach (($cfg['agents']??[]) as $id=>$a) {
        if ((int)$id<2 || (int)$id>30 || !is_array($a) || ($a['instance_id']??'')!==$instance) continue;
        $agentId=(int)$id; $ownerName=(string)($a['human_owner']??''); $agentName=(string)($a['name']??'');
        $slot=(string)($a['slot']??([2=>'instancia_4',3=>'instancia_3',4=>'instancia_2'][$agentId]??''));
        break;
    }
    if (!$agentId || $ownerName==='' || $agentName==='' || !preg_match('/^instancia_[0-9]+$/',$slot)) throw new RuntimeException('Instancia multiagente no autorizada');
    require_once __DIR__.'/royal_ai_sales_multi_control.php';
    if (empty(rai_agent_status($company,$agentId,$slot)['active'])) throw new RuntimeException('Estacion IA inactiva');

    if (($cfg['auto_enabled'] ?? false) !== true || ($cfg['send_enabled'] ?? false) !== true || ($cfg['agents'][$agentId]['send_enabled'] ?? false) !== true) {
        throw new RuntimeException('Fotografias desactivadas');
    }

    $q = $wa->prepare('SELECT token,proveedor,activa,nombre FROM wa_instancias WHERE empresa_id=? AND instance_id=? LIMIT 1');
    if (!$q) throw new RuntimeException('Instancia no disponible');
    $q->bind_param('is', $company, $instance);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$row || (int)$row['activa'] !== 1 || strcasecmp(trim((string)$row['nombre']), $ownerName) !== 0 || strtolower(trim((string)$row['proveedor'])) !== 'zapi' || trim((string)$row['token']) === '') {
        throw new RuntimeException('Instancia Z-API no autorizada');
    }

    $q = $wa->prepare("SELECT wa_lead_id,wa_contact_id FROM wa_messages WHERE empresa_id=? AND instance_id=? AND id=? AND wa_conversation_id=? AND direction='in' AND enviado_por=? LIMIT 1");
    if (!$q) throw new RuntimeException('Mensaje original no disponible');
    $q->bind_param('isiis', $company, $instance, $incoming, $conversation, $phone);
    $q->execute();
    $origin = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$origin) throw new RuntimeException('Mensaje de origen no corresponde');

    $q = $wa->prepare('SELECT asignado_a FROM wa_assignments WHERE empresa_id=? AND telefono=? ORDER BY id DESC LIMIT 1');
    if (!$q) throw new RuntimeException('No se pudo consultar asignacion');
    $q->bind_param('is', $company, $phone);
    $q->execute();
    $owner = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$owner || strcasecmp(trim((string)$owner['asignado_a']), $ownerName) !== 0) throw new RuntimeException('Asignacion cambiada');

    $secretPath = dirname(__DIR__, 2) . '/zapi_secrets/zapi_secrets.php';
    $shared = is_file($secretPath) ? require $secretPath : [];
    $privatePath = dirname(__DIR__, 2) . '/royal_private/royal_ai_sales_config.php';
    $private = is_file($privatePath) ? require $privatePath : [];
    $env = getenv('ROYAL_ZAPI_CLIENT_TOKEN');
    $client = trim((string)(($env !== false && trim($env) !== '') ? $env : ((is_array($shared) ? ($shared['client_token'] ?? '') : '') ?: (is_array($private) ? ($private['zapi_client_token'] ?? '') : ''))));
    if ($client === '') throw new RuntimeException('Falta Client-Token');

    $bridge = dirname(__DIR__) . '/royal_ai_media_bridge.php';
    if (!is_file($bridge)) throw new RuntimeException('Puente Royal Media no instalado');
    require_once $bridge;

    // Validar y construir la URL ANTES de enviar la foto al proveedor.
    $rutaMedia = ltrim(str_replace('\\', '/', (string)($photo['ruta_archivo'] ?? '')), '/');
    if ($rutaMedia === '' || !preg_match('#^royal_media_uploads/[A-Za-z0-9_./-]+$#', $rutaMedia) || str_contains($rutaMedia, '..')) {
        throw new RuntimeException('Ruta de fotografia no valida. Envio cancelado.');
    }
    $rootMedia = realpath(dirname(__DIR__) . '/royal_media_uploads');
    $archivoMedia = realpath(dirname(__DIR__) . '/' . $rutaMedia);
    $archivoValidado = realpath((string)($photo['ruta_local_validada'] ?? ''));
    if (!$rootMedia || !$archivoMedia || !$archivoValidado || !str_starts_with($archivoMedia, $rootMedia . DIRECTORY_SEPARATOR) || $archivoMedia !== $archivoValidado) {
        throw new RuntimeException('Fotografia fuera de Royal Media. Envio cancelado.');
    }
    // Royal Media sirve las fotos mediante su controlador autorizado, no desde uploads.
    $archivoId = (int)($photo['archivo_id'] ?? 0);
    if ($archivoId <= 0) {
        throw new RuntimeException('Fotografia sin identificador valido. Envio cancelado.');
    }
    $mediaUrl = 'https://royallprestigue.com/royal_media_archivo.php?id=' . $archivoId;
    $caption = !empty($photo['es_referencial']) ? 'Foto de referencia de mercaderia anterior. La nueva puede variar.' : 'Foto de la mercaderia disponible.';
    $payload = royal_ai_media_payload_zapi($photo, $phone, $caption);

    if (empty(rai_agent_status($company,$agentId,$slot)['active'])) throw new RuntimeException('Estacion detenida antes de enviar foto');
    $url = 'https://api.z-api.io/instances/' . rawurlencode($instance) . '/token/' . rawurlencode((string)$row['token']) . '/send-image';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Client-Token: ' . $client],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $providerId = is_array($json) ? (string)($json['messageId'] ?? $json['zaapId'] ?? '') : '';
    if ($err !== '' || $http < 200 || $http >= 300 || !is_array($json) || !empty($json['error']) || ($json['success'] ?? true) === false || $providerId === '') {
        return ['ok' => false, 'http' => $http, 'uncertain' => true];
    }

    $lead = (int)($origin['wa_lead_id'] ?? 0);
    $contact = (int)($origin['wa_contact_id'] ?? 0);
    $mime = (string)$photo['mime_type'];
    $meta = json_encode([
        'provider' => 'zapi',
        'provider_message_id' => $providerId,
        'royal_ai_sales' => true,
        'royal_media_archivo_id' => (int)$photo['archivo_id']
    ], JSON_UNESCAPED_UNICODE);
    $text = !empty($photo['es_referencial']) ? 'Foto de referencia de mercaderia anterior' : 'Foto de la mercaderia';
    $q = $wa->prepare("INSERT INTO wa_messages (empresa_id,wa_conversation_id,instance_id,wa_lead_id,wa_contact_id,meta_message_id,direction,tipo,texto,media_url,media_mime,media_sha256,enviado_por,nombre_mostrar,status_envio,raw_payload,event_time) VALUES (?,?,?,?,?,?,'out','image',?,?,?,'',?,?,'enviado',?,UTC_TIMESTAMP() - INTERVAL 6 HOUR)");
    if (!$q) throw new RuntimeException('Imagen aceptada por Z-API; registro local no disponible, NO REINTENTAR');
    $q->bind_param('iisiisssssss', $company, $conversation, $instance, $lead, $contact, $providerId, $text, $mediaUrl, $mime, $phone, $agentName, $meta);
    if (!$q->execute()) {
        $q->close();
        throw new RuntimeException('Imagen aceptada; registro local fallido, NO REINTENTAR');
    }
    $q->close();
    return ['ok' => true, 'http' => $http];
}
