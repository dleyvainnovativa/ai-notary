<?php
// UIF fideicomiso (930009/930010 comprador, 930014/930015 vendedor) export + import round-trip.
// Layout is INFERRED from the official DECLARANOT guide (not byte-verified vs a real SAT
// fideicomiso file). This test guards the structure, column counts, rep linking, round-trip
// stability, and that non-fideicomiso files keep emitting the same empty placeholders.
// Run: php tests/manual/FideicomisoScenarios.php
namespace Carbon { class Carbon { private \DateTimeImmutable $d; function __construct($d){ $this->d=$d; }
  static function parse($x){ return new self($x instanceof self ? $x->d : new \DateTimeImmutable((string)$x)); }
  function format($f){ return $this->d->format($f); } function __toString(){ return $this->d->format('Y-m-d'); } } }
namespace {
function now(){ return Carbon\Carbon::parse('2026-09-25'); }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
foreach (['Modules/ExporterContract','Modules/ImporterContract','Modules/ImportResult','Modules/ImportException','Services/Import/TxtDecoder'] as $f) require "$R/app/$f.php";
require "$R/modules/avisos_uif/v1/Exporter.php";
require "$R/modules/avisos_uif/v1/Importer.php";

use Modules\AvisosUif\V1\Exporter;
use Modules\AvisosUif\V1\Importer;

$fail = 0;
function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }

$dom = ['tipo_domicilio'=>'1','entidad_federativa'=>'30','calle'=>'DOS','num_ext'=>'24','num_int'=>null,'codigo_postal'=>'95750','colonia'=>'Laguna Encantada','municipio'=>'SAN ANDRES TUXTLA'];
// From the user's payload
$fideicomiso = ['tipo_persona'=>'3','rfc'=>'DAN240317DQ1','razon_social'=>'DANHOTECH','numero_fideicomiso'=>'123456',
  'curp'=>null,'fecha_nacimiento'=>null,'nombre'=>null,'apellido_paterno'=>null,'apellido_materno'=>null,
  'actividad_economica'=>null,'fecha_constitucion'=>null,'giro_mercantil'=>null,'nacionalidad'=>null,
  'representante'=>['rfc'=>'LECD9808164X7','curp'=>null,'fecha_nacimiento'=>'1998-08-16','nombre'=>'DIEGOS','apellido_paterno'=>'LEYBAS','apellido_materno'=>'CABALLOS'],
  'domicilio'=>$dom];
$fisica = ['tipo_persona'=>'1','rfc'=>'GAGA580413B21','curp'=>'GAGA580413MCLRRN04','fecha_nacimiento'=>'1958-04-13',
  'nombre'=>'MARIA DE LOS ANGELES','apellido_paterno'=>'GARCIA','apellido_materno'=>'GARCIA','actividad_economica'=>'1000000','nacionalidad'=>'MX','domicilio'=>$dom];

$inmueble = ['tipo_bien'=>'2','valor_pactado'=>2100000,'m2_terreno'=>112.19,'m2_construidos'=>1200,'folio_real'=>'9700','num_instrumento'=>'26763','valor_avaluo'=>1200,
  'domicilio'=>['entidad_federativa'=>'30','calle'=>'BETO AVILA','num_ext'=>'144','num_int'=>null,'codigo_postal'=>'94290','colonia'=>'Camino Real','municipio'=>'BOCA DEL RIO']];
$avis = fn($adq,$ven) => ['referencia_aviso'=>'26763','prioridad'=>'1','tipo_alerta'=>'100','descripcion_alerta'=>null,'operaciones_acumuladas'=>'2',
  'solicitante'=>['rfc'=>'OEEA651127CFA','curp'=>'OEEA651127HVZYBN01','fecha_nacimiento'=>'1965-11-27','nombre'=>'ARTURO','apellido_paterno'=>'ORTEGA','apellido_materno'=>'ESCUDERO'],
  'operaciones'=>[['fecha_operacion'=>'2026-07-30','tipo_transmision'=>'1','adquirentes'=>$adq,'vendedores'=>$ven,'inmueble'=>$inmueble,
    'pagos'=>[['fecha_pago'=>'2026-07-30','forma_pago'=>'1','instrumento'=>'8','moneda'=>'1','monto'=>2100000]]]],
  '_notaria'=>['clave'=>'001','entidad'=>'030','num_notaria'=>'36']];

