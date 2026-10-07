<?php
// DeclaraNOT por adquisición de bienes (910xxx). Run: php tests/manual/DeclaranotAdquisicionScenarios.php
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
function data_get($a,$path){ foreach(explode('.',$path) as $k){ if(!is_array($a)||!array_key_exists($k,$a)) return null; $a=$a[$k]; } return $a; }
function resource_path($p){ return (getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2)) . "/resources/$p"; }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
foreach (['Modules/ModuleControllerContract','Modules/ModuleInput','Modules/ExporterContract','Modules/ImporterContract','Modules/ImportResult','Modules/ImportException',
  'Services/Import/TxtDecoder','Services/Import/ImportService','Services/Schema/IdDates','Services/Schema/ValidationIssue','Services/Schema/EngineResult','Services/Schema/SchemaEngine','Services/Pdf/ReviewPdfPresenter'] as $f) require "$R/app/$f.php";
$A = "$R/modules/declaranot_adquisicion/v1";
require "$A/Controller.php"; require "$A/Exporter.php"; require "$A/Importer.php";
require_once "$R/modules/declaranot/v1/Importer.php"; require_once "$R/modules/declaranot/v1/Exporter.php";
use App\Services\Import\{ImportService, TxtDecoder};
use Modules\DeclaranotAdquisicion\V1\{Exporter, Importer, Controller};
$fail = 0; function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }

// SAT's published example (transcribed from the layout image).
$SAT = <<<TXT
Configuracion:2021|001|035|25|15/07/2021
910001-DatosOperacion:SDJHGFDE3456|15/07/2021|9|2563789|2563789|MUEBLES
910002-DatosAdquirientes-grid:1|DOMC88092145A|||||||||
910002-DatosAdquirientes-grid:1|FGI960808ML5||||SOCIEDAD MERCANTIL||||
910002-DatosAdquirientes-grid:2|EXTF900101000|JOSE|GARCIA|DELGADO|||CW|22/10/1986|DOCUMENTO|F50500500
910002-DatosAdquirientes-grid:2|EXT990101000||||AMERICA CENTRAL|AF|||
910003-DatosEnajenantes-grid:1|MIGE881102TB3|||||||||
910003-DatosEnajenantes-grid:1|AEM110122KEE||||DESARROLLO SUSTENTABLE||||
910003-DatosEnajenantes-grid:2|EXTF900101000|SERGIO|MARTINEZ|ANGELES|||AF|22/10/1989|PASAPORTE|01010000
910003-DatosEnajenantes-grid:2|EXT990101000||||MERCANTIL CENTRO|DE|||
910004-DatosPago-grid:2546325|200000|PT70100|15/07/2021
910005-IngresoCopropiedadOSucesion:1
910011-PreguntaExisteRepresentanteLegal:1
910006-RepresentanteLegal:CAGV710401TX5
910007-DatosCopropiedad-grid:CUME770108DE2|50|1273163|1273163|636581|100000
910007-DatosCopropiedad-grid:CEGB710502TY5|50|1273163|1273163|636581|100000
910009-TotalPorcentajeCopropiedad:
TXT;

$exp = new Exporter(); $imp = new Importer(); $svc = new ImportService();

