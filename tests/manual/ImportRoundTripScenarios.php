<?php
namespace Carbon { class Carbon { private $d; function __construct($d){$this->d=$d;} static function parse($x){ return new self(new \DateTimeImmutable((string)$x)); } function format($f){ return $this->d->format($f);} } }
namespace {
function now(){ return Carbon\Carbon::parse('2026-09-25'); }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
foreach (['Modules/ExporterContract','Modules/ImporterContract','Modules/ImportResult','Modules/ImportException','Services/Import/TxtDecoder','Services/Import/ImportService'] as $f) require "$R/app/$f.php";
require "$R/modules/avisos_uif/v1/Importer.php";      // requires its Exporter
require "$R/modules/declaranot/v1/Exporter.php";
require "$R/modules/declaranot/v1/Importer.php";
use App\Services\Import\ImportService;
$fail = 0;
function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }
$svc = new ImportService();
$uExp = new Modules\AvisosUif\V1\Exporter(); $uImp = new Modules\AvisosUif\V1\Importer();
$dExp = new Modules\Declaranot\V1\Exporter(); $dImp = new Modules\Declaranot\V1\Importer();

// ---------- UIF fixtures ----------
$dom = fn($calle,$cp,$col,$mun) => ['tipo_domicilio'=>'1','entidad_federativa'=>'30','calle'=>$calle,'num_ext'=>'24','num_int'=>null,'codigo_postal'=>$cp,'colonia'=>$col,'municipio'=>$mun];
$fis = fn($rfc,$curp,$fn,$n,$ap,$am,$d) => ['tipo_persona'=>'1','rfc'=>$rfc,'curp'=>$curp,'fecha_nacimiento'=>$fn,'nombre'=>$n,'apellido_paterno'=>$ap,'apellido_materno'=>$am,'nacionalidad'=>'MX','actividad_economica'=>'8111000','domicilio'=>$d];
$mor = fn($rfc,$rs,$rep=null) => ['tipo_persona'=>'2','rfc'=>$rfc,'razon_social'=>$rs,'fecha_constitucion'=>'1995-01-01','nacionalidad'=>'MX','giro_mercantil'=>'1000000','domicilio'=>['tipo_domicilio'=>'1','entidad_federativa'=>'9','calle'=>'REFORMA','num_ext'=>'222','codigo_postal'=>'06600','colonia'=>'Juárez','municipio'=>'CUAUHTÉMOC'],'representante'=>$rep];
$op = fn($fecha,$adq,$ven,$monto,$ref='26763') => ['fecha_operacion'=>$fecha,'tipo_transmision'=>'1','adquirentes'=>$adq,'vendedores'=>$ven,
  'inmueble'=>['tipo_bien'=>'3','valor_pactado'=>$monto,'m2_terreno'=>112.19,'m2_construidos'=>112.19,'folio_real'=>'5184','num_instrumento'=>$ref,'valor_avaluo'=>0,'domicilio'=>['entidad_federativa'=>'30','calle'=>'BETO ÁVILA ESQUINA VEINTIDÓS DE MARZO','num_ext'=>'144','num_int'=>'204','codigo_postal'=>'94294','colonia'=>'Vista Alegre','municipio'=>'BOCA DEL RÍO']],
  'pagos'=>[['fecha_pago'=>$fecha,'forma_pago'=>'2','instrumento'=>'8','moneda'=>'1','monto'=>$monto*0.1],['fecha_pago'=>$fecha,'forma_pago'=>'2','instrumento'=>'8','moneda'=>'1','monto'=>$monto*0.9]]];
$base = ['referencia_aviso'=>'26763','prioridad'=>'1','tipo_alerta'=>'100','descripcion_alerta'=>null,
  'solicitante'=>['rfc'=>'SARE570420CQ7','curp'=>'SARE570420HNENMD02','fecha_nacimiento'=>'1957-04-20','nombre'=>'EDUARDO','apellido_paterno'=>'SAN MARTÍN','apellido_materno'=>'ROMERO'],
  '_notaria'=>['clave'=>'001','entidad'=>'030','num_notaria'=>'36']];
