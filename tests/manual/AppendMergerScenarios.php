<?php
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
require "$R/app/Support/ReviewData.php";
require "$R/app/Services/Append/AppendException.php";
require "$R/app/Services/Append/AppendMerger.php";
use App\Services\Append\{AppendMerger, AppendException};
$fail = 0;
function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }
$cfg = json_decode(file_get_contents("$R/modules/avisos_uif/v1/module.json"), true)['append'];
$m = new AppendMerger();

$op1 = ['fecha_operacion'=>'2026-07-30','adquirentes'=>[['rfc'=>'OEEA651127CFA','nombre'=>'ARTURO']],'vendedores'=>[['rfc'=>'SARE570420CQ7']],'pagos'=>[['monto'=>210000]]];
$parentRaw = ['escritura'=>['referencia_aviso'=>'26763','operaciones'=>[$op1]], '_meta'=>['notes'=>[['input'=>'escritura','path'=>'operaciones.0.pagos.0.fecha_pago','kind'=>'deed_date','message'=>'p']]]];
$parentDraft = ['referencia_aviso'=>'26763-EDITADO','operaciones_acumuladas'=>'1','operaciones'=>[$op1 + ['tipo_transmision'=>'1']]];   // user edits
$childOp = ['fecha_operacion'=>'2026-08-15','adquirentes'=>[['rfc'=>'OEEA651127CFA','nombre'=>'ARTURO']],'vendedores'=>[['rfc'=>'XYZA800101AB1']],'pagos'=>[['monto'=>900000,'fecha_pago'=>'2026-08-15']]];
$child = ['escritura'=>['referencia_aviso'=>'27001','solicitante'=>['rfc'=>'ZZZ'],'operaciones'=>[$childOp]],
  '_meta'=>['notes'=>[
    ['input'=>'escritura','path'=>'operaciones.0.pagos.0.fecha_pago','kind'=>'deed_date','message'=>'child date'],
    ['input'=>'escritura','path'=>'operaciones.0.vendedores.0.domicilio','kind'=>'address','message'=>'child addr'],
    ['input'=>'escritura','path'=>'solicitante.fecha_nacimiento','kind'=>'x','message'=>'child non-op note'],
  ]]];

echo "Merge into a parent with a saved draft\n";
$r = $m->merge($parentDraft, $parentRaw, $child, $cfg, '507-27001.docx');
check('offset 1, 1 item appended', $r['offset'] === 1 && $r['count'] === 1);
check('draft keeps user edits (referencia, tipo_transmision)', $r['draft']['referencia_aviso'] === '26763-EDITADO' && $r['draft']['operaciones'][0]['tipo_transmision'] === '1');
check('draft now has 2 operaciones, new one second', count($r['draft']['operaciones']) === 2 && $r['draft']['operaciones'][1]['fecha_operacion'] === '2026-08-15');
check('child\'s other data ignored (solicitante, referencia)', !isset($r['draft']['solicitante']) && $r['draft']['referencia_aviso'] === '26763-EDITADO');
check('operaciones_acumuladas forced to "1"', $r['draft']['operaciones_acumuladas'] === '1');
check('AI baseline also gets the operation (diffs stay meaningful)', count($r['raw']['escritura']['operaciones']) === 2 && $r['raw']['escritura']['referencia_aviso'] === '26763');
$paths = array_column($r['raw']['_meta']['notes'], 'path');
check('parent note kept', in_array('operaciones.0.pagos.0.fecha_pago', $paths, true));
check('child notes re-indexed to operaciones.1…', in_array('operaciones.1.pagos.0.fecha_pago', $paths, true) && in_array('operaciones.1.vendedores.0.domicilio', $paths, true));
check('child note outside the array dropped', !in_array('solicitante.fecha_nacimiento', $paths, true));
$kinds = array_column(array_filter($r['raw']['_meta']['notes'], fn($n) => $n['path'] === 'operaciones.1'), 'kind');
check('"agregada desde la escritura" note on the new operation', in_array('append', $kinds, true));
check('same client (ARTURO\'s RFC) → no mismatch warning', !in_array('warning', $kinds, true));

echo "Different client → warning\n";
$other = $child; $other['escritura']['operaciones'][0]['adquirentes'][0]['rfc'] = 'LOPA050312AB1';
$r2 = $m->merge($parentDraft, $parentRaw, $other, $cfg, 'x.docx');
check('mismatch warning added', in_array('warning', array_column(array_filter($r2['raw']['_meta']['notes'], fn($n) => $n['path'] === 'operaciones.1'), 'kind'), true));

echo "Parent without draft, 3rd deed, errors\n";
$r3 = $m->merge(\App\Support\ReviewData::flatten($r['raw']), $r['raw'], $child, $cfg, 'tercera.pdf');
check('third deed → offset 2, 3 operaciones', $r3['offset'] === 2 && count($r3['draft']['operaciones']) === 3);
check('its notes land on operaciones.2', in_array('operaciones.2.pagos.0.fecha_pago', array_column($r3['raw']['_meta']['notes'], 'path'), true));
try { $m->merge($parentDraft, $parentRaw, ['escritura'=>['operaciones'=>[]]], $cfg, 'x'); check('empty child throws', false); }
catch (AppendException $e) { check('child with no operaciones → AppendException (token released)', str_contains($e->getMessage(), 'token')); }
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
