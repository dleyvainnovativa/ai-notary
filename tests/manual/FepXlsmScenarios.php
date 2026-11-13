<?php
/**
 * FEP xlsm reader — map logic verified WITHOUT PhpSpreadsheet or a filled file,
 * using an in-memory ArrayCellSource whose cells mirror the official template's
 * layout (transcribed from Sheet1.cls). Proves: reader → review data → Exporter
 * → XSD-valid XML with the expected values.
 *
 * Run: php tests/manual/FepXlsmScenarios.php
 * (The real .xlsm path uses SpreadsheetCellSource + phpoffice/phpspreadsheet,
 *  which must be installed via composer on the deployment machine.)
 */

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/Services/Fep/FepValueFormatter.php';
require_once $ROOT . '/app/Services/Fep/FepXmlWriter.php';
require_once $ROOT . '/app/Services/Fep/FepPersonas.php';
require_once $ROOT . '/app/Services/Fep/FepXlsmCellSource.php';
require_once $ROOT . '/app/Services/Fep/FepXlsmReader.php';
require_once $ROOT . '/app/Modules/ExporterContract.php';
require_once $ROOT . '/modules/fep/v1/Exporter.php';
require_once $ROOT . '/modules/fep_avaluo/v1/Exporter.php';
require_once $ROOT . '/modules/fep_avaluo/v1/XlsmReader.php';
require_once $ROOT . '/modules/fep_cesion/v1/Exporter.php';
require_once $ROOT . '/modules/fep_cesion/v1/XlsmReader.php';
foreach (['fep_poder', 'fep_constitucionpm', 'fep_modifpatrimonial', 'fep_fusion', 'fep_mutuo', 'fep_escision', 'fep_compraventa', 'fep_constitucionfid'] as $m) {
    require_once $ROOT . "/modules/$m/v1/Exporter.php";
    require_once $ROOT . "/modules/$m/v1/XlsmReader.php";
}

use App\Services\Fep\ArrayCellSource;
use App\Services\Fep\FepXmlWriter;

$pass = 0; $fail = 0;
function ok($c, $l) { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "  ✗ FAIL: $l\n"; } }

echo "== Avaluo xlsm map (synthetic cells → XML) ==\n";
$cells = [
    'C4' => 'CAOM820309AM3', 'C5' => '201401', 'C6' => 'AAA111',
    'E6' => '1,NORMAL', 'C7' => '100,Sin alerta.', 'E7' => '',
    'B19' => 'MAR', 'C19' => 'CAS', 'D19' => 'OLI', 'E19' => '19820309', 'F19' => '', 'G19' => '',
    'C23' => '1234568790', 'C24' => '20140101',
    'C26' => '1,Inmueble', 'E26' => '', 'H26' => '580000.98',
    'D29' => 'NO',
    'B33' => 'MAR', 'C33' => 'CAS', 'D33' => 'OLI', 'E33' => '19820903', 'F33' => '', 'G33' => '', 'H33' => 'AFGANISTAN,AF',
];
$reader = new \Modules\FepAvaluo\V1\XlsmReader(new ArrayCellSource($cells));
$data = $reader->read();

ok($data['mes_reportado'] === '201401', 'mes_reportado');
ok($data['sujeto_obligado']['clave_sujeto_obligado'] === 'CAOM820309AM3', 'clave_sujeto_obligado');
ok($data['prioridad'] === '1', 'prioridad clave1');
ok($data['alerta']['tipo_alerta'] === '100', 'tipo_alerta clave1');
$act = $data['operaciones'][0]['actividad'];
ok($act['tipo_bien'] === '1', 'tipo_bien clave1 ('.$act['tipo_bien'].')');
ok($act['valor_avaluo'] === '580000.98', 'valor_avaluo');
ok($act['propietario']['tipo'] === 'fisica', 'propietario tipo');
ok($act['propietario']['pais_nacionalidad'] === 'AF', 'pais clave2 ('.$act['propietario']['pais_nacionalidad'].')');
ok($act['propietario']['propietario_solicita'] === 'NO', 'propietario_solicita');

