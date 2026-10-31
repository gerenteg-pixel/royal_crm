<?php
declare(strict_types=1);
date_default_timezone_set('America/Tegucigalpa');
require_once __DIR__.'/auth.php';
require_once __DIR__.'/conexion.php';
require_once __DIR__.'/empresa_core.php';
require_login();
require_modulo('inventario_activo');
$rm_empresa = empresa_id_actual();
if ($rm_empresa <= 0) { http_response_code(403); exit('Empresa no válida'); }
$rm_rol = (string)($_SESSION['rol'] ?? '');
$rm_tipo = (string)($_SESSION['tipo_usuario'] ?? '');
$rm_admin = in_array($rm_rol, ['admin','superadmin'], true) || $rm_tipo === 'superadmin';
$rm_gestion = $rm_admin || in_array($rm_rol, ['gestion','gestión'], true);
if (!$rm_gestion) { http_response_code(403); exit('No autorizado'); }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['rm_csrf'])) $_SESSION['rm_csrf'] = bin2hex(random_bytes(32));
function rm_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function rm_csrf(): string { return rm_h($_SESSION['rm_csrf']); }
function rm_check_csrf(): void { if (!hash_equals((string)($_SESSION['rm_csrf']??''), (string)($_POST['csrf']??''))) { http_response_code(403); exit('Token de seguridad inválido'); } }
function rm_stmt(mysqli $db,string $sql,string $types='',array $params=[]): mysqli_stmt {
 $s=$db->prepare($sql); if (!$s) throw new RuntimeException('Error preparando consulta');
 if ($types) { $s->bind_param($types,...$params); }
 if (!$s->execute()) throw new RuntimeException('Error ejecutando consulta');
 return $s;
}
function rm_paca(mysqli $db,int $empresa,int $id): ?array {
 $s=rm_stmt($db,'SELECT p.*, l.codigo_lote, c.nombre AS producto_nombre FROM royal_media_pacas p LEFT JOIN inventario_lotes l ON l.id=p.lote_id AND l.empresa_id=p.empresa_id JOIN wa_catalogo_productos c ON c.id=p.producto_id AND c.empresa_id=p.empresa_id WHERE p.id=? AND p.empresa_id=? LIMIT 1','ii',[$id,$empresa]);
 return $s->get_result()->fetch_assoc() ?: null;
}
function rm_head(string $titulo): void { echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.rm_h($titulo).'</title><link rel="stylesheet" href="royal_media_estilo.css"></head><body><main class="wrap"><header><h1>'.rm_h($titulo).'</h1><nav><a href="royal_media_inventario.php">Inventario multimedia</a><a href="royal_media_guia.php">Guía</a><a href="control_gestion.php">Volver al CRM</a></nav></header>'; }
function rm_foot(): void { echo '</main></body></html>'; }
