<?php
namespace App\Services { class CatalogService { public function load($n,$d){ $p="$d/catalogs/$n.json"; return file_exists($p)?json_decode(file_get_contents($p),true):null; } public function isValidValue($n,$d,$v){return true;} } }
namespace Carbon { class Carbon { private $d; function __construct($d){$this->d=$d;} static function parse($x){ return new self(new \DateTimeImmutable((string)$x)); } function format($f){ return $this->d->format($f);} } }
namespace {
function app($c){ return new $c; }
function now(){ return Carbon\Carbon::parse('2026-10-01'); }
function data_get($a,$path){ foreach(explode('.',$path) as $k){ if(!is_array($a)||!array_key_exists($k,$a)) return null; $a=$a[$k]; } return $a; }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
foreach (['Modules/ModuleControllerContract','Modules/ModuleInput','Modules/ExporterContract','Services/References/ResolverResult','Services/References/ReferenceResolver','Services/Schema/IdDates','Services/Schema/ValidationIssue','Services/Schema/EngineResult','Services/Schema/SchemaEngine','Services/Pdf/ReviewPdfPresenter'] as $f) require "$R/app/$f.php";
require "$R/modules/avisos_uif/v1/Controller.php";
require "$R/modules/avisos_uif/v1/Exporter.php";
$fail = 0;
function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }
$M = "$R/modules";
$uifSchema = json_decode(file_get_contents("$M/avisos_uif/v1/uif_schema.json"), true);
$uifRefs = json_decode(file_get_contents("$M/avisos_uif/v1/module.json"), true)['references'];
$decRefs = json_decode(file_get_contents("$M/declaranot/v1/module.json"), true)['references'];
$decSchema = json_decode(file_get_contents("$M/declaranot/v1/declaranot_schema.json"), true);
$res = new App\Services\References\ReferenceResolver();
$noteAt = fn($r, $path) => array_values(array_filter($r->notes, fn($n) => $n['path'] === $path));

echo "Nuda propiedad — deed 26464 (explicit amount)\n";
$op = fn($vc, $avaluo = 2565800) => ['fecha_operacion'=>'2025-12-19','tipo_transmision'=>'3','adquirentes'=>[],'vendedores'=>[],'pagos'=>[],
    'inmueble'=>['valor_pactado'=>0,'valor_avaluo'=>$avaluo,'valor_calculo'=>$vc]];
$r = $res->resolve(['escritura'=>['operaciones'=>[$op(['derecho_transmitido'=>'nuda_propiedad','valor_total'=>'$5,131,600.000','valor_explicito'=>2565800,'porcentajes'=>[]], 5131600)]]], ['escritura'=>$uifSchema], $uifRefs);
$inm = $r->data['escritura']['operaciones'][0]['inmueble'];
check('valor_avaluo = 2,565,800 (explicit wins over the AI\'s 5,131,600)', $inm['valor_avaluo'] == 2565800);
check('"$5,131,600.000" read as 5,131,600', str_contains($noteAt($r, 'operaciones.0.inmueble.valor_avaluo')[0]['message'] ?? '', '$5,131,600.00'));
check('helper valor_calculo removed', !array_key_exists('valor_calculo', $inm));
$n = $noteAt($r, 'operaciones.0.inmueble.valor_avaluo')[0] ?? null;
check('note explains it: nuda propiedad, total and 50%', $n && $n['kind'] === 'partial_value' && str_contains($n['message'], 'nuda propiedad') && str_contains($n['message'], '50%'));
echo "    → {$n['message']}\n";

echo "Cross-check explicit vs percentage\n";
$r = $res->resolve(['escritura'=>['operaciones'=>[$op(['derecho_transmitido'=>'nuda_propiedad','valor_total'=>5131600,'valor_explicito'=>2565800,'porcentajes'=>[['porcentaje'=>50,'concepto'=>'nuda propiedad']]])]]], ['escritura'=>$uifSchema], $uifRefs);
check('explicit = 50% of total → informative note, no warning', ($noteAt($r, 'operaciones.0.inmueble.valor_avaluo')[0]['kind'] ?? '') === 'partial_value');
$r = $res->resolve(['escritura'=>['operaciones'=>[$op(['derecho_transmitido'=>'nuda_propiedad','valor_total'=>5131600,'valor_explicito'=>2565800,'porcentajes'=>[['porcentaje'=>80,'concepto'=>'nuda propiedad']]])]]], ['escritura'=>$uifSchema], $uifRefs);
$n = $noteAt($r, 'operaciones.0.inmueble.valor_avaluo')[0] ?? null;
check('explicit ≠ 80% of total → keeps explicit, WARNING with both numbers', $r->data['escritura']['operaciones'][0]['inmueble']['valor_avaluo'] == 2565800 && $n['kind'] === 'warning' && str_contains($n['message'], '$4,105,280.00'));

