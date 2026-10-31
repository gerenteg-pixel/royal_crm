<?php
declare(strict_types=1);
/** Integracion independiente: no modifica ficha, inventario, webhook ni asignaciones. */
function rai_preventa_turno(mysqli $crm,string $key,array $history,array $catalog,int $company,string $instance,string $phone,int $conversation): array {
    if ($key==='' || !$history || $conversation<1) return ['status'=>'no_solicitada'];
    $products=[];
    foreach ($catalog as $p) $products[]=['id'=>(int)$p['id'],'nombre'=>(string)$p['nombre'],'disponible'=>((int)$p['stock_registrado_no_confirmado']>0)];
    $instructions='Clasifica exclusivamente la solicitud de preventa del ULTIMO mensaje con contexto reciente. Devuelve SOLO JSON valido con claves producto_id (numero), consentimiento (booleano), pregunta_preventa (booleano). Consentimiento true SOLAMENTE si el cliente pide claramente que registremos su interes o acepta expresamente la propuesta inmediatamente anterior de que le avisemos cuando vuelva a haber mercaderia. Un si a fotos, consejos, precio o compra NO es consentimiento. producto_id solo cuando sea inequivoco en el historial reciente. No infieras productos. Catalogo: '.json_encode($products,JSON_UNESCAPED_UNICODE);
    $ch=curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['model'=>trim((string)getenv('ROYAL_AI_MODEL'))?:'gpt-4.1-mini','instructions'=>$instructions,'input'=>array_slice($history,-16),'max_output_tokens'=>150,'store'=>false],JSON_UNESCAPED_UNICODE),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25]);
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if (!is_string($raw)||$http<200||$http>=300) return ['status'=>'clasificacion_no_disponible'];
    $decoded=json_decode($raw,true);$text='';
    foreach (($decoded['output']??[]) as $b) foreach (($b['content']??[]) as $c) if (($c['type']??'')==='output_text') $text.=(string)($c['text']??'');
    $decision=json_decode(trim($text),true);
    if (!is_array($decision)) return ['status'=>'clasificacion_no_disponible'];
    $id=(int)($decision['producto_id']??0);$product=null;
    foreach ($products as $p) if ($p['id']===$id) {$product=$p;break;}
    if (!$product) return ['status'=>'no_solicitada'];
    if ($product['disponible']) return ['status'=>'stock_disponible'];
    if (($decision['consentimiento']??false)!==true) return ['status'=>'sin_stock','producto'=>$product['nombre']];
    require_once dirname(__DIR__).'/royal_ai_preventas_core.php';
    $r=rai_preventa_registrar($crm,$company,$instance,$phone,$conversation,$id,1);
    $r['producto']=$product['nombre'];
    return $r;
}