echo "Import the SAT example\n";
$res = $svc->import($SAT, $imp, $exp);
$d = $res->data;
check('operación: escritura, fecha, tipo 9, avalúo, monto, especifica', $d['numero_escritura']==='SDJHGFDE3456' && $d['fecha_firma_escritura']==='2021-07-15' && $d['tipo_inmueble']==='9' && $d['avaluo_inmueble']==='2563789' && $d['monto_operacion']==='2563789' && $d['especifica_inmueble']==='MUEBLES');
check('4 adquirientes + 4 enajenantes', count($d['adquirientes'])===4 && count($d['enajenantes'])===4);
check('10-column moral row: razón social lands in razon_social (CURP empty)', $d['adquirientes'][1]['razon_social']==='SOCIEDAD MERCANTIL' && $d['adquirientes'][1]['curp']===null && $d['enajenantes'][3]['razon_social']==='MERCANTIL CENTRO' && $d['enajenantes'][3]['nacionalidad']==='DE');
check('extranjero física: nacionalidad + birth date dd/mm/yyyy → Y-m-d + doc + folio', $d['adquirientes'][2]['nacionalidad']==='CW' && $d['adquirientes'][2]['fecha_nacimiento']==='1986-10-22' && $d['adquirientes'][2]['folio']==='F50500500');
check('tipo keys by role', $d['adquirientes'][0]['tipo_adquiriente']==='1' && $d['enajenantes'][0]['tipo_enajenante']==='1');
check('pago ISR (folio keeps letters, fecha parsed)', $d['pago'][0]==['ingreso_acumulable'=>'2546325','isr_federacion'=>'200000','numero_operacion'=>'PT70100','fecha_pago'=>'2021-07-15']);
check('copropiedad 2 integrantes + representante', $d['copropiedad']['existe_copropiedad']==='1' && count($d['copropiedad']['integrantes'])===2 && $d['copropiedad']['integrantes'][1]['valor_avaluo']==='1273163' && $d['representante_comun']==['existe_representante_comun'=>'1','rfc_representante'=>'CAGV710401TX5']);
check('Configuracion read as declaration type 25 (not as notaría)', ($res->meta['configuracion']['tipo'] ?? null)==='25' && empty($res->meta['notaria']));
$out = TxtDecoder::lines($exp->export($d, 'txt')); $in = TxtDecoder::lines($SAT);
$diff = []; foreach ($in as $i => $l) if (($out[$i] ?? null) !== $l) $diff[] = $i + 1;
check('re-export differs only in the 4 moral rows (11 cols) and 910009 (sum)', $diff === [4, 6, 8, 10, 17] && count($out) === count($in));
echo "    4: {$out[3]}\n   17: {$out[16]}\n";
check('…which the import reports as a round-trip warning', $res->meta['round_trip'] === false && str_contains(end($res->warnings)['message'], '5 línea(s)'));

echo "Byte-identical round trip of our own export\n";
$mine = $exp->export($d, 'txt');
$r2 = $svc->import($mine, $imp, $exp);
check('export → import → export is identical, no warnings', $r2->meta['round_trip'] === true && $r2->warnings === [] && $exp->export($r2->data, 'txt') === $mine);
check('UTF-8 + LF, accents kept', str_contains($exp->export(['fecha_firma_escritura'=>'2025-01-02','enajenantes'=>[['tipo_enajenante'=>'1','rfc'=>'X','razon_social'=>'CAMIÑO ÁGIL']]],'txt'), "CAMIÑO ÁGIL") && !str_contains($mine, "\r"));

echo "Switch: moral rows without CURP column\n";
$alt = new class extends Exporter { public const MORAL_ROWS_WITHOUT_CURP = true; };
$altOut = TxtDecoder::lines($alt->export($d, 'txt'));
$d2 = []; foreach ($in as $i => $l) if (($altOut[$i] ?? null) !== $l) $d2[] = $i + 1;
check('with the switch on, only 910009 differs from the SAT example', $d2 === [17]);

echo "Empty / gratuitous case\n";
$min = ['numero_escritura'=>'100','fecha_firma_escritura'=>'2026-03-09','tipo_inmueble'=>'1','avaluo_inmueble'=>1500000.0,'monto_operacion'=>0,
  'adquirientes'=>[['tipo_adquiriente'=>'1','rfc'=>'LECD9808164X7','nombre'=>'DANIEL','apellido_paterno'=>'LEYVA','apellido_materno'=>'CABALLERO','curp'=>'LECD980816HVZYBN01','fecha_nacimiento'=>'1998-08-16']],
  'enajenantes'=>[], 'pago'=>[], 'copropiedad'=>['existe_copropiedad'=>null,'integrantes'=>[]], 'representante_comun'=>[]];
$t = TxtDecoder::lines($exp->export($min, 'txt'));
check('Configuracion type 25 + year', $t[0] === 'Configuracion:2026|001|035|25|09/03/2026');
check('monto 0 printed, float avalúo printed as integer, especifica empty', $t[1] === '910001-DatosOperacion:100|09/03/2026|1|1500000|0|');
check('nacional física with birth date dd/mm/yyyy', $t[2] === '910002-DatosAdquirientes-grid:1|LECD9808164X7|DANIEL|LEYVA|CABALLERO|LECD980816HVZYBN01|||16/08/1998||');
check('defaults 2/2, empty representante, placeholders', array_slice($t, 3) === ['910005-IngresoCopropiedadOSucesion:2','910011-PreguntaExisteRepresentanteLegal:2','910006-RepresentanteLegal:','910007-DatosCopropiedad-grid:|||||','910009-TotalPorcentajeCopropiedad:']);
$r3 = $svc->import(implode("\n", $t), $imp, $exp);
check('…and it round-trips', $r3->meta['round_trip'] === true && $r3->data['pago'] === [] && $r3->data['copropiedad']['integrantes'] === []);
$withCop = $min; $withCop['copropiedad'] = ['existe_copropiedad'=>'1','integrantes'=>[['rfc'=>'A','porcentaje'=>33.5],['rfc'=>'B','porcentaje'=>66.5]]];
check('910009 = sum of porcentajes', str_ends_with($exp->export($withCop,'txt'), '910009-TotalPorcentajeCopropiedad:100'));