echo "Percentages only (computed by code, never by the AI)\n";
$r = $res->resolve(['escritura'=>['operaciones'=>[$op(['derecho_transmitido'=>'parcial','valor_total'=>2000000,'valor_explicito'=>null,'porcentajes'=>[['porcentaje'=>50,'concepto'=>'copropiedad'],['porcentaje'=>'80%','concepto'=>'nuda propiedad']]], 1600000)]]], ['escritura'=>$uifSchema], $uifRefs);
$n = $noteAt($r, 'operaciones.0.inmueble.valor_avaluo')[0] ?? null;
check('stacked 50% × 80% of 2,000,000 = 800,000 (overrides AI\'s wrong 1,600,000)', $r->data['escritura']['operaciones'][0]['inmueble']['valor_avaluo'] == 800000);
check('note shows the math', str_contains($n['message'] ?? '', '50% × 80% de $2,000,000.00 = $800,000.00'));
echo "    → {$n['message']}\n";
$r = $res->resolve(['escritura'=>['operaciones'=>[$op(['derecho_transmitido'=>'nuda_propiedad','valor_total'=>1234567.89,'valor_explicito'=>null,'porcentajes'=>[['porcentaje'=>33.33]]])]]], ['escritura'=>$uifSchema], $uifRefs);
check('rounded to cents: 33.33% of 1,234,567.89 = 411,481.48', $r->data['escritura']['operaciones'][0]['inmueble']['valor_avaluo'] == 411481.48);

echo "Missing data / pleno dominio\n";
$r = $res->resolve(['escritura'=>['operaciones'=>[$op(['derecho_transmitido'=>'nuda_propiedad','valor_total'=>null,'valor_explicito'=>null,'porcentajes'=>[]], 999)]]], ['escritura'=>$uifSchema], $uifRefs);
check('nuda propiedad with no value/percent → value untouched + warning to capture it', $r->data['escritura']['operaciones'][0]['inmueble']['valor_avaluo'] == 999 && ($noteAt($r, 'operaciones.0.inmueble.valor_avaluo')[0]['kind'] ?? '') === 'warning');
$r = $res->resolve(['escritura'=>['operaciones'=>[$op(['derecho_transmitido'=>'pleno_dominio','valor_total'=>1500000,'valor_explicito'=>null,'porcentajes'=>[]], null)]]], ['escritura'=>$uifSchema], $uifRefs);
check('pleno dominio + empty target → total, no note', $r->data['escritura']['operaciones'][0]['inmueble']['valor_avaluo'] == 1500000 && !$noteAt($r, 'operaciones.0.inmueble.valor_avaluo'));
$r = $res->resolve(['escritura'=>['operaciones'=>[$op(['derecho_transmitido'=>'pleno_dominio','valor_total'=>1500000,'valor_explicito'=>null,'porcentajes'=>[]], 1450000)]]], ['escritura'=>$uifSchema], $uifRefs);
check('pleno dominio never overwrites an existing value', $r->data['escritura']['operaciones'][0]['inmueble']['valor_avaluo'] == 1450000);
$r = $res->resolve(['escritura'=>['operaciones'=>[
    $op(['derecho_transmitido'=>'nuda_propiedad','valor_total'=>1000000,'porcentajes'=>[['porcentaje'=>50]]]),
    $op(['derecho_transmitido'=>'nuda_propiedad','valor_total'=>3000000,'porcentajes'=>[['porcentaje'=>70]]])]]], ['escritura'=>$uifSchema], $uifRefs);
check('each operación\'s inmueble computed independently', $r->data['escritura']['operaciones'][0]['inmueble']['valor_avaluo'] == 500000 && $r->data['escritura']['operaciones'][1]['inmueble']['valor_avaluo'] == 2100000);
echo "DeclaraNOT (root-level avaluo_inmueble)\n";
$r = $res->resolve(['escritura'=>['numero_escritura'=>'26464','fecha_firma_escritura'=>'2025-12-19','avaluo_inmueble'=>5131600,
    'valor_calculo'=>['derecho_transmitido'=>'nuda_propiedad','valor_total'=>5131600,'valor_explicito'=>2565800,'porcentajes'=>[]]]], ['escritura'=>$decSchema], $decRefs);
check('avaluo_inmueble = 2,565,800 + note at avaluo_inmueble', $r->data['escritura']['avaluo_inmueble'] == 2565800 && $noteAt($r, 'avaluo_inmueble') && !isset($r->data['escritura']['valor_calculo']));