$exporter = new \Modules\FepAvaluo\V1\Exporter();
[$xml, $errs] = $exporter->exportChecked($data);
if ($errs) { echo "  XSD errors:\n"; foreach ($errs as $e) echo "    - $e\n"; }
ok($errs === [], 'xlsm-sourced avaluo XML is XSD-valid');
ok(str_contains($xml, '<valor_avaluo>580000.98</valor_avaluo>'), 'valor_avaluo in XML');
ok(str_contains($xml, '<pais_nacionalidad>AF</pais_nacionalidad>'), 'pais in XML');

echo "== propietario_solicita=SI omits the persona ==\n";
$cells2 = $cells; $cells2['D29'] = 'SI';
$data2 = (new \Modules\FepAvaluo\V1\XlsmReader(new ArrayCellSource($cells2)))->read();
$prop2 = $data2['operaciones'][0]['actividad']['propietario'];
ok(($prop2['tipo'] ?? null) === null, 'no persona tipo when solicita=SI');
[$xml2, $errs2] = $exporter->exportChecked($data2);
ok($errs2 === [], 'solicita=SI XML is XSD-valid');

echo "== Cesion xlsm map (full personas, clave3 actividad) ==\n";
$cc = [
    'C4' => 'CAOM820309AM3', 'C5' => '201401', 'C6' => 'AAA111',
    'E6' => '1,NORMAL', 'C7' => '1201,Alerta', 'E7' => '',
    'B19' => 'JOSE', 'C19' => 'RODRIGUEZ', 'D19' => 'SOLANO', 'E19' => '19780522', 'F19' => 'ROSM7805225M4', 'G19' => '',
    'C23' => '4456', 'C24' => '20140101',
    'B27' => '44564', 'C27' => 'ROS7805225M4', 'D27' => 'FIDEICOMISO EJEMPLO', 'F27' => '1,Cesion del fideicomitente',
    // cedente física row 34
    'B34' => 'CARLOS', 'C34' => 'CRUZ', 'D34' => 'CASTRO', 'E34' => '19901212', 'H34' => 'AFGANISTAN,AF', 'I34' => 'AGRICULTURA||0300004',
    // cesionario física row 46
    'B46' => 'CARMEN', 'C46' => 'CANO', 'D46' => 'CORONA', 'E46' => '19981212', 'H46' => 'ANDORRA,AD', 'I46' => 'GANADERIA||1300003',
    'C53' => '115621261.00',
];
$cData = (new \Modules\FepCesion\V1\XlsmReader(new ArrayCellSource($cc)))->read();
$cAct = $cData['operaciones'][0]['actividad'];
ok($cAct['tipo_cesion'] === '1', 'cesion tipo_cesion clave1');
ok($cAct['identificador_fideicomiso'] === '44564', 'identificador');
ok(($cAct['cedente']['actividad_economica'] ?? null) === '0300004', 'cedente actividad clave3 ('.($cAct['cedente']['actividad_economica'] ?? 'null').')');
ok(($cAct['cesionario']['pais_nacionalidad'] ?? null) === 'AD', 'cesionario país clave2');
ok($cAct['monto_cesion'] === '115621261.00', 'monto_cesion');
$cExp = new \Modules\FepCesion\V1\Exporter();
[$cXml, $cErr] = $cExp->exportChecked($cData);
if ($cErr) { echo "  XSD errors:\n"; foreach ($cErr as $e) echo "    - $e\n"; }
ok($cErr === [], 'xlsm-sourced cesion XML is XSD-valid');
ok(str_contains($cXml, '<actividad_economica>0300004</actividad_economica>'), 'actividad in XML');

// Generic: build cells, run reader → exporter, assert XSD-valid + needles present.
$env = fn(array $extra) => array_merge([
    'C4' => 'OGA751212G56', 'C5' => '201407', 'C6' => '201406',
    'E6' => '1,NORMAL', 'C7' => '521,Alerta', 'E7' => '',
    'B19' => 'JOSE', 'C19' => 'RODRIGUEZ', 'D19' => 'SOLANO', 'E19' => '19780522', 'F19' => '', 'G19' => '',
    'C23' => '234234', 'C24' => '20140101',
], $extra);
$run = function (string $readerCls, string $expCls, array $cells, array $needles, string $label) {
    $data = (new $readerCls(new ArrayCellSource($cells)))->read();
    [$xml, $errs] = (new $expCls())->exportChecked($data);
    if ($errs) { echo "  $label XSD errors:\n"; foreach ($errs as $e) echo "    - $e\n"; }
    ok($errs === [], "$label: xlsm → XSD-valid XML");
    foreach ($needles as $n) ok(str_contains($xml, $n), "$label: contains $n");
};