$arturo = $fis('OEEA651127CFA','OEEA651127HVZRRR09','1965-11-27','ARTURO','ORTEGA','ESCUDERO',$dom('CALLE AZALEAS','91948','Flores del Valle','VERACRUZ'));
$eduardo = $fis('SARE570420CQ7','SARE570420HNENMD02','1957-04-20','EDUARDO','SAN MARTÍN','ROMERO',$dom('CALLE DOS','95750','LAGUNA ENCANTADA','SAN ANDRÉS TUXTLA'));
$maria = $fis('GAGA580413B21','GAGA580413MCLRRN04','1958-04-13','MARÍA DE LOS ÁNGELES','GARCÍA','GARCÍA',$dom('CALLE DOS','95750','LAGUNA ENCANTADA','SAN ANDRÉS TUXTLA'));
$foreign = $fis('EXTF900101000',null,'1970-05-05','JOHN','SMITH',null,['tipo_domicilio'=>'2','calle'=>'MAIN ST','num_ext'=>'10','num_int'=>null,'codigo_postal'=>'77001','colonia'=>'DOWNTOWN','pais'=>'US','estado'=>'TX','ciudad'=>'HOUSTON','telefono'=>'7135550100','correo'=>'john@example.com']);
$rep = ['rfc'=>'OEET621022IH3','curp'=>'OEET621022MDFRSR01','fecha_nacimiento'=>'1962-10-22','nombre'=>'MARÍA TERESA','apellido_paterno'=>'ORTEGA','apellido_materno'=>'ESCUDERO'];

$roundTrip = function ($exp, $imp, array $data, string $label) use ($svc) {
  $file = $exp->export($data, 'txt');
  $res = $svc->import($file, $imp, $exp);
  $again = $exp->export($res->data + ['_notaria' => $res->meta['notaria'] ?? null], 'txt');
  check("$label: export → import → export is byte-identical", $again === $file);
  check("$label: round-trip flag set by ImportService", $res->meta['round_trip'] === true);
  return $res;
};

echo "UIF\n";
$r = $roundTrip($uExp, $uImp, $base + ['operaciones'=>[$op('2026-07-30', [$arturo], [$eduardo, $maria], 2100000)]], 'deed 26763 (físicas, accents in Windows-1252)');
check('no warnings for a clean file', $r->warnings === []);
$d = $r->data;
check('accents/Ñ removed from values (MARIA DE LOS ANGELES / BOCA DEL RIO)', $d['operaciones'][0]['vendedores'][1]['nombre'] === 'MARIA DE LOS ANGELES' && $d['operaciones'][0]['inmueble']['domicilio']['municipio'] === 'BOCA DEL RIO');
check('dates back to Y-m-d', $d['operaciones'][0]['fecha_operacion'] === '2026-07-30' && $d['solicitante']['fecha_nacimiento'] === '1957-04-20');
check('amounts back to numbers', $d['operaciones'][0]['pagos'][1]['monto'] == 1890000 && $d['operaciones'][0]['inmueble']['m2_terreno'] == 112.19);
check('notaría read from Configuracion', $r->meta['notaria'] === ['clave'=>'001','entidad'=>'030','num_notaria'=>'36']);
check('single op → acumuladas = No (2)', $d['operaciones_acumuladas'] === '2');

