<?php
require_once __DIR__.'/royal_media_core.php';
$id=(int)($_GET['id']??0);
$s=rm_stmt($conn,'SELECT a.ruta_archivo,a.mime_type,a.nombre_original FROM royal_media_archivos a JOIN royal_media_pacas p ON p.id=a.paca_id AND p.empresa_id=a.empresa_id WHERE a.id=? AND a.empresa_id=?','ii',[$id,$rm_empresa]);$a=$s->get_result()->fetch_assoc();$s->close();
if(!$a){http_response_code(404);exit;}
$root=realpath(__DIR__.'/royal_media_uploads');$path=realpath(__DIR__.'/'.(string)$a['ruta_archivo']);
if(!$root||!$path||strncmp($path,$root.DIRECTORY_SEPARATOR,strlen($root)+1)!==0||!is_file($path)){http_response_code(404);exit;}
header('Content-Type: '.$a['mime_type']);header('X-Content-Type-Options: nosniff');header('Content-Disposition: inline');header('Cache-Control: private, no-store');header('Content-Length: '.filesize($path));readfile($path);
