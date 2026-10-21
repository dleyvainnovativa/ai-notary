<?php
// Round 6: UIF representante legal, DeclaraNOT CURP optional, copropiedad = 100 %, ISR < ingreso/ganancia acumulable.
// Run: php tests/manual/ValidationRulesScenarios.php
namespace App\Services { class CatalogService {
    public function load($n, $d) { $p = $this->catalogPath($n, $d); return file_exists($p) ? json_decode(file_get_contents($p), true) : null; }
    public function catalogPath(string $catalogName, string $moduleDir): string {
        $own = "{$moduleDir}/catalogs/{$catalogName}.json"; if (file_exists($own)) return $own;
        $m = @json_decode((string) @file_get_contents("{$moduleDir}/module.json"), true); $parent = is_array($m) ? ($m['extends'] ?? null) : null;
        if (is_string($parent) && $parent !== '') { $inh = rtrim($moduleDir, '/') . '/' . trim($parent, '/') . "/catalogs/{$catalogName}.json"; if (file_exists($inh)) return $inh; }
        return $own; }
    public function isValidValue($n,$d,$v){ return true; } } }
namespace Carbon { class Carbon { private $d; function __construct($d){$this->d=$d;} static function parse($x){ return new self(new \DateTimeImmutable((string)$x)); } function format($f){ return $this->d->format($f);} } }
namespace {
function app($c){ return new $c; }
function now(){ return Carbon\Carbon::parse('2026-10-06'); }
function data_get($a,$path){ foreach(explode('.',$path) as $k){ if(!is_array($a)||!array_key_exists($k,$a)) return null; $a=$a[$k]; } return $a; }
function resource_path($p){ return (getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2)) . "/resources/$p"; }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
foreach (['Modules/ModuleControllerContract','Modules/ModuleInput','Modules/ExporterContract','Modules/ImporterContract','Modules/ImportResult','Modules/ImportException',
  'Services/Import/TxtDecoder','Services/Import/ImportService','Services/Schema/IdDates','Services/Schema/ValidationIssue','Services/Schema/EngineResult','Services/Schema/SchemaEngine','Services/Pdf/ReviewPdfPresenter'] as $f) require "$R/app/$f.php";
require "$R/modules/declaranot/v1/Controller.php";
require "$R/modules/declaranot_adquisicion/v1/Controller.php";
require "$R/modules/avisos_uif/v1/Controller.php";
require "$R/modules/avisos_uif/v1/Importer.php";
use App\Services\Schema\{SchemaEngine, EngineResult};
$fail = 0; function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }
$engine = new SchemaEngine(new App\Services\CatalogService);
$issues = fn($res, $rule = null) => array_values(array_filter($res->issues, fn($i) => $rule === null || $i->rule === $rule));
$paths = fn(array $list) => array_map(fn($i) => $i->path, $list);
$byPath = function ($res, $path) { foreach ($res->issues as $i) if ($i->path === $path) return $i; return null; };

/* ============ DeclaraNOT (enajenación) ============ */
$D = "$R/modules/declaranot/v1";
$dfs = (new Modules\Declaranot\V1\Controller($D))->formSchema();
$nacional = ['tipo_enajenante'=>'1','rfc'=>'CUSP620317SN3','nombre'=>'PATRICIA','apellido_paterno'=>'CRUZ','apellido_materno'=>'SOLORZANO','curp'=>null];
$pago = fn($gan, $fed, $ent) => ['ingresos_enajenacion'=>1000000,'ingresos_exentos'=>0,'deducciones_autorizadas'=>0,'ganancia_perdida'=>$gan,'years_adquisicion_venta'=>5,
  'ganancia_acumulable'=>$gan,'ganancia_no_acumulable'=>0,'isr_federacion'=>$fed,'numero_operacion_federacion'=>$fed ? 'A1' : null,'fecha_pago_federacion'=>$fed ? '2026-01-02' : null,
  'isr_entidad'=>$ent,'numero_operacion_entidad'=>$ent ? 'B1' : null,'fecha_pago_entidad'=>$ent ? '2026-01-02' : null];
