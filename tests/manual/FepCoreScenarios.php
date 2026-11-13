<?php
/**
 * Milestone 1 — FEP shared core.
 * Plain PHP (no Laravel). Run: php tests/manual/FepCoreScenarios.php
 *
 * Proves: value formatter rules, envelope + personas serialization, XSD-validity
 * against modules/fep/v1/fep.xsd, and the Avaluo module export + XML round-trip.
 */

$ROOT = dirname(__DIR__, 2);                 // .../notaria_archive
require_once $ROOT . '/app/Services/Fep/FepValueFormatter.php';
require_once $ROOT . '/app/Services/Fep/FepXmlWriter.php';
require_once $ROOT . '/app/Services/Fep/FepPersonas.php';
require_once $ROOT . '/app/Modules/ExporterContract.php';
require_once $ROOT . '/app/Modules/ImporterContract.php';
require_once $ROOT . '/app/Modules/ImportResult.php';
require_once $ROOT . '/app/Modules/ImportException.php';
require_once $ROOT . '/modules/fep_avaluo/v1/Exporter.php';
require_once $ROOT . '/modules/fep_avaluo/v1/Importer.php';

use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepXmlWriter;

$pass = 0; $fail = 0;
function ok($cond, $label) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "  ✗ FAIL: $label\n"; } }

echo "== FepValueFormatter ==\n";
ok(F::nombre('José  Ñandú-3') === 'JOSE ÑANDU', 'nombre deaccent/upper/strip: '.F::nombre('José  Ñandú-3'));
ok(F::apellido('') === 'XXXX', 'empty apellido → XXXX');
ok(F::apellido('', 'XXXX') === 'XXX', 'both-XXXX guard');
ok(F::fecha('2014-01-01') === '20140101', 'fecha ISO');
ok(F::fecha('09/03/1982') === '19820309', 'fecha DD/MM/YYYY');
ok(F::fecha('2014-13-40') === '', 'fecha invalid → empty');
ok(F::monto('580000.985') === '580000.99', 'monto rounds to 2dp');
ok(F::monto('') === '0.00', 'monto empty → 0.00');
ok(F::monto(-5) === '0.00', 'monto negative → 0.00');
ok(F::pais('mx') === 'MX', 'pais upper');
ok(F::pais('MEX') === '', 'pais non-2 → empty');
ok(F::digito7('13 00003') === '1300003', 'digito7 strip');
ok(F::mesReportado('2014-01') === '201401', 'mes YYYY-MM');
ok(F::denominacion("Álvarez & Hijos, S.A. (de C.V.)") === 'ALVAREZ & HIJOS, S.A. (DE C.V.)', 'denominacion: '.F::denominacion("Álvarez & Hijos, S.A. (de C.V.)"));
ok(F::siNo(true) === 'SI' && F::siNo('no') === 'NO', 'siNo');

echo "== Avaluo export + XSD validation ==\n";
$data = [
    'mes_reportado' => '201401',
    'sujeto_obligado' => ['clave_sujeto_obligado' => 'CAOM820309AM3'],
    'referencia_aviso' => 'AAA111',
    'prioridad' => '1',
    'alerta' => ['tipo_alerta' => '100'],
    'personas_aviso' => [
        ['nombre' => 'MAR', 'apellido_paterno' => 'CAS', 'apellido_materno' => 'OLI', 'fecha_nacimiento' => '19820309'],
    ],
    'operaciones' => [[
        'instrumento_publico' => '1234568790',
        'fecha_operacion' => '20140101',
        'actividad' => [
            'tipo_bien' => '1',
            'valor_avaluo' => '580000.98',
            'propietario' => [
                'tipo' => 'fisica',
                'propietario_solicita' => 'NO',
                'nombre' => 'MAR', 'apellido_paterno' => 'CAS', 'apellido_materno' => 'OLI',
                'fecha_nacimiento' => '19820903', 'pais_nacionalidad' => 'AF',
            ],
        ],
    ]],
];

$exporter = new \Modules\FepAvaluo\V1\Exporter();
[$xml, $errors] = $exporter->exportChecked($data);
if ($errors) { echo "  XSD errors:\n"; foreach ($errors as $e) echo "    - $e\n"; }
ok($errors === [], 'generated avaluo XML is XSD-valid');
ok(str_contains($xml, '<valor_avaluo>580000.98</valor_avaluo>'), 'valor_avaluo present');
ok(str_contains($xml, '<clave_actividad>FEP</clave_actividad>'), 'clave_actividad FEP');
ok(str_contains($xml, '<propietario_solicita>NO</propietario_solicita>'), 'propietario_solicita');
ok(str_contains($xml, 'xmlns="'.FepXmlWriter::NS.'"'), 'default namespace set');

echo "== Example files are XSD-valid (sanity) ==\n";
$xsd = $ROOT . '/modules/fep/v1/fep.xsd';
foreach (['ejemplo_fep10_avaluo.xml'] as $ex) {
    $errs = FepXmlWriter::validate(file_get_contents(__DIR__ . "/fep_fixtures/$ex"), $xsd);
    if ($errs) { echo "  $ex errors:\n"; foreach ($errs as $e) echo "    - $e\n"; }
    ok($errs === [], "$ex validates against fep.xsd");
}

echo "== Avaluo round-trip (import example → re-export → XSD-valid) ==\n";
$importer = new \Modules\FepAvaluo\V1\Importer();
$result = $importer->parse(file_get_contents(__DIR__ . '/fep_fixtures/ejemplo_fep10_avaluo.xml'));
$act = $result->data['operaciones'][0]['actividad'] ?? [];
ok(($act['tipo_bien'] ?? null) === '1', 'import read tipo_bien');
ok(($act['valor_avaluo'] ?? null) === '580000.98', 'import read valor_avaluo');
ok(($act['propietario']['pais_nacionalidad'] ?? null) === 'AF', 'import read propietario país');
[$rtXml, $rtErr] = $exporter->exportChecked($result->data);
if ($rtErr) { echo "  round-trip XSD errors:\n"; foreach ($rtErr as $e) echo "    - $e\n"; }
ok($rtErr === [], 're-exported imported avaluo is XSD-valid');

echo "\n== RESULT: {$pass} passed, {$fail} failed ==\n";
exit($fail ? 1 : 0);