echo "== Poder xlsm (poderdante full moral + apoderados simple) ==\n";
$run(\Modules\FepPoder\V1\XlsmReader::class, \Modules\FepPoder\V1\Exporter::class, $env([
    'B44' => 'AZTECA', 'C44' => '19800202', 'E44' => 'MEXICO,MX', 'F44' => 'COMERCIO||5313110',
    'B61' => 'ANDREA', 'C61' => 'ROBLES', 'D61' => 'GONZALES', 'E61' => '19921120', 'H61' => 'MEXICO,MX', 'I61' => '2,de dominio',
    'B62' => 'YARELY', 'C62' => 'BARGAS', 'D62' => 'PEREZ', 'E62' => '19890412', 'H62' => 'MEXICO,MX', 'I62' => '1,de administracion',
]), ['<giro_mercantil>5313110</giro_mercantil>', '<tipo_poder>2</tipo_poder>', '<tipo_poder>1</tipo_poder>', '<datos_apoderado>'], 'poder');

echo "== ConstitucionPM xlsm ==\n";
$run(\Modules\FepConstitucionpm\V1\XlsmReader::class, \Modules\FepConstitucionpm\V1\Exporter::class, $env([
    'C29' => '2,Sociedad Civil', 'B32' => 'LA AZTECA', 'C32' => 'COMERCIO||4640000', 'D32' => '231GH',
    'E32' => '12334', 'F32' => '7,CHIAPAS', 'G32' => 'NO', 'H32' => '3,Contrato privado', 'I32' => '12549655',
    'B39' => 'MARIBEL', 'C39' => 'FLORES', 'D39' => 'RIO', 'E39' => '19770317', 'H39' => 'MEXICO,MX', 'J39' => '1,Administrador', 'I39' => '30',
    'B40' => 'SAUL', 'C40' => 'CASTRO', 'D40' => 'DOMINGUEZ', 'E40' => '19780611', 'H40' => 'MEXICO,MX', 'J40' => '5,No aplica', 'I40' => '20',
    'C79' => '12810', 'F79' => '1541800.44',
]), ['<tipo_persona_moral>2</tipo_persona_moral>', '<entidad_federativa>7</entidad_federativa>', '<cargo_accionista>1</cargo_accionista>', '<capital_variable>1541800.44</capital_variable>', '<numero_acciones>30.00</numero_acciones>'], 'constpm');

echo "== ModifPatrimonial xlsm ==\n";
$run(\Modules\FepModifpatrimonial\V1\XlsmReader::class, \Modules\FepModifpatrimonial\V1\Exporter::class, $env([
    'B30' => 'CHOCOLATE AZTECA', 'C30' => '20000811', 'D30' => 'CHO000811KJL', 'E30' => 'MEXICO,MX', 'F30' => 'COMERCIO||5231100', 'G30' => '12334.55', 'H30' => '3,Contrato privado', 'I30' => '4500',
    'B35' => '2,Disminucion', 'C35' => '12300.55', 'D35' => '11560.25', 'E35' => '2,Disminucion', 'F35' => '10056.00', 'G35' => '100567.34',
    'B41' => 'ALMA', 'C41' => 'SALAS', 'D41' => 'VILLA', 'E41' => '19771015', 'H41' => 'MEXICO,MX', 'I41' => '50',
]), ['<denominacion_razon>CHOCOLATE AZTECA</denominacion_razon>', '<final_capital_fijo>11560.25</final_capital_fijo>', '<numero_acciones>50.00</numero_acciones>'], 'modif');