$integ = fn($rfc, $pct, $gan = 100, $fed = 10, $ent = 0) => ['rfc'=>$rfc,'porcentaje'=>$pct,'ingresos_enajenacion'=>500,'deducciones_autorizadas'=>0,'ganancia_perdida'=>$gan,'ganancia_acumulable'=>$gan,'ganancia_no_acumulable'=>0,'isr_federacion'=>$fed,'isr_entidad'=>$ent];
$dn = fn(array $over = []) => array_replace([
  'numero_escritura'=>'1','fecha_firma_escritura'=>'2026-01-02','tipo_inmueble'=>'1','avaluo_inmueble'=>1000000,
  'enajenantes'=>[$nacional],'adquirientes'=>[],'pago'=>[$pago(100000, 20000, 1000)],
  'copropiedad'=>['existe_copropiedad'=>'2','integrantes'=>[]],'representante_comun'=>['existe_representante_comun'=>'2','rfc_representante'=>null],
], $over);

echo "DeclaraNOT · CURP optional when the RFC is set\n";
$r = $engine->process($dfs, $dn(), $D);
check('nacional física with RFC and no CURP → no CURP issue', !in_array('enajenantes.0.curp', $paths($r->issues), true));
check('CURP still shown for nacional física (show_in_cases)', ($dfs['fields']['enajenantes']['itemSchema']['curp']['show_in_cases'] ?? null) === ['nacional_fisica'] && empty($dfs['fields']['adquirientes']['itemSchema']['curp']['required_in_cases']));
$bad = $dn(['enajenantes'=>[['curp'=>'XXX'] + $nacional]]);
check('…a filled but malformed CURP is still an error', ($byPath($engine->process($dfs, $bad, $D), 'enajenantes.0.curp')->rule ?? null) === 'format');
$noRfc = $dn(['enajenantes'=>[['rfc'=>null] + $nacional]]);
check('RFC stays required', ($byPath($engine->process($dfs, $noRfc, $D), 'enajenantes.0.rfc')->rule ?? null) === 'required');

echo "DeclaraNOT · ISR < ganancia acumulable (pago + copropiedad, federación + entidad)\n";
check('valid pago (ISR 20,000 / 1,000 < 100,000) → no less_than issue', $issues($r, 'less_than') === []);
$r = $engine->process($dfs, $dn(['pago'=>[$pago(100000, 150000, 100000)]]), $D);
$i = $byPath($r, 'pago.0.isr_federacion');
check('ISR federación ≥ ganancia → error on the ISR field', $i && $i->rule === 'less_than');
check('message names both labels and amounts', $i && $i->message === 'ISR Federación ($150,000) debe ser menor que Ganancia Acumulable ($100,000).');
check('ISR entidad equal to ganancia → error too (rule applies to entidad)', ($byPath($r, 'pago.0.isr_entidad')->message ?? '') === 'ISR Entidad ($100,000) debe ser menor que Ganancia Acumulable ($100,000).');
$r = $engine->process($dfs, $dn(['pago'=>[$pago(0, 0, 0)]]), $D);
check('no tax: 0 / 0 is fine', $issues($r, 'less_than') === []);
$r = $engine->process($dfs, $dn(['pago'=>[['ganancia_acumulable'=>null] + $pago(0, 500, 0)]]), $D);
check('ISR with empty ganancia → error mentions "vacío"', str_ends_with($byPath($r, 'pago.0.isr_federacion')->message ?? '', 'Ganancia Acumulable (vacío).'));
$cop = ['existe_copropiedad'=>'1','integrantes'=>[$integ('AAA010101AA1', 50, 100, 200), $integ('BBB010101BB1', 50, 100, 10, 100)]];
$r = $engine->process($dfs, $dn(['copropiedad'=>$cop]), $D);
check('copropiedad integrante: ISR fed > ganancia and ISR entidad = ganancia → 2 errors', $paths($issues($r, 'less_than')) === ['copropiedad.integrantes.0.isr_federacion', 'copropiedad.integrantes.1.isr_entidad']);

