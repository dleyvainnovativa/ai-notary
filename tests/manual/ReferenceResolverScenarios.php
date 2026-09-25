<?php
require '' . dirname(__DIR__, 2) . '/app/Services/References/ResolverResult.php';
require '' . dirname(__DIR__, 2) . '/app/Services/References/ReferenceResolver.php';
use App\Services\References\ReferenceResolver;

$M = '' . dirname(__DIR__, 2) . '/modules';
$uifSchema = json_decode(file_get_contents("$M/avisos_uif/v1/uif_schema.json"), true);
$uifRefs = json_decode(file_get_contents("$M/avisos_uif/v1/module.json"), true)['references'];
$decSchema = json_decode(file_get_contents("$M/declaranot/v1/declaranot_schema.json"), true);
$calSchema = json_decode(file_get_contents("$M/declaranot/v1/calculo_pagos_schema.json"), true);
$decRefs = json_decode(file_get_contents("$M/declaranot/v1/module.json"), true)['references'];

$eduardo = ['tipo_domicilio'=>'1','entidad_federativa'=>'30','calle'=>'CALLE DOS','num_ext'=>'24','num_int'=>null,
            'codigo_postal'=>'95750','colonia'=>'LAGUNA ENCANTADA','municipio'=>'SAN ANDRES TUXTLA','copiado_de'=>null];
function op($fecha, $pagos, $v2dom) {
  global $eduardo;
  return ['fecha_operacion'=>$fecha,'tipo_transmision'=>null,
    'adquirentes'=>[['tipo_persona'=>'1','nombre'=>'ARTURO','domicilio'=>['calle'=>'AZALEAS','num_ext'=>'489','codigo_postal'=>'91948','colonia'=>'FLORES DEL VALLE','municipio'=>'VERACRUZ','copiado_de'=>null]]],
    'vendedores'=>[['tipo_persona'=>'1','nombre'=>'EDUARDO','domicilio'=>$eduardo],
                   ['tipo_persona'=>'1','nombre'=>'MARIA DE LOS ANGELES','domicilio'=>$v2dom]],
    'inmueble'=>['domicilio'=>['codigo_postal'=>null,'colonia'=>'VISTA ALEGRE','municipio'=>'BOCA DEL RIO']],
    'pagos'=>$pagos];
}
$r = new ReferenceResolver();
$fail = 0;
function check($label, $cond) { global $fail; echo ($cond ? "  PASS " : "  FAIL ") . $label . "\n"; if (!$cond) $fail++; }
function show($res) { foreach ($res->notes as $n) echo "    note [{$n['input']}] {$n['path']}: {$n['message']}\n"; }

echo "S1 — AI follows the new rules (sentinel + copiado_de)\n";
$copied = $eduardo; $copied['copiado_de'] = 'EDUARDO SAN MARTIN ROMERO';
$res = $r->resolve(['escritura'=>['referencia_aviso'=>'26763','operaciones'=>[op('2026-07-30',
  [['fecha_pago'=>'@FECHA_ESCRITURA','monto'=>210000],['fecha_pago'=>'@FECHA_ESCRITURA','monto'=>1890000]], $copied)]]],
  ['escritura'=>$uifSchema], $uifRefs);
$o = $res->data['escritura']['operaciones'][0];
check('pago 0 = 2026-07-30', $o['pagos'][0]['fecha_pago'] === '2026-07-30');
check('pago 1 = 2026-07-30', $o['pagos'][1]['fecha_pago'] === '2026-07-30');
check('vendedor 1 keeps copied address', $o['vendedores'][1]['domicilio']['calle'] === 'CALLE DOS');
check('copiado_de removed everywhere', !str_contains(json_encode($res->data), 'copiado_de'));
check('3 notes', count($res->notes) === 3);
show($res);

