<?php
namespace Carbon { class Carbon { private \DateTimeImmutable $d; function __construct($d){ $this->d=$d; }
  static function parse($x){ return new self($x instanceof self ? $x->d : new \DateTimeImmutable((string)$x)); }
  function format($f){ return $this->d->format($f); } function __toString(){ return $this->d->format('Y-m-d'); } } }
namespace App\Modules { interface ExporterContract { public function supportedFormats(): array; public function export(array $d, string $f): string; } }
namespace {
function now(){ return Carbon\Carbon::parse('2026-09-25'); }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
// Optional: ORIG_EXPORTER=/path/to/old/Exporter.php to prove single-op output is byte-identical
$ORIG = getenv('ORIG_EXPORTER') ?: (file_exists(__DIR__ . '/Exporter.v1-verified.php') ? __DIR__ . '/Exporter.v1-verified.php' : null);
// Load the original under another namespace so both can run side by side
eval('?>' . str_replace('namespace Modules\AvisosUif\V1;', 'namespace Orig;', file_get_contents($ORIG)));
require "$R/modules/avisos_uif/v1/Exporter.php";

$fail = 0;
function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }
$fis = fn($rfc,$n) => ['tipo_persona'=>'1','rfc'=>$rfc,'curp'=>'XEXX010101HNEXXXA4','fecha_nacimiento'=>'1965-11-27','nombre'=>$n,'apellido_paterno'=>'P','apellido_materno'=>'M','nacionalidad'=>'MX','actividad_economica'=>'8111000','domicilio'=>['tipo_domicilio'=>'1','entidad_federativa'=>'30','calle'=>'AZALEAS','num_ext'=>'489','num_int'=>null,'codigo_postal'=>'91948','colonia'=>'Flores del Valle','municipio'=>'VERACRUZ']];
$mor = fn($rfc,$rs) => ['tipo_persona'=>'1','rfc'=>$rfc,'razon_social'=>$rs,'fecha_constitucion'=>'1995-01-01','nacionalidad'=>'MX','giro_mercantil'=>'1000000','representante'=>['rfc'=>'OEET621022IH3','curp'=>'OEET621022MDFRSR01','fecha_nacimiento'=>'1962-10-22','nombre'=>'MARIA TERESA','apellido_paterno'=>'ORTEGA','apellido_materno'=>'ESCUDERO'],'domicilio'=>['tipo_domicilio'=>'1','entidad_federativa'=>'30','calle'=>'CENTRO','num_ext'=>'1','codigo_postal'=>'91700','colonia'=>'Centro','municipio'=>'VERACRUZ']];
$op = fn($fecha, $adq, $ven, $monto) => ['fecha_operacion'=>$fecha,'tipo_transmision'=>'1','adquirentes'=>$adq,'vendedores'=>$ven,
  'inmueble'=>['tipo_bien'=>'3','valor_pactado'=>$monto,'m2_terreno'=>112.19,'m2_construidos'=>112.19,'folio_real'=>'5184','num_instrumento'=>'26763','valor_avaluo'=>0,'domicilio'=>['entidad_federativa'=>'30','calle'=>'BETO AVILA','num_ext'=>'144','num_int'=>'204','codigo_postal'=>'94294','colonia'=>'Vista Alegre','municipio'=>'BOCA DEL RIO']],
  'pagos'=>[['fecha_pago'=>$fecha,'forma_pago'=>'1','instrumento'=>'8','moneda'=>'1','monto'=>$monto*0.1],['fecha_pago'=>$fecha,'forma_pago'=>'2','instrumento'=>'8','moneda'=>'1','monto'=>$monto*0.9]]];
$base = ['referencia_aviso'=>'26763','prioridad'=>'1','tipo_alerta'=>'100','descripcion_alerta'=>null,
  'solicitante'=>['rfc'=>'OEEA651127CFA','curp'=>'OEEA651127HVZRRR09','fecha_nacimiento'=>'1965-11-27','nombre'=>'ARTURO','apellido_paterno'=>'ORTEGA','apellido_materno'=>'ESCUDERO'],
  '_notaria'=>['clave'=>'001','entidad'=>'030','num_notaria'=>'36']];