echo "Wrong files\n";
try { $imp->parse("930001-DatosIdentificacion:\n"); check('UIF file rejected', false); }
catch (App\Modules\ImportException $e) { check('UIF file rejected with an adquisición message', str_contains($e->getMessage(), 'adquisición')); }
try { (new Modules\Declaranot\V1\Importer())->parse($SAT); check('adquisición file rejected by DeclaraNOT', false); }
catch (App\Modules\ImportException $e) { check('adquisición file rejected by the DeclaraNOT importer', true); }
$w = $imp->parse(str_replace('|035|25|', '|035|24|', $SAT));
check('type 24 in Configuracion → warning', str_contains($w->warnings[0]['message'] ?? '', 'tipo de declaración 24'));

echo "Module wiring\n";
$ctrl = new Controller($A);
$inputs = $ctrl->inputs();
check('2 own inputs; prompt = shared rules + DeclaraNOT rules + adquisición note', count($inputs) === 2 && str_contains($inputs[0]->prompt($A), 'expert Mexican legal') && str_contains($inputs[0]->prompt($A), 'monto_operacion') && str_contains($inputs[0]->prompt($A), 'REFERENCIAS DENTRO DE LA ESCRITURA'));
$sc = $inputs[0]->schema($A)['fields'];
check('extraction schema: monto_operacion after avaluo; no pagos_inmueble / datos_informativos; nuda propiedad kept', array_keys($sc)[array_search('avaluo_inmueble', array_keys($sc)) + 1] === 'monto_operacion' && !isset($sc['pagos_inmueble']) && !isset($sc['datos_informativos']) && isset($sc['valor_calculo']));
check('cálculo schema: 4 pago fields, 6 integrante fields', array_keys($inputs[1]->schema($A)['fields']['pago']['items']) === ['ingreso_acumulable','isr_federacion','numero_operacion','fecha_pago'] && count($ctrl->schema()['fields']['copropiedad']['items']['integrantes']['items']) === 6);
check('output examples load', is_array($inputs[0]->outputExample($A)) && is_array($inputs[1]->outputExample($A)));
$fs = $ctrl->formSchema();
check('sections: General, Adquirientes, Enajenantes, ISR, Copropiedad, Representante', array_column($fs['sections'], 'title') === ['Información General','Adquirientes','Enajenantes','ISR por Adquisición','Copropiedad','Representante Común']);
$allFields = array_merge(...array_column($fs['sections'], 'fields'));
check('every section field exists and every field is in a section', !array_diff($allFields, array_keys($fs['fields'])) && !array_diff(array_keys($fs['fields']), $allFields));
check('pago / integrantes item schemas match the exporter columns', array_keys($fs['fields']['pago']['itemSchema']) === ['ingreso_acumulable','isr_federacion','numero_operacion','fecha_pago'] && array_keys($fs['fields']['copropiedad']['itemSchema']['integrantes']['itemSchema']) === ['rfc','porcentaje','monto_operacion','valor_avaluo','ingreso_acumulable','isr_federacion']);
check('catalogs inherited via "extends"', count($fs['fields']['tipo_inmueble']['options'] ?? []) > 3 && !is_dir("$A/catalogs"));
$engine = new App\Services\Schema\SchemaEngine(new App\Services\CatalogService);
$eng = $engine->process($fs, $d, $A);
check('SchemaEngine runs (existe_copropiedad stays 1)', ($eng->data['copropiedad']['existe_copropiedad'] ?? null) === '1');
$eng2 = $engine->process($fs, ['adquirientes'=>[['tipo_adquiriente'=>'1','rfc'=>'LECD9808164X7','curp'=>'LECD980816HVZYBN01']]], $A);
check('birth date derived from CURP', ($eng2->data['adquirientes'][0]['fecha_nacimiento'] ?? null) === '1998-08-16');
$pdf = (new App\Services\Pdf\ReviewPdfPresenter($engine))->present($fs, $eng->data);
check('PDF presenter renders all 6 sections', count($pdf) === 6);
$roles = Modules\DeclaranotAdquisicion\V1\Exporter::class;
check('old role-swap helper is gone', !file_exists("$A/Roles.php"));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
}