echo "DeclaraNOT · copropiedad porcentajes = 100\n";
$r = $engine->process($dfs, $dn(['copropiedad'=>['existe_copropiedad'=>'1','integrantes'=>[$integ('AAA010101AA1', 50), $integ('BBB010101BB1', 40)]]]), $D);
$s = $issues($r, 'sum_equals');
check('50 + 40 → an error on each porcentaje', $paths($s) === ['copropiedad.integrantes.0.porcentaje', 'copropiedad.integrantes.1.porcentaje']);
check('message: "La suma de porcentajes es 90%; debe ser 100%."', $s[0]->message === 'La suma de porcentajes es 90%; debe ser 100%.');
$r = $engine->process($dfs, $dn(['copropiedad'=>['existe_copropiedad'=>'1','integrantes'=>[$integ('AAA010101AA1', 33), $integ('BBB010101BB1', 33), $integ('CCC010101CC1', 34)]]]), $D);
check('33 + 33 + 34 → valid', $issues($r, 'sum_equals') === []);
$r = $engine->process($dfs, $dn(['copropiedad'=>['existe_copropiedad'=>'1','integrantes'=>[$integ('AAA010101AA1', '60'), $integ('BBB010101BB1', '50')]]]), $D);
check('string values from an import are summed too (110 %)', ($issues($r, 'sum_equals')[0]->message ?? '') === 'La suma de porcentajes es 110%; debe ser 100%.');
$r = $engine->process($dfs, $dn(['copropiedad'=>['existe_copropiedad'=>'2','integrantes'=>[]]]), $D);
check('existe_copropiedad = No (form hides and empties the list) → no sum check', $issues($r, 'sum_equals') === []);
// the engine derives existe_copropiedad from the list length, so "Sí + no integrantes" only arrives from the client mid-edit:
$rule = $dfs['fields']['copropiedad']['itemSchema']['integrantes']['sum_equals'];
check('no integrantes → message asks to add them (shown on ¿Existe copropiedad?)', SchemaEngine::sumMessage($rule, []) === 'Agrega los integrantes: la suma de porcentajes debe ser 100%.' && $rule['empty_field'] === 'existe_copropiedad');

echo "Blocking\n";
$r = $engine->process($dfs, $dn(['pago'=>[$pago(100, 500, 0)], 'copropiedad'=>['existe_copropiedad'=>'1','integrantes'=>[$integ('AAA010101AA1', 10)]], 'numero_escritura'=>null]), $D);
$block = $r->blockingIssues();
check('blocking = only sum_equals / less_than (a missing required field does not block)', count($block) === 2 && !array_diff(array_map(fn($i) => $i->rule, $block), EngineResult::BLOCKING_RULES) && in_array('numero_escritura', $paths($r->issues), true));
check('valid data → no blocking issues', $engine->process($dfs, $dn(), $D)->blockingIssues() === []);

echo "PDF prints blocking errors on the field\n";
$p = new App\Services\Pdf\ReviewPdfPresenter($engine);
$errs = array_map(fn($i) => ['path'=>$i->path,'message'=>$i->message], $block);
$secs = $p->present($dfs, $r->data, [], $errs);
$flat = json_encode($secs, JSON_UNESCAPED_UNICODE);
check('PDF cells carry the error messages', str_contains($flat, '"errors":["ISR Federación ($500) debe ser menor que Ganancia Acumulable ($100)."]') && str_contains($flat, 'La suma de porcentajes es 10%'));

/* ============ DeclaraNOT por adquisición ============ */
$A = "$R/modules/declaranot_adquisicion/v1";
$afs = (new Modules\DeclaranotAdquisicion\V1\Controller($A))->formSchema();
$ad = fn(array $over = []) => array_replace([
  'numero_escritura'=>'1','fecha_firma_escritura'=>'2026-01-02','tipo_inmueble'=>'1','avaluo_inmueble'=>1000000,'monto_operacion'=>0,
  'adquirientes'=>[['tipo_adquiriente'=>'1','rfc'=>'LECD9808164X7','nombre'=>'DANIEL','apellido_paterno'=>'LEYVA','curp'=>null]],'enajenantes'=>[],
  'pago'=>[['ingreso_acumulable'=>2546325,'isr_federacion'=>200000,'numero_operacion'=>'PT70100','fecha_pago'=>'2021-07-15']],
  'copropiedad'=>['existe_copropiedad'=>'1','integrantes'=>[
     ['rfc'=>'CUME770108DE2','porcentaje'=>50,'monto_operacion'=>1,'valor_avaluo'=>1,'ingreso_acumulable'=>636581,'isr_federacion'=>100000],
     ['rfc'=>'CEGB710502TY5','porcentaje'=>50,'monto_operacion'=>1,'valor_avaluo'=>1,'ingreso_acumulable'=>636581,'isr_federacion'=>100000]]],
  'representante_comun'=>['existe_representante_comun'=>'2','rfc_representante'=>null],
], $over);
echo "DeclaraNOT por adquisición\n";
$r = $engine->process($afs, $ad(), $A);
check('SAT example values → no blocking issues, no CURP issue', $r->blockingIssues() === [] && !in_array('adquirientes.0.curp', $paths($r->issues), true));
$x = $ad(); $x['pago'][0]['isr_federacion'] = 3000000; $x['copropiedad']['integrantes'][1]['porcentaje'] = 40; $x['copropiedad']['integrantes'][0]['isr_federacion'] = 636581;
$r = $engine->process($afs, $x, $A);
check('ISR ≥ ingreso acumulable (pago + integrante) and 90 % → 4 blocking issues', $paths($r->blockingIssues()) === ['pago.0.isr_federacion', 'copropiedad.integrantes.0.porcentaje', 'copropiedad.integrantes.1.porcentaje', 'copropiedad.integrantes.0.isr_federacion']);
check('adquisición message uses "Ingreso Acumulable"', ($byPath($r, 'pago.0.isr_federacion')->message ?? '') === 'ISR Federación ($3,000,000) debe ser menor que Ingreso Acumulable ($2,546,325).');

