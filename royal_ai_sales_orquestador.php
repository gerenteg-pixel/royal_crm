<?php
declare(strict_types=1);
/** Royal AI Sales: un cron, varios workers. No modifica los workers existentes. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
ini_set('display_errors', '0');
date_default_timezone_set('America/Tegucigalpa');

function royal_orq_log(string $event, array $extra=[]): void {
    echo json_encode(['at'=>date('c'),'event'=>$event]+$extra, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
}
function royal_orq_station(array $data, int $id, string $slot): bool {
    foreach (($data['agentes']??[]) as $a) {
        if ((int)($a['id']??0)!==$id) continue;
        return in_array(($a['estado']??''), ['activo','preparado'], true)
            && ($a['canal']??'')==='mensajes'
            && ($a['instancia']??'')===$slot;
    }
    return false;
}
function royal_orq_run(string $file, ?int $id, int $timeout): array {
    if (!function_exists('proc_open')) return ['status'=>'proc_open_no_disponible'];
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).($id===null?'':' '.escapeshellarg((string)$id));
    $pipes=[];
    $p=@proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
    if (!is_resource($p)) return ['status'=>'no_iniciado'];
    fclose($pipes[0]);
    stream_set_blocking($pipes[1],false);
    stream_set_blocking($pipes[2],false);
    $out=''; $err=''; $start=microtime(true); $timedOut=false;
    $lastExit=-1;
    while (true) {
        $out=substr($out.(string)stream_get_contents($pipes[1]),0,8192);
        $err=substr($err.(string)stream_get_contents($pipes[2]),0,2048);
        $status=proc_get_status($p);
        if (!$status['running']) { $lastExit=(int)$status['exitcode']; break; }
        if (microtime(true)-$start>=$timeout) { $timedOut=true; @proc_terminate($p); usleep(250000); $st=proc_get_status($p); if ($st['running']) @proc_terminate($p,9); break; }
        usleep(150000);
    }
    fclose($pipes[1]); fclose($pipes[2]);
    $close=@proc_close($p);
    $code=$lastExit>=0?$lastExit:$close;
    $json=json_decode(trim($out),true);
    // Never log customer content, AI drafts, or raw exceptions.
    return ['status'=>$timedOut?'timeout':($code===0?'ok':'error'),
        'mode'=>is_array($json)?(string)($json['mode']??'sin_modo'):'sin_json',
        'sends'=>is_array($json)?(int)($json['sends']??0):0,
        'seconds'=>round(microtime(true)-$start,2)];
}

// Diagnostico aislado: no consulta clientes ni ejecuta workers.
if (in_array('--diagnostico', $argv ?? [], true)) {
    $available=function_exists('proc_open') && function_exists('proc_close') && function_exists('proc_get_status');
    if (!$available) {
        royal_orq_log('diagnostico', ['ok'=>false,'motivo'=>'procesos_php_no_disponibles','sends'=>0]);
        exit(1);
    }
    // Ejecutar solamente un PHP trivial para confirmar que el hosting permite hijos.
    $pipes=[];
    $command=escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('echo "ROYAL_ORQ_OK";');
    $child=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
    if (!is_resource($child)) {
        royal_orq_log('diagnostico', ['ok'=>false,'motivo'=>'no_se_pudo_iniciar_php_hijo','sends'=>0]);
        exit(1);
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1],3);
    $response=stream_get_contents($pipes[1],32);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode=proc_close($child);
    $ok=($exitCode===0 && trim((string)$response)==='ROYAL_ORQ_OK');
    royal_orq_log('diagnostico', ['ok'=>$ok,'php_hijo'=>$ok?'disponible':'no_confirmado','sends'=>0]);
    exit($ok?0:1);
}

$cfgPath=__DIR__.'/royal_ai_sales_auto_config.php';
if (!is_file($cfgPath)) { royal_orq_log('config_faltante'); exit(1); }
$cfg=require $cfgPath;
if (!is_array($cfg) || (int)($cfg['company_id']??0)!==1) { royal_orq_log('config_invalida'); exit(1); }
$company=1;
$private=dirname(__DIR__,2).'/royal_private/royal_ai_control';
if (!is_dir($private) || !is_readable($private)) { royal_orq_log('control_no_disponible'); exit(1); }
$lock=@fopen($private.'/orquestador_empresa_'.$company.'.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { royal_orq_log('ejecucion_anterior_activa'); exit(0); }
try {
    $controlFile=$private.'/empresa_'.$company.'.json';
    $raw=@file_get_contents($controlFile);
    $control=is_string($raw)?json_decode($raw,true):null;
    if (!is_array($control) || (int)($control['empresa_id']??0)!==$company || !empty($control['emergencia'])) {
        royal_orq_log('parada_general_o_control_invalido'); exit(0);
    }
    if (($cfg['auto_enabled']??false)!==true || ($cfg['send_enabled']??false)!==true) {
        royal_orq_log('motor_global_desactivado'); exit(0);
    }
    $deadline=microtime(true)+52;
    $proactiveEnabled=($cfg['proactive_enabled']??false)===true;
    // Carlos primero, con su worker y piloto existentes, sin alterar su logica.
    $pilot=$control['piloto_carlos']??[];
    if (is_array($pilot) && ($pilot['enabled']??false)===true && (int)($pilot['started_at']??0)>0
        && royal_orq_station($control,1,'instancia_1')
        && ($cfg['agents'][1]['auto_enabled']??false)===true && ($cfg['agents'][1]['send_enabled']??false)===true) {
        $file=__DIR__.'/royal_ai_sales_auto_worker.php';
        royal_orq_log('agente_finalizado',['agent_id'=>1]+(is_file($file)?royal_orq_run($file,null,15):['status'=>'worker_faltante']));
        if ($proactiveEnabled && microtime(true)<$deadline-5) {
            $pro=__DIR__.'/royal_ai_sales_proactivo.php';
            if (is_file($pro)) royal_orq_log('seguimiento_finalizado',['agent_id'=>1]+royal_orq_run($pro,null,min(8,max(3,(int)($deadline-microtime(true))))));
        }
    }
    // Las estaciones 2-4 conservan su mapeo. Las adicionales se configuran por agente.
    $legacySlots=[2=>'instancia_4',3=>'instancia_3',4=>'instancia_2'];
    $slots=[];
    foreach (($cfg['agents']??[]) as $configuredId=>$configuredAgent) {
        $configuredId=(int)$configuredId;
        if ($configuredId<2 || $configuredId>30 || !is_array($configuredAgent)) continue;
        $slot=(string)($configuredAgent['slot']??($legacySlots[$configuredId]??''));
        if (!preg_match('/^instancia_[0-9]+$/',$slot) || empty($configuredAgent['human_owner'])) continue;
        $slots[$configuredId]=$slot;
    }
    ksort($slots);
    $file=__DIR__.'/royal_ai_sales_multi_worker.php';
    if (!is_file($file)) { royal_orq_log('multi_worker_faltante'); exit(1); }
    // Turnos rotativos para que un agente lento no monopolice el minuto.
    $cursorPath=$private.'/orquestador_turno_'.$company.'.txt';
    $last=(int)trim((string)@file_get_contents($cursorPath));
    $ids=array_keys($slots); $start=array_search($last,$ids,true);
    if ($start===false) $start=-1;
    $order=[];
    for ($i=1;$i<=count($ids);$i++) $order[]=$ids[($start+$i)%count($ids)];
    foreach ($order as $id) {
        if (microtime(true)>=$deadline) break;
        $agent=$cfg['agents'][$id]??[];
        if (($agent['auto_enabled']??false)!==true || ($agent['send_enabled']??false)!==true
            || !royal_orq_station($control,$id,$slots[$id])) continue;
        // Refrescar parada general justo antes de cada worker.
        $freshRaw=@file_get_contents($controlFile);
        $fresh=is_string($freshRaw)?json_decode($freshRaw,true):null;
        if (!is_array($fresh) || !empty($fresh['emergencia']) || !royal_orq_station($fresh,$id,$slots[$id])) break;
        $remaining=(int)floor($deadline-microtime(true));
        if ($remaining<5) break;
        royal_orq_log('agente_finalizado',['agent_id'=>$id]+royal_orq_run($file,$id,min(12,$remaining)));
        @file_put_contents($cursorPath,(string)$id,LOCK_EX);
        if ($proactiveEnabled && microtime(true)<$deadline-4) {
            $pro=__DIR__.'/royal_ai_sales_multi_proactivo.php';
            if (is_file($pro)) royal_orq_log('seguimiento_finalizado',['agent_id'=>$id]+royal_orq_run($pro,$id,min(5,max(3,(int)($deadline-microtime(true))))));
        }
    }
} finally {
    flock($lock,LOCK_UN); fclose($lock);
}
