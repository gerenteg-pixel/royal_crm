<?php
declare(strict_types=1);
/**
 * Royal AI Sales - Fase 2: configuracion independiente de observacion.
 * Ubicacion: public_html/whatsapp/royal_ai_sales_config.php
 * Este archivo no envia mensajes ni modifica asignaciones.
 * enabled=true significa SOLO que el monitor observa ese canal.
 */
$agents = [
    1 => ['name'=>'Carlos IA',   'instance_id'=>'3F8EA469A3BB11D54983B63E388305A1', 'enabled'=>true],  // Jefry12
    2 => ['name'=>'Maria IA',    'instance_id'=>'3F8EA453E775F1C181BF8E000F42F27C', 'enabled'=>true],  // Deybid08
    3 => ['name'=>'Andrea IA',   'instance_id'=>'3F322471C049D2F1007BFAB408D87876', 'enabled'=>true],  // Johan01
    4 => ['name'=>'David IA',    'instance_id'=>'3F3207D10077C1D79EB2E27BD9B0985E', 'enabled'=>true],  // Sindy02
    5 => ['name'=>'Sofia IA',    'instance_id'=>'', 'enabled'=>false],
    6 => ['name'=>'Daniel IA',   'instance_id'=>'', 'enabled'=>false],
    7 => ['name'=>'Valeria IA',  'instance_id'=>'', 'enabled'=>false],
    8 => ['name'=>'Gabriel IA',  'instance_id'=>'', 'enabled'=>false],
    9 => ['name'=>'Camila IA',   'instance_id'=>'', 'enabled'=>false],
   10 => ['name'=>'Sebastian IA','instance_id'=>'', 'enabled'=>false],
];
// Reservar hasta 30 puestos, sin activar ni asociar instancias nuevas.
for ($i = 11; $i <= 30; $i++) {
    $agents[$i] = ['name'=>'Agente IA '.$i, 'instance_id'=>'', 'enabled'=>false];
}
return [
    'mode' => 'simulation',
    'outbound_enabled' => false,
    'lookback_seconds' => 300,
    'max_rows' => 200,
    'max_agents' => 30,
    'agents' => $agents,
];