/* ============ UIF · Representante legal ============ */
$U = "$R/modules/avisos_uif/v1";
$ufs = (new Modules\AvisosUif\V1\Controller($U))->formSchema();
$repDef = $ufs['fields']['operaciones']['itemSchema']['vendedores']['itemSchema']['representante'] ?? null;
echo "UIF · Representante legal\n";
check('adquirentes and vendedores have a "Representante Legal" object for moral + fideicomiso', $repDef && $repDef['type'] === 'object' && $repDef['show_in_cases'] === ['moral', 'fideicomiso'] && isset($ufs['fields']['operaciones']['itemSchema']['adquirentes']['itemSchema']['representante']));
check('fields: RFC, CURP, fecha nac., nombre, apellido paterno, apellido materno', array_keys($repDef['itemSchema']) === ['rfc','curp','fecha_nacimiento','nombre','apellido_paterno','apellido_materno']);
check('nombre + apellido paterno required; RFC/CURP optional', !empty($repDef['itemSchema']['nombre']['required']) && !empty($repDef['itemSchema']['apellido_paterno']['required']) && empty($repDef['itemSchema']['rfc']['required']) && empty($repDef['itemSchema']['curp']['required']));
$dom = ['tipo_domicilio'=>'1','entidad_federativa'=>'9','calle'=>'REFORMA','num_ext'=>'222','codigo_postal'=>'06600','colonia'=>'Juárez','municipio'=>'CUAUHTÉMOC'];
$moral = fn($rep) => ['tipo_persona'=>'2','rfc'=>'ICJ950101AB1','razon_social'=>'INMOBILIARIA SA','fecha_constitucion'=>'1995-01-01','giro_mercantil'=>'1000000','nacionalidad'=>'MX','domicilio'=>$dom,'representante'=>$rep];
$fisica = ['tipo_persona'=>'1','rfc'=>'OEEA651127CFA','curp'=>'OEEA651127HVZRRR09','fecha_nacimiento'=>'1965-11-27','nombre'=>'ARTURO','apellido_paterno'=>'ORTEGA','actividad_economica'=>'8111000','nacionalidad'=>'MX','domicilio'=>$dom,
  'representante'=>['rfc'=>null,'curp'=>null,'fecha_nacimiento'=>null,'nombre'=>null,'apellido_paterno'=>null,'apellido_materno'=>null]];