echo "S2 — AI ignores the rules (literal phrases left in fields)\n";
$res = $r->resolve(['escritura'=>['operaciones'=>[op('2026-07-30',
  [['fecha_pago'=>'EN ESTA FECHA','monto'=>210000]],
  ['calle'=>'CON MISMO DOMICILIO, VECINDAD Y ESTADO CIVIL QUE EL ANTERIOR','codigo_postal'=>null,'colonia'=>null,'municipio'=>null])]]],
  ['escritura'=>$uifSchema], $uifRefs);
$o = $res->data['escritura']['operaciones'][0];
check('literal phrase date -> 2026-07-30', $o['pagos'][0]['fecha_pago'] === '2026-07-30');
check('literal phrase address -> copied from previous vendedor', $o['vendedores'][1]['domicilio']['codigo_postal'] === '95750');
show($res);

echo "S3 — declaranot cross-input (calculo never sees the deed)\n";
$res = $r->resolve(['escritura'=>['numero_escritura'=>'26763','fecha_firma_escritura'=>'2026-07-30'],
  'calculo'=>['pago'=>[['fecha_pago_federacion'=>'@FECHA_ESCRITURA','fecha_pago_entidad'=>'2026-07-31']]]],
  ['escritura'=>$decSchema,'calculo'=>$calSchema], $decRefs);
check('calculo date resolved from escritura', $res->data['calculo']['pago'][0]['fecha_pago_federacion'] === '2026-07-30');
check('real date untouched', $res->data['calculo']['pago'][0]['fecha_pago_entidad'] === '2026-07-31');
check('deed date untouched', $res->data['escritura']['fecha_firma_escritura'] === '2026-07-30');
show($res);

echo "S4 — multiple operations: each pago takes ITS operation's date\n";
$res = $r->resolve(['escritura'=>['operaciones'=>[
  op('2026-07-30', [['fecha_pago'=>'@FECHA_ESCRITURA']], $eduardo),
  op('2026-08-15', [['fecha_pago'=>'@FECHA_ESCRITURA'],['fecha_pago'=>'2026-08-01']], $eduardo)]]],
  ['escritura'=>$uifSchema], $uifRefs);
$ops = $res->data['escritura']['operaciones'];
check('op0 pago -> 2026-07-30', $ops[0]['pagos'][0]['fecha_pago'] === '2026-07-30');
check('op1 pago -> 2026-08-15', $ops[1]['pagos'][0]['fecha_pago'] === '2026-08-15');
check('op1 explicit date untouched', $ops[1]['pagos'][1]['fecha_pago'] === '2026-08-01');

echo "S5 — unresolvable: no deed date / self reference / first person referencing\n";
$first = ['calle'=>'CON MISMO DOMICILIO QUE EL ANTERIOR'];
$o = op('@FECHA_ESCRITURA', [['fecha_pago'=>'@FECHA_ESCRITURA']], $eduardo);
$o['adquirentes'][0]['domicilio'] = $first;
$res = $r->resolve(['escritura'=>['operaciones'=>[$o]]], ['escritura'=>$uifSchema], $uifRefs);
$x = $res->data['escritura']['operaciones'][0];
check('self-referencing fecha_operacion -> null', $x['fecha_operacion'] === null);
check('pago with no deed date -> null', $x['pagos'][0]['fecha_pago'] === null);
check('phrase cleared on first person (no previous)', $x['adquirentes'][0]['domicilio']['calle'] === null);
show($res);

echo "S6 — false positives: normal data must not change\n";
$clean = ['escritura'=>['operaciones'=>[op('2026-07-30', [['fecha_pago'=>'2026-07-30','monto'=>5]], $eduardo)]]];
$clean['escritura']['operaciones'][0]['tipo_transmision'] = 'REPRESENTADO EN ESTE ACTO'; // non-date field with the phrase
$res = $r->resolve($clean, ['escritura'=>$uifSchema], $uifRefs);
check('no notes', count($res->notes) === 0);
check('non-date field with "en este acto" untouched', $res->data['escritura']['operaciones'][0]['tipo_transmision'] === 'REPRESENTADO EN ESTE ACTO');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
