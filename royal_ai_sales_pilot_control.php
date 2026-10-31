<?php
declare(strict_types=1);
/** Control de Carlos IA: activacion manual sin vencimiento, solo piloto autorizado. */
function rai_pilot_status(int $company=1): array {
    if ($company !== 1) return ['active'=>false,'reason'=>'empresa'];
    $path=dirname(__DIR__,2).'/royal_private/royal_ai_control/empresa_'.$company.'.json';
    if (!is_file($path) || !is_readable($path)) return ['active'=>false,'reason'=>'sin_activacion'];
    $raw=file_get_contents($path);
    $d=is_string($raw)?json_decode($raw,true):null;
    if (!is_array($d) || !empty($d['emergencia']) || (int)($d['empresa_id']??0)!==$company) return ['active'=>false,'reason'=>'parada'];
    $pilot=$d['piloto_carlos']??[];
    // No depender de expires_at: permanece activo hasta apagado manual o emergencia.
    if (!is_array($pilot) || ($pilot['enabled']??false)!==true || (int)($pilot['started_at']??0)<=0) return ['active'=>false,'reason'=>'apagado'];
    foreach (($d['agentes']??[]) as $a) {
        if ((int)($a['id']??0)===1 && ($a['estado']??'')==='preparado' && ($a['canal']??'')==='mensajes' && ($a['instancia']??'')==='instancia_1') {
            return ['active'=>true,'started_at'=>(int)$pilot['started_at'],'expires_at'=>0];
        }
    }
    return ['active'=>false,'reason'=>'estacion_no_preparada'];
}