$fideicomiso = fn($rep) => ['tipo_persona'=>'3','rfc'=>'BNM840515VB1','razon_social'=>'BANCO FIDUCIARIO','numero_fideicomiso'=>'F/123','domicilio'=>$dom,'representante'=>$rep];
$uif = fn($adq, $ven) => ['referencia_aviso'=>'1','prioridad'=>'1','tipo_alerta'=>'100','operaciones'=>[['fecha_operacion'=>'2026-01-02','tipo_transmision'=>'1','adquirentes'=>$adq,'vendedores'=>$ven,'inmueble'=>[],'pagos'=>[]]]];
$r = $engine->process($ufs, $uif([$fisica], [$moral(['curp'=>'OEET621022MDFRSR01','nombre'=>null,'apellido_paterno'=>null])]), $U);
$rp = array_values(array_filter($paths($r->issues), fn($p) => str_contains($p, 'representante')));
check('moral without nombre / apellido → required errors on its representante', $rp === ['operaciones.0.vendedores.0.representante.nombre', 'operaciones.0.vendedores.0.representante.apellido_paterno']);
check('física: representante hidden → never validated', !array_filter($paths($r->issues), fn($p) => str_contains($p, 'adquirentes.0.representante')));
check('fecha de nacimiento derived from the representante CURP', ($r->data['operaciones'][0]['vendedores'][0]['representante']['fecha_nacimiento'] ?? null) === '1962-10-22');
$r = $engine->process($ufs, $uif([$fideicomiso(['nombre'=>'JUAN'])], []), $U);
check('fideicomiso: representante shown and validated too', in_array('operaciones.0.adquirentes.0.representante.apellido_paterno', $paths($r->issues), true));
$secs = (new App\Services\Pdf\ReviewPdfPresenter($engine))->present($ufs, $engine->process($ufs, $uif([$fisica], [$moral(['nombre'=>'MARÍA TERESA','apellido_paterno'=>'ORTEGA'])]), $U)->data);
$j = json_encode($secs, JSON_UNESCAPED_UNICODE);
check('PDF: "Representante Legal" group printed once (moral), not for the física', substr_count($j, '"title":"Representante Legal"') === 1 && str_contains($j, 'MARÍA TERESA'));

echo "UIF · export / import of the representante\n";
$exp = new Modules\AvisosUif\V1\Exporter();
$full = ['referencia_aviso'=>'26763','prioridad'=>'1','tipo_alerta'=>'100','solicitante'=>['rfc'=>'OEEA651127CFA','curp'=>'OEEA651127HVZRRR09','fecha_nacimiento'=>'1965-11-27','nombre'=>'ARTURO','apellido_paterno'=>'ORTEGA','apellido_materno'=>'ESCUDERO'],
  '_notaria'=>['clave'=>'001','entidad'=>'030','num_notaria'=>'36'],
  'operaciones'=>[['fecha_operacion'=>'2026-01-02','tipo_transmision'=>'1','adquirentes'=>[$fisica],'vendedores'=>[],'inmueble'=>['tipo_bien'=>'3','valor_pactado'=>100,'valor_avaluo'=>100,'domicilio'=>$dom],'pagos'=>[]]]];
$line = fn($txt, $tag) => array_values(array_filter(preg_split('/\r?\n/', $txt), fn($l) => str_starts_with($l, $tag)))[0] ?? null;
$withNull = $full; $withNull['operaciones'][0]['vendedores'] = [$moral(['rfc'=>null,'curp'=>null,'fecha_nacimiento'=>null,'nombre'=>null,'apellido_paterno'=>null,'apellido_materno'=>null])];
$noKey = $full; $noKey['operaciones'][0]['vendedores'] = [$moral(null)];
check('an all-empty representante (what the form sends) exports exactly like no representante', $line($exp->export($withNull, 'txt'), '930013') === $line($exp->export($noKey, 'txt'), '930013') && $line($exp->export($noKey, 'txt'), '930013') === '930013-Representante legal realiza operacion a nombre persona moral-grid:||||||');
$withRep = $full; $withRep['operaciones'][0]['vendedores'] = [$moral(['rfc'=>'OEET621022IH3','curp'=>'OEET621022MDFRSR01','fecha_nacimiento'=>'1962-10-22','nombre'=>'MARÍA TERESA','apellido_paterno'=>'ORTEGA','apellido_materno'=>'ESCUDERO'])];
$t = $exp->export($withRep, 'txt');
check('captured representante → 930013 filled (accents stripped like the rest of UIF)', str_starts_with($line($t, '930013'), '930013-Representante legal realiza operacion a nombre persona moral-grid:OEET621022IH3|OEET621022MDFRSR01|22/10/1962|MARIA TERESA|ORTEGA|ESCUDERO|'));
$res = (new App\Services\Import\ImportService())->import($t, new Modules\AvisosUif\V1\Importer(), $exp);
check('import: representante back on its moral, no "form can\'t show it" warning', ($res->data['operaciones'][0]['vendedores'][0]['representante']['nombre'] ?? null) === 'MARIA TERESA' && !array_filter($res->warnings, fn($w) => str_contains($w['message'], 'representante')));