$exp = new Exporter();
$imp = new Importer();
$lineOf = fn($txt,$tag) => array_values(array_filter(explode("\r\n", $txt), fn($l)=>str_starts_with($l,$tag)));
$body = fn($line) => substr($line, strpos($line,':')+1);
$cols = fn($line) => explode('|', substr($line, strpos($line,':')+1));

echo "Fideicomiso as ADQUIRENTE (930009/930010), física as vendedor\n";
$txt = mb_convert_encoding($exp->export($avis([$fideicomiso], [$fisica]), 'txt'), 'UTF-8', 'Windows-1252');
$l9  = $lineOf($txt,'930009')[0];
$l10 = $lineOf($txt,'930010')[0];
check('930009 has exactly 25 columns', count($cols($l9)) === 25);
check('930010 has exactly 7 columns',  count($cols($l10)) === 7);
check('930009 carries RFC, razón social, número fideicomiso', str_contains($l9,'DAN240317DQ1') && str_contains($l9,'DANHOTECH') && str_contains($l9,'123456'));
check('930009 col order: rfc|razon|numero|tipo_dom', implode('|', array_slice($cols($l9),0,4)) === 'DAN240317DQ1|DANHOTECH|123456|1');
check('930010 carries the apoderado (rep) data', str_contains($l10,'LECD9808164X7') && str_contains($l10,'DIEGOS') && str_contains($l10,'LEYBAS'));
check('vendedor fideicomiso 930014 is the empty placeholder (25 cols)', count($cols($lineOf($txt,'930014')[0])) === 25 && trim($body($lineOf($txt,'930014')[0]),'|') === '');
check('vendedor física 930011 is populated', str_contains($lineOf($txt,'930011')[0],'GAGA580413B21'));

echo "\nRound-trip: export → import → re-export is byte-identical\n";
$re = $exp->export($imp->parse($txt)->data + ['_notaria'=>['clave'=>'001','entidad'=>'030','num_notaria'=>'36']], 'txt');
$re = mb_convert_encoding($re, 'UTF-8', 'Windows-1252');
check('fideicomiso adquirente round-trips byte-for-byte', $re === $txt);

echo "\nFideicomiso as VENDEDOR (930014/930015)\n";
$txt2 = mb_convert_encoding($exp->export($avis([$fisica], [$fideicomiso]), 'txt'), 'UTF-8', 'Windows-1252');
check('930014 populated (25 cols) with fideicomiso', count($cols($lineOf($txt2,'930014')[0]))===25 && str_contains($lineOf($txt2,'930014')[0],'DANHOTECH'));
check('930015 populated with apoderado', str_contains($lineOf($txt2,'930015')[0],'LECD9808164X7'));
check('930009 empty placeholder when no comprador fideicomiso', trim($body($lineOf($txt2,'930009')[0]),'|') === '');
$re2 = $exp->export($imp->parse($txt2)->data + ['_notaria'=>['clave'=>'001','entidad'=>'030','num_notaria'=>'36']], 'txt');
check('fideicomiso vendedor round-trips byte-for-byte', mb_convert_encoding($re2,'UTF-8','Windows-1252') === $txt2);

echo "\nRegression: no fideicomiso → placeholders unchanged (24 and 6 pipes)\n";
$txt3 = mb_convert_encoding($exp->export($avis([$fisica], [$fisica]), 'txt'), 'UTF-8', 'Windows-1252');
check('930009 placeholder = 24 pipes', $body($lineOf($txt3,'930009')[0]) === str_repeat('|',24));
check('930010 placeholder = 6 pipes',  $body($lineOf($txt3,'930010')[0]) === str_repeat('|',6));
check('930014 placeholder = 24 pipes', $body($lineOf($txt3,'930014')[0]) === str_repeat('|',24));
check('930015 placeholder = 6 pipes',  $body($lineOf($txt3,'930015')[0]) === str_repeat('|',6));

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
}