$orig = new Orig\Exporter(); $new = new Modules\AvisosUif\V1\Exporter();
echo "Single operation — must be byte-identical to the verified exporter\n";
$cases = [
  'físicas only' => [$op('2026-07-30', [$fis('OEEA651127CFA','ARTURO')], [$fis('SARE570420CQ7','EDUARDO'), $fis('GAGA580413B21','MARIA')], 2100000)],
  'morales both sides (GUIDs used)' => [$op('2026-07-30', [$mor('ICJ950101AB1','INMOBILIARIA JUBRISA SA DE CV'), $fis('OEEA651127CFA','ARTURO')], [$mor('BNM840515VB1','BANCO X SA'), $mor('ABC150101AB1','OTRA SA')], 3500000)],
  'no personas at all' => [$op('2026-07-30', [], [], 100)],
];
foreach ($cases as $label => $ops) {
  $d = $base + ['operaciones' => $ops];
  check("$label", $orig->export($d, 'txt') === $new->export($d, 'txt'));
}

echo "Multiple operations (operaciones acumuladas)\n";
$d = $base + ['operaciones' => [
  $op('2026-07-30', [$mor('ICJ950101AB1','INMOBILIARIA JUBRISA SA DE CV')], [$mor('BNM840515VB1','BANCO X SA')], 2100000),
  $op('2026-08-15', [$mor('ICJ950101AB1','INMOBILIARIA JUBRISA SA DE CV')], [$fis('SARE570420CQ7','EDUARDO')], 900000),
  $op('2026-09-01', [$mor('ICJ950101AB1','INMOBILIARIA JUBRISA SA DE CV')], [$mor('ABC150101AB1','OTRA SA')], 500000),
]];
$txt = mb_convert_encoding($new->export($d, 'txt'), 'UTF-8', 'Windows-1252');
$lines = explode("\r\n", $txt);
preg_match_all('/\d{8}-0000-\d{4}-0000-\d{12}/', $txt, $m);
$opGuids = []; $morGuids = []; $inmGuids = []; $liqLinks = [];
foreach ($lines as $ln) {
  if (str_starts_with($ln, '930005')) $opGuids[] = explode('|', $ln)[2];
  if (preg_match('/^93000[7]|^930012/', $ln)) { preg_match_all('/\d{8}-0000-\d{4}-0000-\d{12}/', $ln, $g); $morGuids[] = end($g[0]); }
  if (str_starts_with($ln, '930016')) { $c = explode('|', $ln); $inmGuids[] = end($c); }
  if (str_starts_with($ln, '930017')) { $c = explode('|', $ln); $liqLinks[] = end($c); }
}
check('930004 counts 3 operations', in_array('930004-DetalleOperaciones:3', $lines, true));
check('3 operation GUIDs, all distinct', count($opGuids) === 3 && count(array_unique($opGuids)) === 3);
check('persona moral GUIDs distinct and never equal an operation GUID', count(array_unique($morGuids)) === count($morGuids) && !array_intersect($morGuids, $opGuids));
check('3 inmueble GUIDs, all distinct', count(array_unique($inmGuids)) === 3);
check('each operation\'s liquidaciones point to ITS inmueble', $liqLinks === [$inmGuids[0], $inmGuids[0], $inmGuids[1], $inmGuids[1], $inmGuids[2], $inmGuids[2]]);
echo "  op GUIDs:  " . implode('  ', array_map(fn($g) => substr($g, -4), $opGuids)) . "\n";
echo "  moral:     " . implode('  ', array_map(fn($g) => substr($g, -4), $morGuids)) . "\n";
echo "  inmueble:  " . implode('  ', array_map(fn($g) => substr($g, 14, 4) . '…' . substr($g, -4), $inmGuids)) . "\n";
$origTxt = mb_convert_encoding($orig->export($d, 'txt'), 'UTF-8', 'Windows-1252');
$oldInm = [];
foreach (explode("\r\n", $origTxt) as $ln) if (str_starts_with($ln, '930016')) { $c = explode('|', $ln); $oldInm[] = end($c); }
check('(old exporter really had the bug: 3 operations, 1 shared inmueble GUID)', count($oldInm) === 3 && count(array_unique($oldInm)) === 1);
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
}
