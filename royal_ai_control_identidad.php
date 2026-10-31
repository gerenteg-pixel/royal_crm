<?php
/** Fotografía original para revisión de fichas Carlos IA; no modifica datos. */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_permiso('ver_control_gestion');
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/empresa_core.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
$empresa = (int)empresa_id_actual();
$id = filter_input(INPUT_GET, 'ficha_id', FILTER_VALIDATE_INT);
if ($empresa < 1 || !$id) { http_response_code(404); exit; }
$q = $conn->prepare('SELECT foto_identidad FROM wa_fichas_venta WHERE id=? AND empresa_id=? AND fuente_venta=? LIMIT 1');
if (!$q) { http_response_code(500); exit; }
$fuente='WhatsApp Carlos IA';
$q->bind_param('iis', $id, $empresa, $fuente);
$q->execute();
$row=$q->get_result()->fetch_assoc();
$q->close();
$path=trim((string)($row['foto_identidad'] ?? ''));
if (!preg_match('~^whatsapp/identidades/empresa_' . $empresa . '/identidad_[A-Za-z0-9_.-]+\.(jpg|png|webp)$~', $path, $m)) { http_response_code(404); exit; }
$base=realpath(__DIR__ . '/whatsapp/identidades/empresa_' . $empresa);
$file=realpath(__DIR__ . '/' . $path);
if (!$base || !$file || dirname($file) !== $base || !is_file($file)) { http_response_code(404); exit; }
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) { http_response_code(415); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
readfile($file);