$r = $roundTrip($uExp, $uImp, $base + ['operaciones'=>[$op('2026-07-30', [$mor('ICJ950101AB1','INMOBILIARIA JUBRISA SA DE CV',$rep), $arturo], [$mor('BNM840515VB1','BANCO X SA'), $foreign], 3500000)]], 'morales + representante + foreign address');
$o = $r->data['operaciones'][0];
// The TXT lists físicas (930006) before morales (930007) on each side, so that is the imported order.
$byTipo = fn($list, $t) => array_values(array_filter($list, fn($p) => $p['tipo_persona'] === $t));
check('físicas first, then morales (TXT record order)', $o['adquirentes'][0]['tipo_persona'] === '1' && $o['adquirentes'][1]['tipo_persona'] === '2');
$m = $byTipo($o['adquirentes'], '2')[0];
check('moral imported as tipo_persona 2 with razón social', $m['razon_social'] === 'INMOBILIARIA JUBRISA SA DE CV');
check('representante attached to its moral', ($m['representante']['nombre'] ?? null) === 'MARIA TERESA');
check('colonia keeps its accent (Juárez) — SAT catalog spelling', $m['domicilio']['colonia'] === 'Juárez');
$f = $byTipo($o['vendedores'], '1')[0];
check('foreign address kept (país/ciudad)', $f['domicilio']['tipo_domicilio'] === '2' && $f['domicilio']['ciudad'] === 'HOUSTON');
$msgs = implode(' | ', array_column($r->warnings, 'message'));
check('warns: form can\'t show the representante', str_contains($msgs, 'representante legal'));
check('warns: form has no país/teléfono/correo fields', str_contains($msgs, 'país') && str_contains($msgs, 'correo'));
check('warnings point at the right persona', in_array('operaciones.0.vendedores.0.domicilio', array_column($r->warnings, 'path'), true));

$r = $roundTrip($uExp, $uImp, $base + ['operaciones'=>[
  $op('2026-07-30', [$mor('ICJ950101AB1','INMOBILIARIA JUBRISA SA DE CV')], [$eduardo], 2100000),
  $op('2026-08-15', [$mor('ICJ950101AB1','INMOBILIARIA JUBRISA SA DE CV')], [$mor('BNM840515VB1','BANCO X SA')], 900000),
  $op('2026-09-01', [$arturo], [$maria], 500000)]], '3 operaciones acumuladas');
check('3 operations, acumuladas = Sí (1)', count($r->data['operaciones']) === 3 && $r->data['operaciones_acumuladas'] === '1');
check('pagos land on their own operation (via inmueble GUID)', $r->data['operaciones'][1]['pagos'][0]['monto'] == 90000 && $r->data['operaciones'][2]['pagos'][1]['monto'] == 450000);

echo "UIF — robustness\n";
$file = $uExp->export($base + ['operaciones'=>[$op('2026-07-30', [$arturo], [$maria], 2100000)]], 'txt');
$utf8lf = str_replace("\r\n", "\n", mb_convert_encoding($file, 'UTF-8', 'Windows-1252'));
$r = $svc->import("\xEF\xBB\xBF" . $utf8lf, $uImp, $uExp);
check('UTF-8 + BOM + LF version imports the same data', $r->data['operaciones'][0]['vendedores'][0]['nombre'] === 'MARIA DE LOS ANGELES' && $r->meta['round_trip'] === true);
$tampered = preg_replace('/(930005[^\r]*\|)(\d{8}-0000-0000-0000-)000000000001/', '${2}999999999999', $file, 1);
$tampered = str_replace('930005-Datos de la operacion-grid:30/07/2026|1|02676300-0000-0000-0000-000000000001', '930005-Datos de la operacion-grid:30/07/2026|1|ABCDEF00-0000-0000-0000-000000000001', $file);
$r = $svc->import($tampered, $uImp, $uExp);
check('file with foreign GUIDs → round_trip false + warning naming the lines', $r->meta['round_trip'] === false && str_contains(end($r->warnings)['message'], 'línea'));
try { $svc->import("900001-DatosOperacion:1|01/01/2026|1||0\n", $uImp, $uExp); check('declaranot file rejected by UIF', false); }
catch (App\Modules\ImportException $e) { check('DeclaraNOT file rejected by UIF importer with a clear message', str_contains($e->getMessage(), 'aviso UIF')); }

