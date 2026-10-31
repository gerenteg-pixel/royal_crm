<?php
declare(strict_types=1);
/** Royal AI - Centro de Operaciones. Control persistente de configuracion, sin ejecutor de canales. */
require_once __DIR__.'/royal_media_core.php';
if (!$rm_admin) { http_response_code(403); exit('Solo administracion'); }
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$base = dirname(__DIR__).'/royal_private/royal_ai_control';
$empresa = (int)$rm_empresa;
$nombreArchivo = $base.'/empresa_'.$empresa.'.json';
$lockArchivo = $base.'/empresa_'.$empresa.'.lock';
$fotoDir = $base.'/retratos_empresa_'.$empresa;
// Los retratos se entregan solo a administradores autenticados, nunca mediante URL publica.
if (isset($_GET['retrato'])) {
  $fotoId=(string)$_GET['retrato'];
  if (!preg_match('/^(director|asistente|agente_([1-9][0-9]*)|escena_agente_([1-9][0-9]*)|escena_director|escena_asistente)$/', $fotoId)) { http_response_code(400); exit; }
  $ruta=$fotoDir.'/'.$fotoId.'.png';
  if (!is_file($ruta)) { $ruta=__DIR__.'/royal_ai_assets/retratos/'.$fotoId.'.png'; if (!is_file($ruta)) { http_response_code(404); exit; } }
  header('Content-Type: image/png');
  header('Content-Disposition: inline; filename="'.basename($ruta).'"');
  header('X-Content-Type-Options: nosniff');
  readfile($ruta); exit;
}

$personas = ['Carlos','Maria','Andrea','David','Alexa','Dayanara','Yefri','Gabriel','Camila','Sebastian'];
function ra_foto_id($v): string {
  $id=(string)$v;
  if (!preg_match('/^(director|asistente|agente_([1-9][0-9]*)|escena_agente_([1-9][0-9]*)|escena_director|escena_asistente)$/',$id)) throw new RuntimeException('Identificador de retrato invalido');
  return $id;
}
function ra_save_photo(string $fotoDir, string $id): void {
  if (empty($_FILES['foto']) || !is_array($_FILES['foto'])) throw new RuntimeException('Selecciona una fotografia');
  $f=$_FILES['foto'];
  if (($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($f['size']??0)>5*1024*1024 || ($f['size']??0)<100) throw new RuntimeException('Imagen invalida o mayor de 5 MB');
  $tmp=(string)$f['tmp_name'];
  if (!is_uploaded_file($tmp)) throw new RuntimeException('Carga no valida');
  $info=@getimagesize($tmp);
  if (!$info || $info[0]<150 || $info[1]<150 || $info[0]>5000 || $info[1]>5000) throw new RuntimeException('Usa una fotografia entre 150 y 5000 pixeles');
  if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) throw new RuntimeException('El servidor necesita la extension PHP GD para convertir fotografias a PNG');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
  if (!in_array($mime,['image/png','image/jpeg','image/webp'],true)) throw new RuntimeException('Solo PNG, JPG o WEBP');
  $raw=file_get_contents($tmp);$im=$raw===false?false:@imagecreatefromstring($raw);
  if (!$im) throw new RuntimeException('No se pudo procesar la fotografia');
  if (!is_dir($fotoDir)&&!mkdir($fotoDir,0700,true)&&!is_dir($fotoDir)) {imagedestroy($im);throw new RuntimeException('No se pudo crear la carpeta de retratos');}
  $path=$fotoDir.'/'.$id.'.png';$temp=tempnam($fotoDir,'foto_');
  if (!$temp) {imagedestroy($im);throw new RuntimeException('No se pudo crear archivo temporal');}
  imagealphablending($im,true);imagesavealpha($im,true);
  $saved=imagepng($im,$temp,7);imagedestroy($im);
  if (!$saved || !rename($temp,$path)) {@unlink($temp);throw new RuntimeException('No se pudo guardar la fotografia');}
  chmod($path,0600);
}
function ra_default(int $empresa, array $nombres): array {
  $agents=[];
  for($i=0;$i<10;$i++) $agents[]=['id'=>$i+1,'nombre'=>$nombres[$i].' IA','instancia'=>($empresa===1 && $i>=4 && $i<=6 ? 'instancia_'.($i+2) : 'rr'),'canal'=>'mensajes','estado'=>'apagado','tarea'=>'Sin asignacion','foto'=>'','actualizado'=>date('c')];
  return ['version'=>1,'empresa_id'=>$empresa,'emergencia'=>false,'agentes'=>$agents,'ordenes'=>[],'auditoria'=>[],'actualizado'=>date('c')];
}
function ra_read(string $path, int $empresa, array $nombres): array {
  if(!is_file($path)) return ra_default($empresa,$nombres);
  $raw=file_get_contents($path); $data=$raw!==false?json_decode($raw,true):null;
  if(!is_array($data)||($data['empresa_id']??null)!==$empresa||!isset($data['agentes'])||!is_array($data['agentes'])) throw new RuntimeException('Configuracion invalida; no se modifico ningun dato.');
  // Compatibilidad con la version anterior: estaciones 05-07 usaban claves 06-08.
  // Solo ajustar esas asociaciones antiguas, sin cambiar otros agentes.
  $legacySlots=[5=>['instancia_6','instancia_5'],6=>['instancia_7','instancia_6'],7=>['instancia_8','instancia_7']];
  foreach ($data['agentes'] as &$agente) {
    $aid=(int)($agente['id']??0);
    if (isset($legacySlots[$aid]) && ($agente['instancia']??'')===$legacySlots[$aid][0]) {
      $agente['instancia']=$legacySlots[$aid][1];
    }
  }
  unset($agente);
  return $data;
}