echo "== Fusion xlsm (fusionadas + fusionante + accionistas) ==\n";
$run(\Modules\FepFusion\V1\XlsmReader::class, \Modules\FepFusion\V1\Exporter::class, $env([
    'C27' => '1,Absorcion',
    'B33' => 'SAI SOLUCIONES', 'C33' => '20001102', 'D33' => 'SAI001102HJK', 'E33' => 'MEXICO,MX', 'F33' => 'COMERCIO||5222200', 'G33' => '2200', 'H33' => '1250033', 'I33' => '718183FG',
    'B34' => 'ITSOL', 'C34' => '20010403', 'D34' => 'ITS010403UIO', 'E34' => 'MEXICO,MX', 'F34' => 'COMERCIO||5231100', 'G34' => '250133', 'H34' => '256601', 'I34' => '245500300',
    'C46' => 'SI',
    'B49' => 'AVANTE', 'C49' => '19780713', 'D49' => 'AVA780713IIO', 'E49' => 'MEXICO,MX', 'F49' => 'COMERCIO||5411100', 'G49' => '12000036', 'H49' => '566000333', 'I49' => 'YUT678', 'J49' => '1236685',
    'B55' => 'ANTONIO', 'C55' => 'SALAZAR', 'D55' => 'PRADO', 'E55' => '19800309', 'F55' => 'SAPA800309JHJ', 'G55' => 'SAPA800309HHJKKK23', 'H55' => 'MEXICO,MX', 'I55' => '20336900',
    'B56' => 'MATIAS', 'C56' => 'ALCALA', 'D56' => 'BALTAR', 'E56' => '19820714', 'H56' => 'MEXICO,MX', 'I56' => '21122',
]), ['<tipo_fusion>1</tipo_fusion>', '<fusionante_determinadas>SI</fusionante_determinadas>', '<numero_total_acciones>1236685.00</numero_total_acciones>', '<datos_fusionada>', '<numero_acciones>20336900.00</numero_acciones>'], 'fusion');

echo "== Mutuo xlsm (acreedor/deudor full + garantía inmueble + garante + liquidación) ==\n";
$run(\Modules\FepMutuo\V1\XlsmReader::class, \Modules\FepMutuo\V1\Exporter::class, $env([
    'C26' => '2,Con garantia',
    'B33' => 'CARLOS', 'C33' => 'CRUZ', 'D33' => 'CAMPOS', 'E33' => '19801212', 'H33' => 'ANTARTIDA,AQ', 'I33' => 'SERV||1300003',
    'B63' => 'CESAR', 'C63' => 'CANO', 'D63' => 'CORONA', 'E63' => '19801212', 'H63' => 'ANTARTIDA,AQ', 'I63' => 'SERV||1300003',
    'B90' => '2,Inmueble', 'C90' => '1,Casa', 'D90' => '1000000.00', 'E90' => '06000', 'F90' => '1111111',
    'B106' => 'CARLOS', 'C106' => 'CRUZ', 'D106' => 'CAMPOS', 'E106' => '19801212',
    'B132' => '1,Peso mexicano', 'C132' => '1000000.00',
]), ['<tipo_otorgamiento>2</tipo_otorgamiento>', '<actividad_economica>1300003</actividad_economica>', '<datos_inmueble>', '<folio_real>1111111</folio_real>', '<datos_garantia>', '<monto_operacion>1000000.00</monto_operacion>'], 'mutuo');

echo "== Escision xlsm (escindente + escindida + name-matched accionista) ==\n";
$run(\Modules\FepEscision\V1\XlsmReader::class, \Modules\FepEscision\V1\Exporter::class, $env([
    'B30' => 'AVANTE', 'C30' => '19780713', 'D30' => 'AVA780713IIO', 'E30' => 'MEXICO,MX', 'F30' => 'COM||5411100', 'G30' => '12000036', 'H30' => '566000333', 'I30' => 'YUT678',
    'D32' => 'SI',
    'G51' => 'SOLUCIONES AVANTE', 'H51' => 'CHO000811KJL', 'I51' => 'YUSIDO', 'J51' => '25',
    'D64' => 'SI',
    'B67' => 'UFIO', 'C67' => '19900201', 'D67' => 'UFI900201KKK', 'E67' => 'MEXICO,MX', 'F67' => 'COM||4810000', 'G67' => '1200365', 'H67' => '23555066', 'I67' => 'RUTU8473', 'J67' => '21',
    'H95' => 'SOLUCIONES AVANTE', 'I95' => 'CHO000811KJL', 'J95' => 'YUSIDO', 'K95' => '23', 'L95' => 'UFIO',
]), ['<escision>', '<escindente_subsiste>SI</escindente_subsiste>', '<datos_accionista_escindente>', '<dato_escindida>', '<numero_total_acciones>21.00</numero_total_acciones>', '<numero_acciones>23.00</numero_acciones>'], 'escision');

