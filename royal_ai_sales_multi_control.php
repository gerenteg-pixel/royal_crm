<?php
declare(strict_types=1);
/** Control adicional para Maria, Andrea y David; no reemplaza el control de Carlos. */
function rai_agent_status(int $company, int $agentId, string $expectedSlot): array {
    if ($company!==1 || ($agentId<2 || $agentId>30)) return ['active'=>false,'reason'=>'agente'];
    $path=dirname(__DIR__,2).'/royal_private/royal_ai_control/empresa_'.$company.'.json';
    if (!is_readable($path)) return ['active'=>false,'reason'=>'sin_configuracion'];
    $raw=file_get_contents($path);
    $data=is_string($raw)?json_decode($raw,true):null;
    if (!is_array($data) || (int)($data['empresa_id']??0)!==$company || !empty($data['emergencia'])) return ['active'=>false,'reason'=>'parada'];
    foreach (($data['agentes']??[]) as $agent) {
        if ((int)($agent['id']??0)!==$agentId) continue;
        if (!in_array(($agent['estado']??''), ['activo','preparado'], true) || ($agent['canal']??'')!=='mensajes' || ($agent['instancia']??'')!==$expectedSlot) return ['active'=>false,'reason'=>'estacion_no_activa'];
        $started=strtotime((string)($agent['actualizado']??''));
        return ['active'=>true,'started_at'=>$started?:1,'expires_at'=>0];
    }
    return ['active'=>false,'reason'=>'estacion_inexistente'];
}