function ra_write(string $path, array $data): void {
  $data['actualizado']=date('c');
  $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
  $tmp=tempnam(dirname($path),'rai_'); if($tmp===false) throw new RuntimeException('No se pudo crear archivo temporal');
  chmod($tmp,0600);
  if(file_put_contents($tmp,$json)===false||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('No se pudo guardar la configuracion');}
}
function ra_log(array &$data,string $actor,string $accion): void {
  array_unshift($data['auditoria'],['fecha'=>date('c'),'usuario'=>$actor,'accion'=>$accion]);
  $data['auditoria']=array_slice($data['auditoria'],0,150);
}
function ra_text($v,int $max=160): string { return mb_substr(trim((string)$v),0,$max,'UTF-8'); }
function ra_find(array $data,int $id): int {foreach($data['agentes'] as $i=>$a) if((int)$a['id']===$id)return $i;return -1;}
$actor=ra_text($_SESSION['usuario']??$_SESSION['username']??$_SESSION['nombre']??'Administrador',70);
$error='';$ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    rm_check_csrf();
    if(!is_dir($base)&&!mkdir($base,0700,true)&&!is_dir($base))throw new RuntimeException('No se puede crear la carpeta privada');
    $lock=fopen($lockArchivo,'c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('No se pudo bloquear el archivo de configuracion');
    try{
      $data=ra_read($nombreArchivo,$empresa,$personas);
      $action=(string)($_POST['action']??'');
      if($action==='upload_photo'){
         $id=ra_foto_id($_POST['foto_id']??'');
         if (str_starts_with($id,'escena_agente_') && ra_find($data,(int)substr($id,14))<0) throw new RuntimeException('Estacion inexistente');
         if (str_starts_with($id,'agente_') && ra_find($data,(int)substr($id,7))<0) throw new RuntimeException('Agente inexistente');
         ra_save_photo($fotoDir,$id);
         ra_log($data,$actor,'Retrato actualizado: '.$id);$ok='Fotografia PNG guardada correctamente';
       } elseif($action==='save_agent'){
        $id=(int)($_POST['id']??0);$idx=ra_find($data,$id);if($idx<0)throw new RuntimeException('Agente inexistente');
        $estado=(string)($_POST['estado']??'apagado');$canal=(string)($_POST['canal']??'mensajes');$inst=(string)($_POST['instancia']??'rr');
        if(!in_array($estado,['apagado','descanso','preparado'],true)||!in_array($canal,['mensajes','llamadas','ambos'],true)||!preg_match('/^(rr|instancia_[1-7]|todas)$/',$inst))throw new RuntimeException('Parametros no validos');
        if(!empty($data['emergencia'])&&$estado==='preparado')throw new RuntimeException('Parada de emergencia activa');
        $data['agentes'][$idx]['nombre']=ra_text($_POST['nombre']??'',70)?:$data['agentes'][$idx]['nombre'];
        $data['agentes'][$idx]['instancia']=$inst;$data['agentes'][$idx]['canal']=$canal;$data['agentes'][$idx]['estado']=$estado;
        $data['agentes'][$idx]['tarea']=$estado==='preparado'?'Preparado para activacion':($estado==='descanso'?'En descanso':'Desactivado');
        $data['agentes'][$idx]['actualizado']=date('c');
        ra_log($data,$actor,'Estacion '.$id.': '.$estado.', '.$canal.', '.$inst);$ok='Configuracion guardada para la estacion '.$id;
      } elseif($action==='all'){
        $estado=(string)($_POST['estado']??'');if(!in_array($estado,['apagado','descanso','preparado'],true))throw new RuntimeException('Estado invalido');
        if(!empty($data['emergencia'])&&$estado==='preparado')throw new RuntimeException('Parada de emergencia activa');
        $canal=(string)($_POST['canal']??'mensajes');$inst=(string)($_POST['instancia']??'rr');
        if(!in_array($canal,['mensajes','llamadas','ambos'],true)||!preg_match('/^(rr|instancia_[1-7]|todas)$/',$inst))throw new RuntimeException('Destino invalido');
        foreach($data['agentes'] as &$a){$a['estado']=$estado;$a['canal']=$canal;$a['instancia']=$inst;$a['tarea']=$estado==='preparado'?'Preparado para activacion':($estado==='descanso'?'En descanso':'Desactivado');$a['actualizado']=date('c');}unset($a);
        ra_log($data,$actor,'Configuracion masiva: '.$estado.', '.$canal.', '.$inst);$ok='Configuracion aplicada a todas las estaciones';
      } elseif($action==='activate_agent' || $action==='deactivate_agent'){
         if($empresa!==1 || !empty($data['emergencia'])) throw new RuntimeException('Activacion no permitida durante parada general');
         $id=(int)($_POST['id']??0);
         if(!in_array($id,[2,3,4,5,6,7],true)) throw new RuntimeException('Esta accion solo corresponde a las estaciones IA 02-07');
         $idx=ra_find($data,$id);
         if($idx<0) throw new RuntimeException('Estacion inexistente');
         $a=&$data['agentes'][$idx];
         $slots=[2=>'instancia_2',3=>'instancia_3',4=>'instancia_4',5=>'instancia_5',6=>'instancia_6',7=>'instancia_7'];
         if($action==='activate_agent'){
             if(($a['estado']??'')!=='preparado') throw new RuntimeException('Primero prepara la estacion');
             if(($a['canal']??'')!=='mensajes' || ($a['instancia']??'')!==$slots[$id]) throw new RuntimeException('Verifica canal Mensajes y la instancia asignada antes de activar');
             $a['estado']='activo';
             $a['tarea']='Autorizado para atender mensajes';
             $ok='Agente activado correctamente';
         } else {
             if(($a['estado']??'')!=='activo') throw new RuntimeException('El agente no esta activo');
             $a['estado']='preparado';
             $a['tarea']='Preparado, sin autorizacion de envio';
             $ok='Agente detenido; permanece preparado';
         }
         $a['actualizado']=date('c');
         unset($a);
         ra_log($data,$actor,'Estacion '.$id.': '.($action==='activate_agent'?'ACTIVADA':'DETENIDA'));
      } elseif($action==='pilot_carlos_start'){
         if($empresa!==1 || !empty($data['emergencia'])) throw new RuntimeException('Piloto no autorizado o parada general activa');
         $idx=ra_find($data,1); if($idx<0) throw new RuntimeException('Carlos IA no disponible');
         $data['agentes'][$idx]['estado']='preparado';$data['agentes'][$idx]['canal']='mensajes';$data['agentes'][$idx]['instancia']='instancia_1';
         $data['agentes'][$idx]['tarea']='Piloto Royal Chat Jefry12, activo hasta detencion manual';
         $now=time();$data['piloto_carlos']=['enabled'=>true,'started_at'=>$now,'expires_at'=>0];
         ra_log($data,$actor,'Inicio manual piloto Carlos Jefry12 sin vencimiento');$ok='Carlos IA activado sin limite de horas para Jefry12';
       } elseif($action==='pilot_carlos_stop'){
         $data['piloto_carlos']['enabled']=false;
         $idx=ra_find($data,1);if($idx>=0){$data['agentes'][$idx]['estado']='apagado';$data['agentes'][$idx]['tarea']='Piloto detenido';}
         ra_log($data,$actor,'Detencion manual Carlos IA');$ok='Piloto Carlos detenido';
      } elseif($action==='emergency'){
        $data['emergencia']=true;
         // La parada general tambien debe detener el piloto persistente.
         $data['piloto_carlos']['enabled']=false;
        foreach($data['agentes'] as &$a){$a['estado']='apagado';$a['tarea']='Detenido por administracion';$a['actualizado']=date('c');}unset($a);
        ra_log($data,$actor,'PARADA GENERAL');$ok='Parada general registrada';
      } elseif($action==='unlock'){
        $data['emergencia']=false;ra_log($data,$actor,'Parada general liberada; agentes siguen apagados');$ok='Parada liberada; estaciones permanecen apagadas';
      } elseif($action==='order'){
        $texto=ra_text($_POST['orden']??'',700);$target=(int)($_POST['destino']??0);
        if(mb_strlen($texto,'UTF-8')<5)throw new RuntimeException('Escribe una instruccion mas especifica');
        if($target!==0&&ra_find($data,$target)<0)throw new RuntimeException('Destino inexistente');
        $id=bin2hex(random_bytes(8));
        array_unshift($data['ordenes'],['id'=>$id,'fecha'=>date('c'),'director'=>$actor,'destino'=>$target,'texto'=>$texto,'estado'=>'pendiente_integracion','respuesta'=>'Orden registrada. No se ha enviado a un agente ejecutor.']);
        $data['ordenes']=array_slice($data['ordenes'],0,100);
        ra_log($data,$actor,'Orden para '.($target?:'todos').': '.$texto);$ok='Orden registrada en la bandeja del asistente';
      } elseif($action==='resolve_order'){
        $id=(string)($_POST['order_id']??'');$new=(string)($_POST['estado_orden']??'');
        if(!in_array($new,['cancelada','revisada'],true))throw new RuntimeException('Accion no valida');
        $found=false;foreach($data['ordenes'] as &$o)if(hash_equals((string)$o['id'],$id)){$o['estado']=$new;$o['respuesta']='Marcada como '.$new.' por '.$actor;$found=true;break;}unset($o);
        if(!$found)throw new RuntimeException('Orden no encontrada');ra_log($data,$actor,'Orden '.$id.' '.$new);$ok='Orden actualizada';
      }else throw new RuntimeException('Accion desconocida');
      ra_write($nombreArchivo,$data);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
  }catch(Throwable $e){error_log('Royal AI Control: '.$e->getMessage());$error=$e->getMessage();}
}
try{$data=ra_read($nombreArchivo,$empresa,$personas);}catch(Throwable $e){$data=ra_default($empresa,$personas);$error=$e->getMessage();}
$agents=$data['agentes'];
// Carlos conserva 'preparado' en el JSON: su worker original exige ese estado.
// 'activo' es solo la representacion del panel cuando el piloto realmente autoriza.
$p=$data['piloto_carlos']??[];
$pilotLive=$empresa===1 && empty($data['emergencia']) && is_array($p)
    && ($p['enabled']??false)===true && (int)($p['started_at']??0)>0
    && count(array_filter($agents,fn($a)=>(int)($a['id']??0)===1
        && ($a['estado']??'')==='preparado' && ($a['canal']??'')==='mensajes'
        && ($a['instancia']??'')==='instancia_1'))>0;