echo "== Compraventa xlsm (sociedad + name-matched vendedor/comprador) ==\n";
$run(\Modules\FepCompraventa\V1\XlsmReader::class, \Modules\FepCompraventa\V1\Exporter::class, $env([
    'C27' => '1,Compra',
    'B31' => 'PEDRO', 'C31' => '18000101', 'E31' => 'ALEMANIA,AL', 'F31' => '112223215158', 'G31' => '45',
    'B60' => 'AZTECA', 'C60' => '19800202', 'E60' => 'MEXICO,MX', 'G60' => 'PEDRO', 'F60' => '45',
    'B75' => 'ANDREA', 'C75' => 'ROBLES', 'D75' => 'GONZALES', 'E75' => '19921120', 'H75' => 'MEXICO,MX', 'J75' => 'PEDRO', 'I75' => '40',
    'B102' => '20140701', 'D102' => '1,Efectivo', 'E102' => '1,Peso', 'F102' => '1800000.12',
]), ['<tipo_operacion>1</tipo_operacion>', '<persona_moral_acciones>', '<valor_nominal>112223215158.00</valor_nominal>', '<numero_acciones_vendidas>45.00</numero_acciones_vendidas>', '<numero_acciones_compradas>40.00</numero_acciones_compradas>', '<monto_operacion>1800000.12</monto_operacion>'], 'compraventa');

echo "== Fideicomiso xlsm (fideicomitente + name-matched patrimonios + fideicomisario) ==\n";
$run(\Modules\FepConstitucionfid\V1\XlsmReader::class, \Modules\FepConstitucionfid\V1\Exporter::class, $env([
    'B27' => '4,Movimiento', 'C27' => '1,Traslativo', 'D27' => 'CONSTITUCION DE FIDEICOMISO', 'F27' => '1A', 'G27' => 'FID', 'H27' => '5900000.40',
    // fideicomitente física row 34 → key "R01 - C C C"
    'B34' => 'C', 'C34' => 'C', 'D34' => 'C', 'E34' => '19901212', 'H34' => 'AFGANISTAN,AF', 'I34' => 'AGR||0300004', 'J34' => '1,Incorporacion',
    // patrimonios matched by L col
    'B73' => '1,Peso', 'C73' => '10000000.00', 'L73' => 'R01 - C C C',
    'J74' => 'DESC OTRO BIEN', 'K74' => '2000000.00', 'L74' => 'R01 - C C C',
    // fideicomisario física row 94 (col-shifted: nombre at C), determinados at B
    'B94' => 'NO', 'C94' => 'C', 'D94' => 'C', 'E94' => 'C', 'F94' => '19981212', 'I94' => 'ANDORRA,AD', 'J94' => 'GAN||1300003', 'K94' => '1,Incorporacion',
    'C133' => 'SI', 'E133' => 'NO',
]), ['<constitucion_modificacion_fideicomiso>', '<tipo_movimiento>4</tipo_movimiento>', '<monto_patrimonio>5900000.40</monto_patrimonio>', '<patrimonio_monetario>', '<monto_operacion>10000000.00</monto_operacion>', '<valor_bien>2000000.00</valor_bien>', '<datos_fideicomisarios_determinados>NO</datos_fideicomisarios_determinados>', '<comite_tecnico>SI</comite_tecnico>'], 'fideicomiso');

echo "\n== RESULT: {$pass} passed, {$fail} failed ==\n";
exit($fail ? 1 : 0);