echo "Optional forma de pago / instrumento (engine + PDF)\n";
$ctrl = new Modules\AvisosUif\V1\Controller("$M/avisos_uif/v1");
$fs = $ctrl->formSchema();
$engine = new App\Services\Schema\SchemaEngine(new App\Services\CatalogService);
$pago = ['fecha_pago'=>'2025-12-19','forma_pago'=>null,'instrumento'=>null,'moneda'=>'1','monto'=>100];
$data = fn($tipo) => ['referencia_aviso'=>'26464','operaciones'=>[['fecha_operacion'=>'2025-12-19','tipo_transmision'=>$tipo,'adquirentes'=>[],'vendedores'=>[],'inmueble'=>[],'pagos'=>[$pago]]]];
$issues = fn($tipo) => array_map(fn($i) => $i->path, $engine->process($fs, $data($tipo), "$M/avisos_uif/v1")->issues);
foreach (['2'=>'herencia','3'=>'donación','7'=>'cesión gratuita','9'=>'servidumbre sin indemnización'] as $t => $lbl) {
  check("tipo $t ($lbl): forma_pago / instrumento NOT required", !array_intersect(['operaciones.0.pagos.0.forma_pago','operaciones.0.pagos.0.instrumento'], $issues($t)));
}
check('tipo 1 (compraventa): both still required', count(array_intersect(['operaciones.0.pagos.0.forma_pago','operaciones.0.pagos.0.instrumento'], $issues('1'))) === 2);
check('tipo 3: moneda / monto still required when a pago exists', in_array('operaciones.0.pagos.0.moneda', array_map(fn($i) => $i->path, $engine->process($fs, ['operaciones'=>[['tipo_transmision'=>'3','pagos'=>[['fecha_pago'=>'2025-12-19','monto'=>1]]]]], "$M/avisos_uif/v1")->issues), true));
$d = $data('3'); $d['operaciones'][0]['pagos'][0]['forma_pago'] = '99';
check('tipo 3: a filled but invalid forma_pago is still flagged (optional ≠ unchecked)', in_array('operaciones.0.pagos.0.forma_pago', array_map(fn($i) => $i->path, $engine->process($fs, $d, "$M/avisos_uif/v1")->issues), true));
$pres = new App\Services\Pdf\ReviewPdfPresenter($engine);
$missing = function ($tipo) use ($pres, $fs, $data) {
  foreach ($pres->present($fs, $data($tipo)) as $sec) foreach ($sec['blocks'] as $b) if ($b['type'] === 'cards') foreach ($b['items'] as $card) foreach ($card['blocks'] as $bb)
    if ($bb['type'] === 'table') { $out = []; foreach ($bb['columns'] as $i => $c) $out[$c['label']] = $bb['rows'][0][$i]['missing']; return $out; }
};
check('PDF: no "Falta" on forma/instrumento for a donación', $missing('3')['Forma Pago'] === false && $missing('3')['Instrumento'] === false);
check('PDF: "Falta" on both for a compraventa', $missing('1')['Forma Pago'] === true && $missing('1')['Instrumento'] === true);

echo "postProcess safety net + export without pagos\n";
$out = $ctrl->postProcess(['escritura'=>['operaciones'=>[
  ['tipo_transmision'=>'3','inmueble'=>['valor_pactado'=>null],'pagos'=>[['monto'=>0],['monto'=>null]]],
  ['tipo_transmision'=>'1','inmueble'=>['valor_pactado'=>null],'pagos'=>[['monto'=>0]]]]]]);
check('donación: valor_pactado → 0, empty pagos dropped', $out['escritura']['operaciones'][0]['inmueble']['valor_pactado'] === 0 && $out['escritura']['operaciones'][0]['pagos'] === []);
check('compraventa untouched', $out['escritura']['operaciones'][1]['inmueble']['valor_pactado'] === null && count($out['escritura']['operaciones'][1]['pagos']) === 1);
$txt = (new Modules\AvisosUif\V1\Exporter())->export(['referencia_aviso'=>'26464','operaciones'=>[['fecha_operacion'=>'2025-12-19','tipo_transmision'=>'3','adquirentes'=>[],'vendedores'=>[],
  'inmueble'=>['tipo_bien'=>'3','valor_pactado'=>0,'valor_avaluo'=>2565800,'domicilio'=>[]],'pagos'=>[]]]], 'txt');
check('export: a donación without pagos emits ONE automatic liquidación fecha|0|0|1|valor avalúo (as in the real aviso 26464)',
  preg_match_all('/^930017/m', $txt) === 1 && str_contains($txt, '930017-Datos inmuebles liquidaciones-grid:19/12/2025|0|0|1|2565800.00|02646400-0000-0001-0000-000000000001'));
check('export: 930016 carries valor_pactado 0.00 and avalúo 2565800.00', (bool) preg_match('/^930016[^:]*:3\|0\.00\|.*\|2565800\.00\|/m', $txt));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
}
