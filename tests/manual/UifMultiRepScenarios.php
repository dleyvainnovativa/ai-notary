<?php
/**
 * UIF · multiple representantes legales per persona moral / fideicomiso.
 * Plain PHP. Run: php tests/manual/UifMultiRepScenarios.php
 *
 * SAT accepts N representantes per persona moral/fideicomiso: the 930008/930013
 * (and 930010/930015) grid links each rep row to its parent by person_guid.
 * Proves: export emits one rep row per representante sharing the parent GUID,
 * import reattaches them all, round-trip is byte-identical, and the legacy
 * single-object `representante` shape still exports.
 */

namespace Carbon { class Carbon { private $d; function __construct($d){$this->d=$d;} static function parse($x){ return new self(new \DateTimeImmutable((string)$x)); } function format($f){ return $this->d->format($f);} } }
namespace {
function now(){ return Carbon\Carbon::parse('2026-09-25'); }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
require_once "$R/app/Support/ExportDate.php";
foreach (['Modules/ExporterContract','Modules/ImporterContract','Modules/ImportResult','Modules/ImportException','Services/Import/TxtDecoder','Services/Import/ImportService'] as $f) require "$R/app/$f.php";
require "$R/modules/avisos_uif/v1/Importer.php";   // pulls its Exporter

use App\Services\Import\ImportService;

$fail = 0;
function check($l, $c) { global $fail; echo ($c ? '  PASS ' : '  FAIL ') . "$l\n"; if (!$c) $fail++; }
$svc = new ImportService();
$exp = new Modules\AvisosUif\V1\Exporter();
$imp = new Modules\AvisosUif\V1\Importer();
$lines = fn($txt, $tag) => array_values(array_filter(preg_split('/\r?\n/', $txt), fn($l) => str_starts_with($l, $tag)));

$dom = ['tipo_domicilio'=>'1','entidad_federativa'=>'9','calle'=>'REFORMA','num_ext'=>'222','codigo_postal'=>'06600','colonia'=>'Juárez','municipio'=>'CUAUHTÉMOC'];
$rep = fn($rfc,$curp,$fn,$n,$ap,$am) => ['rfc'=>$rfc,'curp'=>$curp,'fecha_nacimiento'=>$fn,'nombre'=>$n,'apellido_paterno'=>$ap,'apellido_materno'=>$am];
$rep1 = $rep('OEET621022IH3','OEET621022MDFRSR01','1962-10-22','MARIA TERESA','ORTEGA','ESCUDERO');
$rep2 = $rep('LECD9808164X7',null,'1998-08-16','DIEGO','LEYVA','CABALLERO');
$moral = fn($reps) => ['tipo_persona'=>'2','rfc'=>'ICJ950101AB1','razon_social'=>'INMOBILIARIA JUBRISA SA DE CV','fecha_constitucion'=>'1995-01-01','nacionalidad'=>'MX','giro_mercantil'=>'1000000','domicilio'=>$dom,'representantes'=>$reps];
$fis = ['tipo_persona'=>'1','rfc'=>'OEEA651127CFA','curp'=>'OEEA651127HVZRRR09','fecha_nacimiento'=>'1965-11-27','nombre'=>'ARTURO','apellido_paterno'=>'ORTEGA','apellido_materno'=>'ESCUDERO','nacionalidad'=>'MX','actividad_economica'=>'8111000','domicilio'=>$dom];
$op = fn($adq,$ven) => ['fecha_operacion'=>'2026-01-02','tipo_transmision'=>'1','adquirentes'=>$adq,'vendedores'=>$ven,
  'inmueble'=>['tipo_bien'=>'3','valor_pactado'=>1000000,'valor_avaluo'=>0,'domicilio'=>$dom],'pagos'=>[['fecha_pago'=>'2026-01-02','forma_pago'=>'2','instrumento'=>'8','moneda'=>'1','monto'=>1000000]]];
$base = fn($op) => ['referencia_aviso'=>'1','prioridad'=>'1','tipo_alerta'=>'100','_notaria'=>['clave'=>'001','entidad'=>'030','num_notaria'=>'36'],
  'solicitante'=>['rfc'=>'SARE570420CQ7','curp'=>'SARE570420HNENMD02','fecha_nacimiento'=>'1957-04-20','nombre'=>'EDUARDO','apellido_paterno'=>'SAN MARTIN','apellido_materno'=>'ROMERO'],'operaciones'=>[$op]];

echo "== export: two representantes → two 930013 rows, same parent GUID ==\n";
$data = $base($op([$fis], [$moral([$rep1, $rep2])]));
$txt = $exp->export($data, 'txt');
$repRows = $lines($txt, '930013');
check('two 930013 rep rows emitted', count($repRows) === 2);
$moralRow = $lines($txt, '930012')[0] ?? '';
$guid = array_values(array_slice(explode('|', $moralRow), -1))[0] ?? 'X';   // person_guid is the last column
check('parent moral row carries a GUID', trim($guid) !== '');
check('both rep rows reference the same parent GUID', str_ends_with($repRows[0], $guid) && str_ends_with($repRows[1], $guid));
check('rep rows carry each representante', str_contains($repRows[0], 'MARIA TERESA') && str_contains($repRows[1], 'DIEGO'));

echo "== round-trip: import reattaches both, re-export byte-identical ==\n";
$res = $svc->import($txt, $imp, $exp);
$m = $res->data['operaciones'][0]['vendedores'][0];
check('imported moral has 2 representantes', count($m['representantes'] ?? []) === 2);
check('names preserved in order', ($m['representantes'][0]['nombre'] ?? '') === 'MARIA TERESA' && ($m['representantes'][1]['nombre'] ?? '') === 'DIEGO');
check('no "sin persona moral" warning (GUID linkage held)', !array_filter($res->warnings, fn($w) => str_contains($w['message'], 'sin persona moral')));
$again = $exp->export($res->data + ['_notaria' => $res->meta['notaria'] ?? null], 'txt');
check('export → import → export byte-identical', $again === $txt);

echo "== legacy single-object `representante` still exports ==\n";
$legacy = $base($op([$fis], [['tipo_persona'=>'2','rfc'=>'ICJ950101AB1','razon_social'=>'INMOBILIARIA JUBRISA SA DE CV','fecha_constitucion'=>'1995-01-01','nacionalidad'=>'MX','giro_mercantil'=>'1000000','domicilio'=>$dom,'representante'=>$rep1]]));
$lt = $exp->export($legacy, 'txt');
check('legacy representante → one filled 930013 row', count($lines($lt, '930013')) === 1 && str_contains($lines($lt, '930013')[0], 'MARIA TERESA'));

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
}