$displayAgents=$agents;
if($pilotLive){foreach($displayAgents as &$displayAgent){
    if((int)($displayAgent['id']??0)===1){$displayAgent['estado']='activo';break;}
}unset($displayAgent);}
$prepared=count(array_filter($displayAgents,fn($a)=>$a['estado']==='preparado'));
$activeAgents=count(array_filter($displayAgents,fn($a)=>$a['estado']==='activo'));
$rest=count(array_filter($agents,fn($a)=>$a['estado']==='descanso'));
$orders=count(array_filter($data['ordenes'],fn($o)=>$o['estado']==='pendiente_integracion'));
/** Personajes SVG de respaldo si falta un PNG de la estación. */
function ra_avatar(int $seed, bool $manager=false): string {
  $skins=['#d7a17e','#edbd98','#c98b68','#a86b4d','#e2ae8d','#b97858','#eac2a1','#c58b69','#d5a083','#efc6a7','#b77b59','#e7b292'];
  $hairs=['#241d1b','#51352c','#34231e','#201b1b','#704735','#28211e','#4e3026','#33211f','#623d31','#2a2021','#302320','#493026'];
  $female=in_array($seed,[1,2,4,6,8,11],true);
  $skin=$skins[$seed%count($skins)];$hair=$hairs[$seed%count($hairs)];
  $coat=$manager?'#192b39':'#151d26';
  $id='rpv'.$seed.($manager?'m':'a');
  $hairBack=$female?'<path d="M79 112 Q54 25 112 18 Q177 12 162 112 L171 190 Q148 207 136 174 L84 174 Q62 206 52 184Z" fill="'.$hair.'"/>':'';
  $hairFront=$female?'<path d="M70 94 Q62 25 112 25 Q162 23 157 94 Q151 66 137 57 Q112 76 76 72Z" fill="'.$hair.'"/>':'<path d="M69 91 Q59 40 96 29 Q139 15 157 53 L156 88 Q144 68 131 63 Q105 73 75 68Z" fill="'.$hair.'"/>';
  $beard=(!$female && $seed%3===0)?'<path d="M82 121 Q93 157 113 160 Q135 154 144 120 Q136 157 113 165 Q89 158 82 121" fill="'.$hair.'" opacity=".58"/>':'';
  $headset=$manager?'':'<path d="M65 111 Q53 38 111 37 Q170 37 158 111" stroke="#182936" stroke-width="10" fill="none"/><rect x="56" y="100" width="14" height="31" rx="6" fill="#273e50"/><rect x="153" y="100" width="14" height="31" rx="6" fill="#273e50"/><path d="M162 127 Q160 146 139 145" fill="none" stroke="#273e50" stroke-width="4"/><circle cx="139" cy="145" r="5" fill="#263d4a"/>';
  $shirt=$manager?'#f4f1eb':'#222d38';
  return '<svg class="rp-human" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 224 270" role="img" aria-label="Personaje vectorial Royal Prestige"><defs><linearGradient id="'.$id.'j" x1="0" x2="1" y1="0" y2="1"><stop stop-color="'.$coat.'"/><stop offset="1" stop-color="#070e17"/></linearGradient><linearGradient id="'.$id.'s" x1="0" x2="1"><stop stop-color="'.$skin.'"/><stop offset=".55" stop-color="'.$skin.'"/><stop offset="1" stop-color="#9d6d56"/></linearGradient></defs><ellipse cx="112" cy="259" rx="100" ry="9" fill="#050b10" opacity=".3"/>'.$hairBack.'<path d="M10 270 Q11 201 54 182 L88 171 L136 171 L171 183 Q213 202 215 270Z" fill="url(#'.$id.'j)"/><path d="M82 176 L111 222 L142 176 L129 167 L95 167Z" fill="'.$shirt.'"/><path d="M107 219 L103 270 L120 270 L115 219Z" fill="#c39a61"/><path d="M91 175 L111 222 L79 194 M132 175 L111 222 L146 194" fill="none" stroke="#667989" stroke-width="2"/><rect x="99" y="144" width="27" height="38" rx="10" fill="'.$skin.'"/><ellipse cx="112" cy="103" rx="47" ry="64" fill="url(#'.$id.'s)"/><ellipse cx="65" cy="111" rx="7" ry="14" fill="'.$skin.'"/><ellipse cx="159" cy="111" rx="7" ry="14" fill="'.$skin.'"/>'.$hairFront.'<path d="M81 102 Q92 96 101 102 M122 102 Q133 96 143 102" stroke="'.$hair.'" stroke-width="3" fill="none" stroke-linecap="round"/><ellipse cx="93" cy="108" rx="4" ry="5" fill="#222a30"/><ellipse cx="132" cy="108" rx="4" ry="5" fill="#222a30"/><circle cx="94" cy="106" r="1.3" fill="#fff"/><circle cx="133" cy="106" r="1.3" fill="#fff"/><path d="M112 110 L108 132 Q112 136 117 132" fill="none" stroke="#99664f" stroke-width="2"/><path d="M98 146 Q112 153 126 145" fill="none" stroke="#854d4a" stroke-width="2.5" stroke-linecap="round"/>'.$beard.$headset.'<path d="M25 246 Q48 227 70 230 M153 230 Q180 227 198 246" stroke="#8293a0" stroke-width="2" fill="none" opacity=".55"/></svg>';
}
function ra_person_visual(string $id, int $seed, bool $manager=false): string {
  // Personajes humanos recortados en una capa separada de la oficina.
  // Los PNG se sirven por la ruta autenticada ?retrato=; no se publica la carpeta privada.
  global $fotoDir;
  $id = ra_foto_id($id);
  $privado = $fotoDir.'/'.$id.'.png';
  $incluido = __DIR__.'/royal_ai_assets/retratos/'.$id.'.png';
  $ruta = is_file($privado) ? $privado : $incluido;
  if (!is_file($ruta)) return ra_avatar($seed, $manager);
  $url = '?retrato='.rawurlencode($id).'&amp;v='.(int)filemtime($ruta);
  return '<img class="rp-human ra-human-cutout" src="'.$url.'" alt="Personaje '.ra_e($id).'" loading="lazy" decoding="async">';
}
/** Dimensiones reales del archivo de escena, sin procesarlo ni alterar su calidad. */
function ra_scene_info(string $fotoDir, string $id): array {
  $id=ra_foto_id($id);
  $privado=$fotoDir.'/'.$id.'.png';
  $incluido=__DIR__.'/royal_ai_assets/retratos/'.$id.'.png';
  $ruta=is_file($privado)?$privado:$incluido;
  if(!is_file($ruta)) return ['existe'=>false,'ancho'=>0,'alto'=>0,'version'=>0];
  $dim=@getimagesize($ruta);
  return ['existe'=>true,'ancho'=>(int)($dim[0]??0),'alto'=>(int)($dim[1]??0),'version'=>(int)filemtime($ruta)];
}
function ra_scene_url(string $fotoDir, string $id): string {
  $info=ra_scene_info($fotoDir,$id);
  return '?retrato='.rawurlencode($id).'&amp;v='.$info['version'];
}
function ra_png_has_transparency(string $path): bool {
  if (!is_file($path) || !function_exists('imagecreatefrompng')) return false;
  $im=@imagecreatefrompng($path);
  if (!$im) return false;
  $w=imagesx($im);$h=imagesy($im);
  // Muestreo de bordes: no modifica archivos ni usa servicios externos.
  $coords=[[0,0],[$w-1,0],[0,$h-1],[$w-1,$h-1],[intdiv($w,2),0]];
  $alpha=false;
  foreach($coords as [$x,$y]) {
    $rgba=imagecolorat($im,$x,$y);
    if ((($rgba >> 24) & 0x7F)>8) {$alpha=true;break;}
  }
  imagedestroy($im);
  return $alpha;
}
function ra_e($v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
// Etiquetas administrativas basadas en las asociaciones verificadas de Royal AI Sales.
// No altera los identificadores guardados ni activa canales.
function ra_instance_labels(): array {
  return [
    'rr' => 'Route Roaming',
    'instancia_1' => 'Instancia Jefry12',
    'instancia_2' => 'Instancia Deybid08',
    'instancia_3' => 'Instancia Johan01',
    'instancia_4' => 'Instancia Sindy02',
    'instancia_5' => 'Instancia Alexa09',
    'instancia_6' => 'Instancia DayanaraRT',
    'instancia_7' => 'Instancia YefriC',
    'todas' => 'Todas las instancias',
  ];
}
function ra_instance(string $v): string { $labels=ra_instance_labels(); return $labels[$v] ?? 'Instancia no identificada'; }
$raInstLabels=ra_instance_labels();
include __DIR__.'/header.php';
?>
<style>
#rai{--line:#294960;--gold:#e4b977;--muted:#9db7ca;box-sizing:border-box;color:#e9f4ff;font-family:Inter,system-ui,Arial,sans-serif;background:#071727;padding:10px;border-radius:12px;height:calc(100dvh - 175px);min-height:0;display:flex;flex-direction:column;gap:8px;overflow:hidden}
#rai *{box-sizing:border-box}#rai h1,#rai h2,#rai p{margin:0}#rai button,#rai select,#rai input,#rai textarea{font:inherit}#rai button{cursor:pointer}#rai .top{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-shrink:0}#rai h1{font-size:clamp(16px,1.25vw,22px)}#rai .eyebrow{font-size:9px;color:var(--gold);letter-spacing:1.6px;font-weight:800}#rai .sub{font-size:10px;color:var(--muted)}#rai .actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}#rai .btn{border:1px solid #345875;background:#173a54;color:#fff;border-radius:7px;padding:8px 10px;font-size:11px;font-weight:650;white-space:nowrap}#rai .btn:hover{background:#22577a}#rai .btn.green{background:#106947;border-color:#298765}#rai .btn.red{background:#752d40;border-color:#a74454}#rai .metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;flex-shrink:0}#rai .metric{background:#0d273d;border:1px solid #27465e;border-radius:8px;padding:7px 10px;min-width:0}#rai .metric strong{font-size:19px;display:block;line-height:1.1}#rai .metric span{font-size:10px;color:var(--muted)}#rai .command{display:grid;grid-template-columns:minmax(0,1fr) minmax(240px,22%);gap:8px;flex:1;min-height:0}#rai .maincol{display:grid;grid-template-rows:minmax(110px,30%) minmax(0,1fr);gap:8px;min-height:0}#rai .panel{background:linear-gradient(135deg,#122d43,#0a1e31);border:1px solid #31536b;border-radius:10px;min-height:0;overflow:hidden}#rai .leaders{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:8px}#rai .leader{position:relative;display:flex;align-items:center;gap:13px;padding:10px 16px;border:1px solid #34546c;border-radius:9px;background:linear-gradient(115deg,#203e51,#0d2438);text-align:left;color:#fff;min-width:0;overflow:hidden}#rai .leader:before{content:'';position:absolute;inset:0 auto auto 0;width:100%;height:2px;background:var(--gold)}#rai .leader .face{width:clamp(55px,6vw,90px);height:clamp(55px,6vw,90px);border-radius:50%;background:#28475b;border:2px solid #b68e5d;display:grid;place-items:center;flex-shrink:0;font-size:clamp(29px,3.4vw,48px);overflow:hidden}#rai .leader .face img{width:100%;height:100%;object-fit:cover}#rai .leader b{font-size:clamp(13px,1.15vw,18px);display:block}#rai .leader small{color:#b7ccdc;font-size:10px}#rai .leader .role{font-size:9px;letter-spacing:1px;color:var(--gold);font-weight:750}#rai .agentarea{display:flex;flex-direction:column;padding:8px;gap:7px}#rai .area-title{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:12px;font-weight:750;flex-shrink:0}#rai .area-title small{color:var(--muted);font-size:9px;font-weight:400}#rai .agents{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));grid-template-rows:repeat(2,minmax(0,1fr));gap:7px;flex:1;min-height:0}#rai .agent{border:1px solid #34556c;background:linear-gradient(160deg,#234257,#0c2438);border-radius:8px;position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-width:0;min-height:0;padding:5px;color:#fff;overflow:hidden}#rai .agent:hover,#rai .agent.selected{border-color:#eac384;box-shadow:inset 0 0 0 1px #eac384}#rai .agent .num{position:absolute;top:5px;left:6px;font-size:9px;color:#a8c4d4}#rai .agent .status{position:absolute;right:5px;top:5px;border-radius:20px;background:#071b2b;border:1px solid #365b72;padding:3px 6px;font-size:9px;white-space:nowrap}#rai .agent.preparado .status{color:#eac46e}#rai .agent.activo .status{color:#6be0b1}#rai .agent.descanso .status{color:#eac46e}#rai .agent .portrait{width:clamp(45px,5vw,80px);height:clamp(45px,5vw,80px);border-radius:50%;display:grid;place-items:center;font-size:clamp(27px,3.2vw,46px);border:2px solid #5e7e90;background:#37566b;overflow:hidden;flex-shrink:1}#rai .agent .portrait img{width:100%;height:100%;object-fit:cover}#rai .agent b{font-size:clamp(10px,.9vw,14px);max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}#rai .agent small{font-size:9px;color:#aec6d5;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}#rai .agent .bubble{font-size:9px;border-radius:14px;border:1px solid #426a81;padding:3px 7px;background:#09243a;color:#b4cad7}#rai .side{display:flex;flex-direction:column;gap:8px;min-height:0}#rai .side .panel{padding:11px;display:flex;flex-direction:column;gap:9px}#rai .side h2{font-size:13px}#rai .side .selected-name{font-size:18px;font-weight:800;color:#f2d19a}#rai .side .hint{font-size:10px;color:var(--muted);line-height:1.5}#rai .tags{display:flex;gap:5px;flex-wrap:wrap}#rai .tag{border:1px solid #375c74;background:#0a253b;border-radius:6px;padding:5px 7px;font-size:10px}#rai .side .panel:first-child{flex:1}#rai .side .panel:last-child{flex-shrink:0}#rai .side .buttons{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:auto}#rai .footerline{font-size:10px;color:#aec5d6;display:flex;justify-content:space-between;gap:10px;flex-shrink:0}#rai .footerline .warn{color:#e8c68d}#rai dialog{color:#eaf5ff;border:1px solid #47708b;border-radius:12px;background:#0e263b;box-shadow:0 25px 100px #000b;width:min(520px,calc(100vw - 28px));max-height:min(85dvh,720px);overflow:auto;padding:20px}#rai dialog::backdrop{background:#020c18ba}#rai dialog h2{font-size:18px;margin-bottom:13px}#rai dialog label{display:block;font-size:11px;color:#b8d0df;margin:10px 0 4px}#rai dialog input,#rai dialog select,#rai dialog textarea{display:block;width:100%;border:1px solid #41627b;background:#06192b;color:#fff;border-radius:7px;padding:10px;font-size:12px}#rai dialog textarea{min-height:85px;resize:vertical}#rai dialog .formrow{display:grid;grid-template-columns:1fr 1fr;gap:9px}#rai dialog .modalbuttons{display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}#rai .toast{font-size:11px;padding:7px 10px;border:1px solid #557c6a;background:#123e35;border-radius:7px}#rai .toast.error{background:#4b2030;border-color:#9d4b5a}#rai .recent{display:flex;flex-direction:column;gap:6px;overflow:auto;max-height:240px}#rai .recent article{border:1px solid #35556a;padding:8px;border-radius:6px;font-size:11px}#rai .recent small{color:var(--muted)}
@media(max-height:720px){#rai{min-height:480px;gap:5px;padding:7px}#rai .metrics .metric{padding:5px 8px}#rai .metric strong{font-size:15px}#rai .maincol{grid-template-rows:minmax(90px,26%) minmax(0,1fr)}#rai .agent .portrait{width:43px;height:43px;font-size:28px}#rai .leader .face{width:55px;height:55px;font-size:30px}}
@media(max-width:1050px){#rai .command{grid-template-columns:minmax(0,1fr) 210px}#rai .leader{padding:8px}#rai .agent .portrait{width:48px;height:48px;font-size:29px}#rai .agent .bubble{display:none}}
@media(max-width:700px){#rai{height:calc(100dvh - 65px);min-height:540px}#rai .command{grid-template-columns:1fr}#rai .side{display:none}#rai .maincol{grid-template-rows:115px minmax(0,1fr)}#rai .agents{gap:4px}#rai .agent{padding:3px}#rai .agent .portrait{width:38px;height:38px;font-size:23px}#rai .agent .status{font-size:7px;padding:2px}#rai .agent b{font-size:10px}#rai .agent small{display:none}#rai .leader .face{width:46px;height:46px;font-size:26px}#rai .metrics{grid-template-columns:repeat(3,1fr)}#rai .metrics .metric:nth-child(n+4){display:none}#rai .actions .btn{font-size:9px;padding:6px}#rai .footerline{font-size:9px}}

/* Royal AI: una sola pantalla, oficina compacta y personas SVG si no hay retratos */
#rai{height:calc(100dvh - 170px);min-height:0;gap:5px;padding:7px}
#rai .top{min-height:27px}
#rai h1{font-size:clamp(14px,1.12vw,19px);line-height:1.12}
#rai .eyebrow{font-size:8px}
#rai .sub{font-size:9px}
#rai .btn{padding:5px 8px;font-size:10px}
#rai .metrics{gap:5px}
#rai .metric{padding:4px 8px}
#rai .metric strong{font-size:16px}
#rai .metric span{font-size:9px}
#rai .command{gap:6px;grid-template-columns:minmax(0,1fr) minmax(220px,21%)}
#rai .maincol{grid-template-rows:clamp(82px,19%,130px) minmax(0,1fr);gap:6px}
#rai .leaders{gap:6px;padding:5px}
#rai .leader{gap:10px;padding:6px 10px}
#rai .leader .face{width:clamp(50px,5.1vw,72px);height:clamp(50px,5.1vw,72px);border-radius:12px;background:linear-gradient(155deg,#315369,#14283a)}
#rai .leader .face .rp-human{width:100%;height:100%;display:block}
#rai .leader b{font-size:clamp(12px,1vw,16px)}
#rai .agentarea{padding:5px;gap:4px}
#rai .area-title{font-size:11px}
#rai .agents{gap:5px}
#rai .agent{gap:2px;padding:3px;justify-content:center}
#rai .agent .num{top:4px;left:5px;font-size:8px}
#rai .agent .status{top:3px;right:3px;padding:2px 5px;font-size:8px}
#rai .agent .portrait{width:clamp(58px,7.5vw,112px);height:clamp(62px,10vh,110px);max-height:58%;border-radius:9px 9px 3px 3px;border:0;background:transparent;overflow:hidden;flex-shrink:1}
#rai .agent .portrait .rp-human{height:100%;width:100%;display:block;filter:drop-shadow(0 3px 3px #0005)}
#rai .agent .portrait img{object-fit:contain;object-position:center bottom}
#rai .agent b{font-size:clamp(10px,.82vw,13px)}
#rai .agent small,#rai .agent .bubble{font-size:8px}
#rai .agent .bubble{padding:2px 6px}
#rai .side{gap:6px}
#rai .side .panel{padding:8px;gap:6px}
#rai .side .hint{font-size:9px;line-height:1.3}
#rai .side .selected-name{font-size:15px}
#rai .tag{padding:3px 5px;font-size:9px}
#rai .footerline{font-size:8px}
@media(max-height:820px){#rai{height:calc(100dvh - 158px)}#rai .maincol{grid-template-rows:85px minmax(0,1fr)}#rai .leader .face{width:53px;height:53px}#rai .agent .portrait{height:clamp(45px,9vh,78px)}#rai .agent .bubble{display:none}}
@media(max-height:650px){#rai{height:calc(100dvh - 145px)}#rai .metrics{display:none}#rai .maincol{grid-template-rows:65px minmax(0,1fr)}#rai .leader .face{width:43px;height:43px}#rai .leader small{display:none}#rai .agent .portrait{height:clamp(35px,8vh,62px)}#rai .side .panel:last-child{display:none}}
@media(max-width:700px){#rai{height:calc(100dvh - 115px)}#rai .command{grid-template-columns:1fr}#rai .maincol{grid-template-rows:75px minmax(0,1fr)}#rai .agent .portrait{width:100%;height:clamp(42px,9vh,70px)}#rai .leader .role{font-size:7px}#rai .leader b{font-size:10px}#rai .leader small{display:none}#rai .footerline{display:none}}
</style>
<main id="rai">
<div class="top"><div><div class="eyebrow">ROYAL PRESTIGE / INTELIGENCIA ARTIFICIAL</div><h1>ROYAL AI · Centro de Operaciones</h1><div class="sub">Dirección, asignaciones y supervisión comercial</div></div><div class="actions"><button class="btn" type="button" onclick="raOpen('turnos')">Turnos</button><button class="btn" type="button" onclick="raOpen('ordenes')">Órdenes</button><button class="btn red" type="button" onclick="raOpen('parada')">Control general</button></div></div>
<?php if($error||$ok):?><div class="toast <?=$error?'error':''?>"><?=ra_e($error?:$ok)?></div><?php endif;?>
<div class="metrics"><div class="metric"><strong><?=count($agents)?></strong><span>Agentes registrados</span></div><div class="metric"><strong><?=$prepared?> / <?=$activeAgents?></strong><span>Preparados / Activos</span></div><div class="metric"><strong><?=$rest?></strong><span>En descanso</span></div><div class="metric"><strong><?=$orders?></strong><span>Órdenes pendientes</span></div><div class="metric"><strong id="raChatCount">—</strong><span>Mensajes recientes por canal</span></div></div>
<div class="command"><div class="maincol"><section class="panel leaders"><button type="button" class="leader" onclick="raOpen('ordenes')"><span class="face"><?=ra_person_visual("director",10,true)?></span><span><span class="role">DIRECCIÓN GENERAL</span><b>Director IA</b><small>Define prioridades y emite instrucciones</small></span></button><button type="button" class="leader" onclick="raOpen('ordenes')"><span class="face"><?=ra_person_visual("asistente",11,true)?></span><span><span class="role">COORDINACIÓN</span><b>Asistente ejecutivo IA</b><small>Recibe órdenes para distribuir al equipo</small></span></button></section>
<section class="panel agentarea"><div class="area-title"><span>Equipo comercial · 10 estaciones</span><small>Selecciona un agente para configurarlo</small></div><div class="agents">
<?php foreach($displayAgents as $a): $id=(int)$a['id'];$st=(string)$a['estado'];$photo='agente_'.$id;$file=is_file($fotoDir.'/'.$photo.'.png')?$fotoDir.'/'.$photo.'.png':__DIR__.'/royal_ai_assets/retratos/'.$photo.'.png'; ?>
<button class="agent <?=ra_e($st)?>" id="raSpot<?=$id?>" type="button" onclick="raSelect(<?=$id?>)"><span class="num"><?=str_pad((string)$id,2,'0',STR_PAD_LEFT)?></span><span class="status"><?= $st==='activo'?'● Activo':($st==='preparado'?'◷ Preparado':($st==='descanso'?'◷ Descanso':'○ Apagado')) ?></span><span class="portrait"><?php if(is_file($file)):?><img src="?retrato=<?=ra_e($photo)?>&amp;v=<?=filemtime($file)?>" alt="" loading="lazy"><?php else:?><?=ra_avatar($id-1)?><?php endif;?></span><b><?=ra_e($a['nombre'])?></b><small><?=ra_e(ra_instance((string)$a['instancia']))?></small><span class="bubble" id="raActivity<?=$id?>">Sin datos Royal Chat</span></button>
<?php endforeach;?></div></section></div>
<aside class="side"><section class="panel"><h2>Estación seleccionada</h2><div class="selected-name" id="raSelectedName">Carlos IA</div><div class="hint" id="raSelectedMeta">Estación 01</div><div class="tags"><span class="tag" id="raTagChannel">Mensajes</span><span class="tag" id="raTagInst">Route Roaming</span><span class="tag" id="raTagState">Apagado</span></div><div class="hint">Preparado no autoriza respuestas. Activo autoriza al motor cuando su ejecutor está programado. La actividad del canal no acredita que este agente atendió al cliente. Llamadas, fichas y ventas siguen sin conexión.</div><form method="post" id="raActivationForm" style="display:none;gap:5px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="id" id="raActivationId" value="2"><button class="btn green" name="action" value="activate_agent" id="raActivateButton" onclick="return confirm('¿Activar este agente para atender clientes?')">Activar agente</button><button class="btn red" name="action" value="deactivate_agent" id="raDeactivateButton" onclick="return confirm('¿Detener este agente y dejarlo preparado?')">Detener agente</button></form><div class="buttons"><button type="button" class="btn green" onclick="raOpen('agente')">Configurar agente</button><button type="button" class="btn" onclick="raOpen('ordenes')">Dar instrucción</button></div></section><section class="panel"><h2>Estado del sistema</h2><div class="tags"><span class="tag" id="raChatHealth">Royal Chat · comprobando</span><span class="tag">Royal Phone · pendiente</span><span class="tag">Ventas · pendiente</span></div><div class="hint">La autorización de los agentes 2–7 se controla aquí; su ejecución requiere un worker compatible con cada instancia. La parada general no detiene sistemas externos.</div></section></aside></div>

<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:6px 10px;border:1px solid #31536b;border-radius:8px;background:#0d273d;font-size:11px">
<strong>Carlos IA · Jefry12</strong><span><?= $pilotLive?'Carlos IA activo permanentemente':'Carlos IA apagado' ?></span>
<?php if($empresa===1): ?><form method="post" style="margin-left:auto;display:flex;gap:6px"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><button class="btn green" name="action" value="pilot_carlos_start" onclick="return confirm('¿Activar Carlos IA para Jefry12 sin límite de horas hasta detenerlo manualmente?')">Activar Carlos IA</button><button class="btn red" name="action" value="pilot_carlos_stop">Detener Carlos</button></form><?php endif; ?>
</div>
<div class="footerline"><span>ROYAL PRESTIGE · Panel administrativo por empresa</span><span class="warn">Monitoreo Royal Chat (solo lectura) · actividad IA no verificada</span></div>
<dialog id="raModalAgent"><h2>Configurar estación</h2><form method="post"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="action" value="save_agent"><label>Agente</label><select name="id" id="raId" onchange="raLoad(this.value)"><?php foreach($agents as $a):?><option value="<?=(int)$a['id']?>"><?=ra_e($a['nombre'])?></option><?php endforeach;?></select><label>Nombre</label><input name="nombre" id="raName" maxlength="70" required><div class="formrow"><div><label>Instancia</label><select name="instancia" id="raInst"><option value="rr">Route Roaming</option><?php for($i=1;$i<=7;$i++):?><option value="instancia_<?=$i?>"><?=ra_e(ra_instance("instancia_".$i))?></option><?php endfor;?><option value="todas">Todas</option></select></div><div><label>Canal</label><select name="canal" id="raChannel"><option value="mensajes">Mensajes</option><option value="llamadas">Llamadas</option><option value="ambos">Ambos</option></select></div></div><label>Estado</label><select name="estado" id="raState"><option value="apagado">Apagado</option><option value="descanso">Descanso</option><option value="preparado">Preparado (sin activar)</option></select><div class="modalbuttons"><button class="btn" type="button" onclick="raClose('raModalAgent')">Cancelar</button><button class="btn green" type="submit">Guardar estación</button></div></form></dialog>
<dialog id="raModalTurnos"><h2>Asignación general de turnos</h2><p class="sub">Guarda la configuración para las diez estaciones. No inicia llamadas ni mensajes.</p><form method="post" onsubmit="return confirm('¿Aplicar esta configuración a todas las estaciones?')"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="action" value="all"><label>Estado</label><select name="estado"><option value="preparado">Preparados</option><option value="descanso">Descanso</option><option value="apagado">Apagados</option></select><label>Canal</label><select name="canal"><option value="mensajes">Mensajes</option><option value="llamadas">Llamadas</option><option value="ambos">Ambos</option></select><label>Instancia</label><select name="instancia"><option value="rr">Route Roaming</option><?php for($i=1;$i<=7;$i++):?><option value="instancia_<?=$i?>"><?=ra_e(ra_instance("instancia_".$i))?></option><?php endfor;?><option value="todas">Todas</option></select><div class="modalbuttons"><button type="button" class="btn" onclick="raClose('raModalTurnos')">Cancelar</button><button class="btn green">Guardar turnos</button></div></form></dialog>
<dialog id="raModalOrdenes"><h2>Dirección → Asistente ejecutivo</h2><p class="sub">Las instrucciones se registran en bandeja, pero todavía no se ejecutan automáticamente.</p><form method="post"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="action" value="order"><label>Destino</label><select name="destino"><option value="0">Todo el equipo</option><?php foreach($agents as $a):?><option value="<?=(int)$a['id']?>"><?=ra_e($a['nombre'])?></option><?php endforeach;?></select><label>Instrucción</label><textarea name="orden" maxlength="700" minlength="5" required placeholder="Ejemplo: Preparar al agente 03 para atender mensajes de la instancia 2."></textarea><div class="modalbuttons"><button type="button" class="btn" onclick="raClose('raModalOrdenes')">Cerrar</button><button class="btn green">Registrar orden</button></div></form><h2 style="margin-top:18px">Bandeja reciente</h2><div class="recent"><?php if(!$data['ordenes']):?><p class="sub">Sin instrucciones registradas.</p><?php endif;?><?php foreach(array_slice($data['ordenes'],0,6) as $o):?><article><strong><?=ra_e($o['texto'])?></strong><br><small><?=ra_e($o['fecha'])?> · <?=ra_e($o['estado'])?></small><?php if($o['estado']==='pendiente_integracion'):?><form method="post" style="display:flex;gap:5px;margin-top:6px"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="action" value="resolve_order"><input type="hidden" name="order_id" value="<?=ra_e($o['id'])?>"><button class="btn" name="estado_orden" value="revisada">Revisada</button><button class="btn red" name="estado_orden" value="cancelada">Cancelar</button></form><?php endif;?></article><?php endforeach;?></div></dialog>
<dialog id="raModalParada"><h2>Control general</h2><p class="sub">Esta parada desactiva la configuración de estaciones en este centro. No interrumpe Royal Chat, Royal Phone ni procesos externos.</p><?php if(empty($data['emergencia'])):?><form method="post" onsubmit="return confirm('¿Desactivar todas las estaciones configuradas?')"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="action" value="emergency"><div class="modalbuttons"><button type="button" class="btn" onclick="raClose('raModalParada')">Cancelar</button><button class="btn red">Parada de configuración</button></div></form><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=rm_csrf()?>"><input type="hidden" name="action" value="unlock"><div class="modalbuttons"><button type="button" class="btn" onclick="raClose('raModalParada')">Cancelar</button><button class="btn green">Liberar parada</button></div></form><?php endif;?></dialog>
</main>
<script>(function(){const instanceLabels=<?=json_encode($raInstLabels,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;const agents=<?=json_encode(array_values($displayAgents),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;let selected=agents[0]?.id||1;window.raLoad=function(id){const a=agents.find(x=>Number(x.id)===Number(id));if(!a)return;selected=a.id;const byId=x=>document.getElementById(x);byId('raId').value=a.id;byId('raName').value=a.nombre;byId('raInst').value=a.instancia;byId('raChannel').value=a.canal;byId('raState').value=a.estado==='activo'?'preparado':a.estado;const af=byId('raActivationForm');if(af){af.style.display=[2,3,4,5,6,7].includes(Number(a.id))?'flex':'none';byId('raActivationId').value=a.id;byId('raActivateButton').style.display=a.estado==='preparado'?'inline-block':'none';byId('raDeactivateButton').style.display=a.estado==='activo'?'inline-block':'none';}byId('raSelectedName').textContent=a.nombre;byId('raSelectedMeta').textContent='Estación '+String(a.id).padStart(2,'0');byId('raTagChannel').textContent=a.canal;byId('raTagInst').textContent=instanceLabels[a.instancia]||'Instancia no identificada';byId('raTagState').textContent=a.estado;document.querySelectorAll('#rai .agent').forEach(el=>el.classList.toggle('selected',el.id==='raSpot'+a.id));};window.raSelect=function(id){raLoad(id);if(window.innerWidth<=700)raOpen('agente');};window.raOpen=function(name){const ids={agente:'raModalAgent',turnos:'raModalTurnos',ordenes:'raModalOrdenes',parada:'raModalParada'};const el=document.getElementById(ids[name]);if(el&&!el.open)el.showModal();};window.raClose=function(id){document.getElementById(id)?.close();};raLoad(selected);})();</script>
<script>
/* Royal AI: monitor informativo de canales; no atribuye actividad a agentes IA. */
(function(){
  const agents = <?=json_encode(array_values($agents),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  const url = '../whatsapp/royal_ai_chat_actividad.php'; // Ajustar solo si la carpeta de Royal Chat tiene otro nombre.
  let active = false;
  async function refresh(){
    if(active || document.hidden) return;
    active=true;
    const health=document.getElementById('raChatHealth');
    try{
      const r=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
      const d=await r.json();
      if(!r.ok || !d.ok) throw new Error(d.error||'No disponible');
      const instances=d.instances||{};
      let sum=0;
      for(const [id,info] of Object.entries(instances)) sum+=Number(info.total||0);
      const count=document.getElementById('raChatCount');if(count) count.textContent=String(sum);
      for(const a of agents){
        const el=document.getElementById('raActivity'+a.id);if(!el)continue;
        if(a.canal==='llamadas'){el.textContent='Royal Chat no aplica';continue;}
        const keys=a.instancia==='todas'?Object.keys(instances):[a.instancia];
        let incoming=0,outgoing=0;
        for(const key of keys){const x=instances[key]||{};incoming+=Number(x.in||0);outgoing+=Number(x.out||0);}
        el.textContent=(incoming||outgoing)?`Canal 5 min: ${incoming} recib. · ${outgoing} env.`:'Canal: sin mensajes recientes';
        el.title='Actividad del canal, no del agente IA. Una instancia puede ser compartida.';
      }
      if(health)health.textContent='Royal Chat · lectura activa';
    }catch(e){
      if(health)health.textContent='Royal Chat · sin conexión';
      const count=document.getElementById('raChatCount');if(count)count.textContent='—';
      for(const a of agents){const el=document.getElementById('raActivity'+a.id);if(el)el.textContent='Sin datos verificados';}
    }finally{active=false;}
  }
  refresh();setInterval(refresh,30000);
})();
</script>
<?php include __DIR__.'/footer.php'; ?>