echo "DeclaraNOT\n";
$dec = ['numero_escritura'=>'26477','fecha_firma_escritura'=>'2025-12-23','tipo_inmueble'=>'4','especifica_inmueble'=>null,'avaluo_inmueble'=>'0',
  'pagos_inmueble'=>[['monto'=>'1277','tipo_pago_inmueble'=>'3','institucion_financiera'=>'044','numero_cuenta'=>'1','otro_pago'=>null,'otro'=>null],
                     ['monto'=>'300000','tipo_pago_inmueble'=>'3','institucion_financiera'=>'999','numero_cuenta'=>'2','otro_pago'=>'canadian imperial bank','otro'=>null]],
  'enajenantes'=>[['tipo_enajenante'=>'1','rfc'=>'ZADE470405SG7','nombre'=>'EMILIA','apellido_paterno'=>'ZÁRATE','apellido_materno'=>'DÍAZ','curp'=>'ZADE470405MVZRZM02','razon_social'=>null,'nacionalidad'=>null,'fecha_nacimiento'=>null,'documento_oficial'=>null,'folio'=>null]],
  'datos_informativos'=>['ingresos_exentos'=>'2','monto'=>null,'impuesto'=>null],
  'adquirientes'=>[['tipo_adquiriente'=>'2','rfc'=>'EXTF900101000','nombre'=>'JOHN','apellido_paterno'=>'SMITH','apellido_materno'=>null,'curp'=>null,'razon_social'=>null,'nacionalidad'=>'US','fecha_nacimiento'=>'1970-05-05','documento_oficial'=>'PASAPORTE','folio'=>'X123']],
  'pago'=>[['ingresos_enajenacion'=>'600001','ingresos_exentos'=>'0','ingreso_sismo_2017'=>'0','deducciones_autorizadas'=>'400000','ganancia_perdida'=>'200001','years_adquisicion_venta'=>'12','ganancia_acumulable'=>'16667','ganancia_no_acumulable'=>'183334','isr_federacion'=>'5000','numero_operacion_federacion'=>'ABC123','fecha_pago_federacion'=>'2025-12-23','isr_entidad'=>'1000','numero_operacion_entidad'=>'E77','fecha_pago_entidad'=>'2025-12-24','total_isr_pagado'=>'6000']],
  'copropiedad'=>['existe_copropiedad'=>'1','integrantes'=>[['rfc'=>'ZADE470405SG7','porcentaje'=>'50','ingresos_enajenacion'=>'300000','deducciones_autorizadas'=>'200000','ganancia_perdida'=>'100000','ganancia_acumulable'=>'8333','ganancia_no_acumulable'=>'91667','isr_federacion'=>'2500','isr_entidad'=>'500'],
                                                        ['rfc'=>'ABCD800101AB1','porcentaje'=>'50','ingresos_enajenacion'=>'300001','deducciones_autorizadas'=>'200000','ganancia_perdida'=>'100001','ganancia_acumulable'=>'8334','ganancia_no_acumulable'=>'91667','isr_federacion'=>'2500','isr_entidad'=>'500']]],
  'representante_comun'=>['existe_representante_comun'=>'1','rfc_representante'=>'ZADE470405SG7']];
$r = $roundTrip($dExp, $dImp, $dec, 'full declaranot (pagos, enajenante, extranjero, ISR, copropiedad)');
check('no warnings for a clean file', $r->warnings === []);
check('fields land in the right keys', $r->data['adquirientes'][0]['tipo_adquiriente'] === '2' && $r->data['pagos_inmueble'][1]['otro_pago'] === 'canadian imperial bank' && $r->data['pago'][0]['fecha_pago_entidad'] === '2025-12-24');
$noCop = $dec; $noCop['copropiedad'] = ['existe_copropiedad'=>'2','integrantes'=>[]]; $noCop['representante_comun'] = ['existe_representante_comun'=>'2','rfc_representante'=>null]; $noCop['datos_informativos'] = null;
$r = $roundTrip($dExp, $dImp, $noCop, 'no copropiedad / no datos informativos (placeholder lines)');
check('placeholder copropiedad line not imported as an integrante', $r->data['copropiedad']['integrantes'] === []);
$bad = str_replace('900011-TotalPorcentajeCopropiedad:100', '900011-TotalPorcentajeCopropiedad:90', $dExp->export($dec, 'txt'));
$r = $svc->import($bad, $dImp, $dExp);
check('inconsistent copropiedad total → warning', str_contains(implode(' ', array_column($r->warnings, 'message')), 'no coincide'));
try { $svc->import($file, $dImp, $dExp); check('UIF file rejected by declaranot', false); }
catch (App\Modules\ImportException $e) { check('UIF file rejected by DeclaraNOT importer with a clear message', str_contains($e->getMessage(), 'DeclaraNOT')); }
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
}
