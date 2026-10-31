<?php
declare(strict_types=1);
/** Solo lectura: fotografias historicas expresamente marcadas para IA. */
function rai_media_fotos_referenciales(mysqli $conn,int $empresaId,int $productoId,int $limite=3): array {
    if ($empresaId<1 || $productoId<1) throw new InvalidArgumentException('Producto invalido');
    $limite=max(1,min(5,$limite));
    $sql="SELECT a.id AS archivo_id,a.ruta_archivo,a.mime_type,p.id AS paca_id,p.codigo_paca,p.estado,c.nombre AS producto_nombre FROM royal_media_pacas p JOIN wa_catalogo_productos c ON c.id=p.producto_id AND c.empresa_id=p.empresa_id JOIN royal_media_archivos a ON a.paca_id=p.id AND a.empresa_id=p.empresa_id WHERE p.empresa_id=? AND p.producto_id=? AND p.foto_referencial_ia=1 AND a.tipo='foto' AND a.mime_type IN ('image/jpeg','image/png','image/webp') ORDER BY p.id DESC,a.id DESC LIMIT 150";
    $s=$conn->prepare($sql); if (!$s) throw new RuntimeException('Consulta de fotos historicas no disponible');
    $s->bind_param('ii',$empresaId,$productoId); $s->execute(); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
    $root=realpath(__DIR__.'/royal_media_uploads'); if (!$root) return [];
    $out=[];
    foreach ($rows as $r) {
        $path=realpath(__DIR__.'/'.(string)$r['ruta_archivo']);
        if (!$path || strpos($path,$root.DIRECTORY_SEPARATOR)!==0 || !is_file($path)) continue;
        $size=filesize($path); if ($size===false || $size<1 || $size>8*1024*1024) continue;
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime!==(string)$r['mime_type'] || !in_array($mime,['image/jpeg','image/png','image/webp'],true)) continue;
        $r['ruta_local_validada']=$path;
        $r['es_referencial']=true;
        $r['caption_obligatorio']='Fotografia referencial de mercaderia anterior. El contenido de las nuevas pacas puede variar; no corresponde a inventario disponible.';
        $out[]=$r;
        if (count($out)>=$limite) break;
    }
    return $out;
}