echo "UIF · AI extraction\n";
$sch = json_decode(file_get_contents("$U/uif_schema.json"), true)['fields']['operaciones']['items'];
check('extraction schema: representante object on both sides', isset($sch['adquirentes']['items']['representante']['items']['curp'], $sch['vendedores']['items']['representante']['items']['apellido_paterno']));
$prompt = file_get_contents("$U/uif_prompt.txt");
check('prompt: moral/fideicomiso only, first signer, company stays the party', str_contains($prompt, 'use only the FIRST one') && str_contains($prompt, 'never replaces it') && str_contains($prompt, '"representante" is null'));
check('output example has representante keys', str_contains(file_get_contents("$U/uif_output.json"), '"representante"'));


echo "RFC / CURP exported in uppercase\n";
require_once "$R/modules/declaranot/v1/Exporter.php"; require_once "$R/modules/declaranot_adquisicion/v1/Exporter.php";
$low = ['tipo_enajenante'=>'1','tipo_adquiriente'=>'1','rfc'=>' cusp620317sn3 ','curp'=>'cusp620317mvzrlt07','nombre'=>'Patricia','apellido_paterno'=>'Cruz'];
$cp = ['existe_copropiedad'=>'1','integrantes'=>[['rfc'=>'aaa010101aa1','porcentaje'=>100]]];
$rc = ['existe_representante_comun'=>'1','rfc_representante'=>'zade470405sg7'];
$t = @(new Modules\Declaranot\V1\Exporter())->export(   // sparse test data: base exporter expects every key
  ['fecha_firma_escritura'=>'2026-01-02','enajenantes'=>[$low],'adquirientes'=>[$low],'copropiedad'=>$cp,'representante_comun'=>$rc], 'txt');
check('DeclaraNOT: persona RFC + CURP, integrante RFC, representante común → uppercase', str_contains($t, '900003-DatosEnajenante-grid:1|CUSP620317SN3|Patricia|Cruz||CUSP620317MVZRLT07') && str_contains($t, '900005-DatosAdquiriente-grid:1|CUSP620317SN3|') && str_contains($t, '900007-DatosCopropiedad-grid:AAA010101AA1|') && str_contains($t, '900009-RepresentanteLegal:ZADE470405SG7'));
$t = (new Modules\DeclaranotAdquisicion\V1\Exporter())->export(['fecha_firma_escritura'=>'2026-01-02','adquirientes'=>[$low],'enajenantes'=>[$low],'copropiedad'=>$cp,'representante_comun'=>$rc], 'txt');
check('Adquisición: 910002/910003 RFC + CURP, 910007 and 910006 → uppercase', str_contains($t, '910002-DatosAdquirientes-grid:1|CUSP620317SN3|Patricia|Cruz||CUSP620317MVZRLT07|') && str_contains($t, '910003-DatosEnajenantes-grid:1|CUSP620317SN3|') && str_contains($t, '910007-DatosCopropiedad-grid:AAA010101AA1|') && str_contains($t, '910006-RepresentanteLegal:ZADE470405SG7'));
$u = $full;
$u['solicitante']['rfc'] = 'oeea651127cfa'; $u['solicitante']['curp'] = 'oeea651127hvzrrr09';
$u['operaciones'][0]['adquirentes'][0]['rfc'] = 'oeea651127cfa'; $u['operaciones'][0]['adquirentes'][0]['curp'] = 'oeea651127hvzrrr09';
$u['operaciones'][0]['vendedores'] = [['rfc'=>'icj950101ab1'] + $moral(['rfc'=>'oeet621022ih3','curp'=>'oeet621022mdfrsr01','nombre'=>'MARIA','apellido_paterno'=>'ORTEGA'])];
$t = $exp->export($u, 'txt');
check('UIF: solicitante, persona física, moral and representante RFC/CURP → uppercase', !preg_match('/oeea651127|icj950101|oeet621022/', $t) && str_contains($t, 'OEEA651127CFA|OEEA651127HVZRRR09') && str_contains($t, 'ICJ950101AB1') && str_contains($t, 'OEET621022IH3|OEET621022MDFRSR01'));
check('UIF: a lowercase moral RFC is still classified as moral (930012)', str_contains($line($t, '930012') ?? '', 'ICJ950101AB1'));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
}
