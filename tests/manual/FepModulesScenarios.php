<?php
/**
 * FEP type-modules — export + round-trip, validated against fep.xsd.
 * Plain PHP. Run: php tests/manual/FepModulesScenarios.php
 *
 * For each built module: (1) load its *_output.json (AI-output == review-data shape),
 * export → assert XSD-valid; (2) parse its SAT example XML → re-export → assert XSD-valid.
 */

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/Services/Fep/FepValueFormatter.php';
require_once $ROOT . '/app/Services/Fep/FepXmlWriter.php';
require_once $ROOT . '/app/Services/Fep/FepPersonas.php';
require_once $ROOT . '/app/Modules/ExporterContract.php';
require_once $ROOT . '/app/Modules/ImporterContract.php';
require_once $ROOT . '/app/Modules/ImportResult.php';
require_once $ROOT . '/app/Modules/ImportException.php';

use App\Services\Fep\FepXmlWriter;

$XSD = $ROOT . '/modules/fep/v1/fep.xsd';
$FIX = __DIR__ . '/fep_fixtures';

$modules = [
    'fep_avaluo' => ['output' => 'avaluo_output.json', 'example' => 'ejemplo_fep10_avaluo.xml', 'exp' => 'Modules\\FepAvaluo\\V1\\Exporter', 'imp' => 'Modules\\FepAvaluo\\V1\\Importer'],
    'fep_poder'  => ['output' => 'poder_output.json',  'example' => 'ejemplo_fep1_otorgamientopoder.xml', 'exp' => 'Modules\\FepPoder\\V1\\Exporter', 'imp' => 'Modules\\FepPoder\\V1\\Importer'],
    'fep_cesion' => ['output' => 'cesion_output.json', 'example' => 'ejemplo_fep8_cesion.xml', 'exp' => 'Modules\\FepCesion\\V1\\Exporter', 'imp' => 'Modules\\FepCesion\\V1\\Importer'],
    'fep_constitucionpm' => ['output' => 'constitucionpm_output.json', 'example' => 'ejemplo_fep2_constitucionpm.xml', 'exp' => 'Modules\\FepConstitucionpm\\V1\\Exporter', 'imp' => 'Modules\\FepConstitucionpm\\V1\\Importer'],
    'fep_modifpatrimonial' => ['output' => 'modifpatrimonial_output.json', 'example' => 'ejemplo_fep3_modificacionpatrimonial.xml', 'exp' => 'Modules\\FepModifpatrimonial\\V1\\Exporter', 'imp' => 'Modules\\FepModifpatrimonial\\V1\\Importer'],
    'fep_constitucionfid' => ['output' => 'constitucionfid_output.json', 'example' => 'ejemplo_fep7_constitucionfid.xml', 'exp' => 'Modules\\FepConstitucionfid\\V1\\Exporter', 'imp' => 'Modules\\FepConstitucionfid\\V1\\Importer'],
    'fep_fusion' => ['output' => 'fusion_output.json', 'example' => 'ejemplo_fep4_fusion.xml', 'exp' => 'Modules\\FepFusion\\V1\\Exporter', 'imp' => 'Modules\\FepFusion\\V1\\Importer'],
    'fep_escision' => ['output' => 'escision_output.json', 'example' => 'ejemplo_fep5_escision.xml', 'exp' => 'Modules\\FepEscision\\V1\\Exporter', 'imp' => 'Modules\\FepEscision\\V1\\Importer'],
    'fep_compraventa' => ['output' => 'compraventa_output.json', 'example' => 'ejemplo_fep6_compraventaacciones.xml', 'exp' => 'Modules\\FepCompraventa\\V1\\Exporter', 'imp' => 'Modules\\FepCompraventa\\V1\\Importer'],
    'fep_mutuo' => ['output' => 'mutuo_output.json', 'example' => 'ejemplo_fep9_mutuo.xml', 'exp' => 'Modules\\FepMutuo\\V1\\Exporter', 'imp' => 'Modules\\FepMutuo\\V1\\Importer'],
];

$pass = 0; $fail = 0;
function ok($c, $l) { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "  ✗ FAIL: $l\n"; } }

foreach ($modules as $slug => $m) {
    require_once $ROOT . "/modules/$slug/v1/Exporter.php";
    require_once $ROOT . "/modules/$slug/v1/Importer.php";
    echo "== $slug ==\n";

    $exporter = new $m['exp']();
    $importer = new $m['imp']();

    // 1) sample output.json → export → XSD valid. The notary-side fields (mes/so)
    //    aren't in the AI output, so inject minimal valid values for the test.
    $data = json_decode(file_get_contents($ROOT . "/modules/$slug/v1/" . $m['output']), true);
    $data['mes_reportado'] = '201401';
    $data['sujeto_obligado'] = ['clave_sujeto_obligado' => 'CAOM820309AM3'];
    if (empty($data['prioridad'])) $data['prioridad'] = '1';
    if (empty($data['alerta']['tipo_alerta'])) $data['alerta']['tipo_alerta'] = '100';
    [$xml, $errs] = $exporter->exportChecked($data);
    if ($errs) { echo "  export XSD errors:\n"; foreach ($errs as $e) echo "    - $e\n"; }
    ok($errs === [], "$slug: output.json export is XSD-valid");

    // 2) example XML → import → re-export → XSD valid
    $result = $importer->parse(file_get_contents("$FIX/" . $m['example']));
    $result->data['mes_reportado'] = $result->data['mes_reportado'] ?: '201401';
    if (empty($result->data['sujeto_obligado']['clave_sujeto_obligado'])) {
        $result->data['sujeto_obligado']['clave_sujeto_obligado'] = 'CAOM820309AM3';
    }
    [$rt, $rtErr] = $exporter->exportChecked($result->data);
    if ($rtErr) { echo "  round-trip XSD errors:\n"; foreach ($rtErr as $e) echo "    - $e\n"; }
    ok($rtErr === [], "$slug: example import→re-export is XSD-valid");
}

echo "\n== RESULT: {$pass} passed, {$fail} failed ==\n";
exit($fail ? 1 : 0);
