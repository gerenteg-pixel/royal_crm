<?php
declare(strict_types=1);
require_once __DIR__.'/royal_media_core.php';

function rmm_json(array $data, int $status=200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Solo utiliza datos comerciales generales ya autorizados en el Catalogo Maestro.
// No copia permisos de referencia historica ni promociones individuales.
function rmm_preparar_ia(mysqli $db,int $empresa,int $id): array {
    $s=rm_stmt($db,'SELECT p.id,p.producto_id,p.estado,p.lista_ia,p.descripcion,p.caracteristicas_ia,p.condicion_ia,p.observaciones_ia,c.stock_actual,m.descripcion_comercial,m.caracteristicas_comerciales,m.condicion_mercaderia,m.instrucciones_venta,m.restricciones_ia FROM royal_media_pacas p JOIN wa_catalogo_productos c ON c.id=p.producto_id AND c.empresa_id=p.empresa_id LEFT JOIN royal_media_catalogo_maestro m ON m.producto_id=p.producto_id AND m.empresa_id=p.empresa_id WHERE p.id=? AND p.empresa_id=? FOR UPDATE','ii',[$id,$empresa]);
    $p=$s->get_result()->fetch_assoc();$s->close();
    if (!$p) throw new RuntimeException('Paca no encontrada.');
    if ((int)$p['lista_ia']===1) return ['activada'=>true,'detalle'=>'Ya estaba habilitada para IA'];
    if ($p['estado']!=='disponible' || (int)$p['stock_actual']<1) return ['activada'=>false,'detalle'=>'Pendiente: estado o stock oficial no valido'];
    $s=rm_stmt($db,"SELECT COUNT(*) AS total FROM royal_media_archivos WHERE empresa_id=? AND paca_id=? AND tipo='foto'",'ii',[$empresa,$id]);
    $fotos=(int)($s->get_result()->fetch_assoc()['total']??0);$s->close();
    if ($fotos<1) return ['activada'=>false,'detalle'=>'Pendiente: falta fotografia'];
    $descripcion=trim((string)$p['descripcion']);
    $caracteristicas=trim((string)$p['caracteristicas_ia']);
    $condicion=trim((string)$p['condicion_ia']);
    $observaciones=trim((string)$p['observaciones_ia']);
    if ($descripcion==='') $descripcion=mb_substr(trim((string)($p['descripcion_comercial']??'')),0,10000);
    if ($caracteristicas==='') {
        $base=trim((string)($p['caracteristicas_comerciales']??''));
        if ($base!=='') $caracteristicas=mb_substr('Caracteristicas generales de esta categoria, no verificadas individualmente en esta paca: '.$base,0,5000);
    }
    if ($condicion==='') {
        $base=trim((string)($p['condicion_mercaderia']??''));
        if ($base!=='') $condicion=mb_substr('Condicion general de categoria; sin revision individual',0,100);
    }
    if ($observaciones==='') {
        $base=trim((string)($p['instrucciones_venta']??''));
        $restricciones=trim((string)($p['restricciones_ia']??''));
        $observaciones=mb_substr(trim($base.($restricciones!==''?"\nRestricciones IA: ".$restricciones:'')),0,5000);
    }
    if ($descripcion==='' || $caracteristicas==='' || $condicion==='') return ['activada'=>false,'detalle'=>'Pendiente: Catalogo Maestro incompleto; no se habilito IA'];
    $s=rm_stmt($db,'UPDATE royal_media_pacas SET descripcion=?,caracteristicas_ia=?,condicion_ia=?,observaciones_ia=?,lista_ia=1 WHERE id=? AND empresa_id=? AND lista_ia=0','ssssii',[$descripcion,$caracteristicas,$condicion,$observaciones,$id,$empresa]);$s->close();
    $s=rm_stmt($db,"INSERT INTO royal_media_eventos (empresa_id,paca_id,evento,descripcion,realizado_por) VALUES (?,?,'preparacion_ia',?,?)",'iiss',[$empresa,$id,'Informacion comercial aplicada y lista para IA tras validar fotografia y stock',(string)($_SESSION['usuario']??'crm')]);$s->close();
    return ['activada'=>true,'detalle'=>'Informacion comercial aplicada; lista para IA'];
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        rm_check_csrf();
        $accion=(string)($_POST['accion']??'');
        if ($accion==='preparar_existentes') {
            if (!$rm_admin) throw new RuntimeException('Solo administracion puede preparar las fichas existentes.');
            $producto=(int)($_POST['producto_id']??0);
            if ($producto<1) throw new RuntimeException('Selecciona una categoria.');
            $s=rm_stmt($conn,"SELECT p.id FROM royal_media_pacas p WHERE p.empresa_id=? AND p.producto_id=? AND p.estado='disponible' AND p.lista_ia=0 AND EXISTS (SELECT 1 FROM royal_media_archivos a WHERE a.empresa_id=p.empresa_id AND a.paca_id=p.id AND a.tipo='foto') ORDER BY p.id ASC LIMIT 200",'ii',[$rm_empresa,$producto]);
            $ids=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'id');$s->close();
            $listos=0;$pendientes=0;$fallidos=0;
            foreach ($ids as $pid) {
                try { $conn->begin_transaction();$res=rmm_preparar_ia($conn,$rm_empresa,(int)$pid);$conn->commit();if($res['activada'])$listos++;else $pendientes++; }
                catch(Throwable $e){$conn->rollback();$fallidos++;error_log('Royal Media preparar existente '.$pid.': '.$e->getMessage());}
            }
            rmm_json(['ok'=>true,'mensaje'=>"Preparadas: $listos. Pendientes: $pendientes. Errores: $fallidos."]);
        }
        if ($accion!=='subir_foto') throw new RuntimeException('Accion no valida.');
        $id=(int)($_POST['paca_id']??0);
        if ($id<1) throw new RuntimeException('Paca inválida.');
        $f=$_FILES['archivo']??null;
        if (!is_array($f) || (int)($f['error']??-1)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name']??''))) throw new RuntimeException('No se recibió la fotografía. Comprueba los límites de PHP.');
        $size=(int)($f['size']??0);
        if ($size<1 || $size>50*1024*1024) throw new RuntimeException('La fotografía debe pesar entre 1 byte y 50 MB.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($allowed[$mime])) throw new RuntimeException('Solo se permiten imágenes JPG, PNG o WEBP.');
        $relative='royal_media_uploads/'.$rm_empresa.'/'.$id;
        $base=__DIR__.'/'.$relative;
        $path=null;
        $conn->begin_transaction();
        try {
            $s=rm_stmt($conn,'SELECT id,estado FROM royal_media_pacas WHERE id=? AND empresa_id=? FOR UPDATE','ii',[$id,$rm_empresa]);
            $p=$s->get_result()->fetch_assoc(); $s->close();
            if (!$p) throw new RuntimeException('La paca ya no existe.');
            if ($p['estado']!=='disponible') throw new RuntimeException('Solo se admiten pacas disponibles.');
            $s=rm_stmt($conn,"SELECT COUNT(*) AS total FROM royal_media_archivos WHERE empresa_id=? AND paca_id=? AND tipo='foto'",'ii',[$rm_empresa,$id]);
            $total=(int)($s->get_result()->fetch_assoc()['total']??0);$s->close();
            if ($total>0) throw new RuntimeException('Esta paca ya tiene fotografía; no se reemplazó ni duplicó.');
            if (!is_dir($base) && !mkdir($base,0750,true) && !is_dir($base)) throw new RuntimeException('No se pudo crear la carpeta.');
            $name=bin2hex(random_bytes(16)).'.'.$allowed[$mime];
            $path=$base.'/'.$name;
            if (!move_uploaded_file($f['tmp_name'],$path)) throw new RuntimeException('No se pudo guardar la fotografía.');
            @chmod($path,0640);
            $original=mb_substr(basename(str_replace('\\','/',(string)($f['name']??'foto'))),0,255);
            $usuario=(string)($_SESSION['usuario']??'crm');
            $s=rm_stmt($conn,'INSERT INTO royal_media_archivos (empresa_id,paca_id,tipo,ruta_archivo,nombre_original,mime_type,tamano_bytes,creado_por) VALUES (?,?,?,?,?,?,?,?)','iissssis',[$rm_empresa,$id,'foto',$relative.'/'.$name,$original,$mime,$size,$usuario]);$s->close();
            $s=rm_stmt($conn,"INSERT INTO royal_media_eventos (empresa_id,paca_id,evento,descripcion,realizado_por) VALUES (?,?,'archivo_agregado',?,?)",'iiss',[$rm_empresa,$id,'foto agregada mediante carga masiva',$usuario]);$s->close();
            $ia=rmm_preparar_ia($conn,$rm_empresa,$id);
            $conn->commit();
            rmm_json(['ok'=>true,'paca_id'=>$id,'mensaje'=>'Fotografia guardada. QR conservado. '.$ia['detalle'],'lista_ia'=>$ia['activada']]);
        } catch(Throwable $e) {
            $conn->rollback();
            if ($path && is_file($path)) @unlink($path);
            throw $e;
        }
    } catch(Throwable $e) {rmm_json(['ok'=>false,'mensaje'=>$e->getMessage()],400);}
}

$s=rm_stmt($conn,"SELECT c.id,c.nombre,COUNT(p.id) AS pendientes FROM wa_catalogo_productos c JOIN royal_media_pacas p ON p.producto_id=c.id AND p.empresa_id=c.empresa_id AND p.estado='disponible' WHERE c.empresa_id=? AND NOT EXISTS (SELECT 1 FROM royal_media_archivos a WHERE a.empresa_id=p.empresa_id AND a.paca_id=p.id AND a.tipo='foto') GROUP BY c.id,c.nombre ORDER BY c.nombre",'i',[$rm_empresa]);
$productos=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
$s=rm_stmt($conn,"SELECT p.id,p.producto_id,p.codigo_paca,p.created_at FROM royal_media_pacas p WHERE p.empresa_id=? AND p.estado='disponible' AND NOT EXISTS (SELECT 1 FROM royal_media_archivos a WHERE a.empresa_id=p.empresa_id AND a.paca_id=p.id AND a.tipo='foto') ORDER BY p.id ASC",'i',[$rm_empresa]);
$pacas=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
$s=rm_stmt($conn,'SELECT id,nombre FROM wa_catalogo_productos WHERE empresa_id=? ORDER BY nombre','i',[$rm_empresa]);
$categorias=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
include __DIR__.'/header.php';
?>
<style>
.rmm{background:linear-gradient(145deg,#07101f,#10233e);color:#f6faff;padding:24px;border-radius:20px;min-height:65vh}.rmm *{box-sizing:border-box}.rmm h1{color:#fff}.rmm p,.rmm label{color:#b8cce8}.rmm .panel{background:#0d1b31;border:1px solid #29466e;border-radius:14px;padding:18px;margin:15px 0}.rmm select,.rmm input[type=file]{background:#09172d;color:#fff;border:1px solid #47658e;border-radius:9px;padding:11px;max-width:100%;width:100%;margin:7px 0 12px}.rmm button,.rmm .btn{background:#2764e7;color:#fff;border:0;border-radius:9px;padding:12px 16px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}.rmm button:disabled{opacity:.45;cursor:not-allowed}.rmm .items{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}.rmm .item{background:#09172d;border:1px solid #29466e;border-radius:12px;padding:12px;min-width:0}.rmm .item img{width:100%;height:175px;object-fit:contain;background:#020814;border-radius:8px}.rmm .item small{color:#a8bfdf;overflow-wrap:anywhere}.rmm .status{margin-top:8px;font-size:13px}.rmm .good{color:#71f0b4}.rmm .bad{color:#ff9a9a}.rmm .note{color:#f6d889}.rmm progress{width:100%;height:18px}
</style>
<div class="rmm">
<h1>Carga masiva de fotografías · Royal Media</h1>
<p>Asocia una fotografía a cada paca ya identificada. Se conserva el QR original y no se modifica el inventario oficial; se completa la ficha comercial y se habilita IA solo si pasa las validaciones.</p>
<a class="btn" href="royal_media_inventario.php">Volver a Royal Media Inventory</a>
<div class="panel">
<label for="producto">1. Selecciona el producto</label>
<select id="producto"><option value="">Selecciona un producto</option><?php foreach($productos as $pr):?><option value="<?=(int)$pr['id']?>"><?=rm_h($pr['nombre'])?> · <?=(int)$pr['pendientes']?> pacas sin foto</option><?php endforeach;?></select>
<label for="fotos">2. Selecciona todas las fotografías de ese producto</label>
<input id="fotos" type="file" accept="image/jpeg,image/png,image/webp" multiple>
<p class="note">3. Verifica la correspondencia de cada fotografía con su QR. Puedes cambiar manualmente la paca asignada a cada imagen antes de guardar. Solo se muestran pacas sin fotografía.</p>
<p id="resumen"></p>
<button id="enviar" type="button" disabled>Confirmar y subir fotografías</button>
<progress id="progreso" max="100" value="0" hidden></progress>
<p id="resultado" aria-live="polite"></p>
</div>
<?php if($rm_admin): ?>
<div class="panel"><h2>Preparar pacas con fotografias ya cargadas</h2>
<p>Para las 22 pacas crudas y otras cargas anteriores. Solo completa campos vacios y habilita IA si se cumplen los requisitos. No altera QR, fotos, stock ni referencias historicas.</p>
<label for="existente">Producto con fotos ya guardadas</label>
<select id="existente"><option value="">Selecciona un producto</option><?php foreach($categorias as $c):?><option value="<?=(int)$c['id']?>"><?=rm_h($c['nombre'])?></option><?php endforeach;?></select>
<button id="preparar" type="button">Preparar fichas existentes</button><p id="preparar_resultado" aria-live="polite"></p></div>
<?php endif; ?>
<div id="vista" class="items"></div>
</div>
<script>
(function(){
'use strict';
const todas=<?=json_encode($pacas,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const csrf=<?=json_encode((string)($_SESSION['rm_csrf']??''),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const producto=document.getElementById('producto'),fotos=document.getElementById('fotos'),vista=document.getElementById('vista'),resumen=document.getElementById('resumen'),enviar=document.getElementById('enviar'),resultado=document.getElementById('resultado'),progreso=document.getElementById('progreso');
let archivos=[],ocupado=false,urls=[];
function limpiar(){urls.forEach(u=>URL.revokeObjectURL(u));urls=[];vista.replaceChildren();archivos=[];enviar.disabled=true;resumen.textContent='';resultado.textContent='';progreso.hidden=true;progreso.value=0;}
function disponibles(){return todas.filter(p=>String(p.producto_id)===producto.value);}
producto.addEventListener('change',()=>{if(ocupado)return;fotos.value='';limpiar();});
fotos.addEventListener('change',()=>{
 if(ocupado)return;limpiar();archivos=Array.from(fotos.files||[]);const pacas=disponibles();
 if(!producto.value){resultado.textContent='Primero selecciona un producto.';return;}
 if(!archivos.length)return;
 if(archivos.length>pacas.length){resultado.textContent='Hay más fotografías que pacas disponibles sin foto. No se cargará ninguna.';return;}
 archivos.forEach((file,i)=>{
  const card=document.createElement('div');card.className='item';
  const img=document.createElement('img');const url=URL.createObjectURL(file);urls.push(url);img.src=url;img.alt='Vista previa de '+file.name;card.appendChild(img);
  const nombre=document.createElement('small');nombre.textContent=file.name;card.appendChild(nombre);
  const select=document.createElement('select');select.className='destino';select.setAttribute('aria-label','Paca para '+file.name);
  pacas.forEach((p,j)=>{const op=document.createElement('option');op.value=p.id;op.textContent=p.codigo_paca+' (ID '+p.id+')';op.selected=i===j;select.appendChild(op);});
  card.appendChild(select);const estado=document.createElement('div');estado.className='status';card.appendChild(estado);vista.appendChild(card);
 });
 resumen.textContent=archivos.length+' fotografía(s) para '+pacas.length+' paca(s) disponibles sin foto.';
 enviar.disabled=false;
});
enviar.addEventListener('click',async()=>{
 if(ocupado||!archivos.length)return;
 const selects=Array.from(vista.querySelectorAll('.destino'));
 const ids=selects.map(s=>s.value);
 if(ids.some(id=>!id)||new Set(ids).size!==ids.length){alert('Cada fotografía debe tener una paca diferente. Revisa las asignaciones.');return;}
 if(!confirm('Se subirán '+archivos.length+' fotografías a '+archivos.length+' pacas existentes. No se crearán nuevos QR ni se modificará el stock. ¿Continuar?'))return;
 ocupado=true;enviar.disabled=true;producto.disabled=true;fotos.disabled=true;selects.forEach(s=>s.disabled=true);progreso.hidden=false;
 let exitos=0,fallos=0;
 for(let i=0;i<archivos.length;i++){
  const estado=vista.children[i].querySelector('.status');
  const data=new FormData();data.append('csrf',csrf);data.append('accion','subir_foto');data.append('paca_id',ids[i]);data.append('archivo',archivos[i],archivos[i].name);
  try{
   const r=await fetch(window.location.pathname,{method:'POST',body:data,credentials:'same-origin'});
   const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.mensaje||'Error al guardar');
   estado.textContent=d.mensaje||'Guardada correctamente · QR conservado';estado.className='status good';exitos++;
  }catch(e){estado.textContent='No guardada: '+e.message;estado.className='status bad';fallos++;}
  progreso.value=Math.round((i+1)/archivos.length*100);
  resultado.textContent='Procesadas '+(i+1)+' de '+archivos.length+'. Guardadas: '+exitos+'. Fallidas: '+fallos+'.';
 }
 ocupado=false;producto.disabled=false;fotos.disabled=false;
 resultado.textContent+=' Para otra carga, actualiza la página y selecciona las pacas pendientes.';
});
const preparar=document.getElementById('preparar');
if(preparar) preparar.addEventListener('click',async()=>{
 const cat=document.getElementById('existente').value, out=document.getElementById('preparar_resultado');
 if(!cat){out.textContent='Selecciona un producto.';return;}
 if(!confirm('Se completaran campos vacios y se habilitaran para IA las pacas con fotografia y validaciones correctas. No se modificaran referencias historicas. Continuar?'))return;
 preparar.disabled=true;out.textContent='Procesando...';
 try{const fd=new FormData();fd.append('csrf',csrf);fd.append('accion','preparar_existentes');fd.append('producto_id',cat);
 const r=await fetch(window.location.pathname,{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.mensaje||'Error');out.textContent=d.mensaje;
 }catch(e){out.textContent='Error: '+e.message;}finally{preparar.disabled=false;}
});
})();
</script>
<?php include __DIR__.'/footer.php'; ?>
