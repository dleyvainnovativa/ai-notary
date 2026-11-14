<?php
/**
 * FEP persona tipo discriminator — the form-select (1/2/3) path.
 * Plain PHP. Run: php tests/manual/FepPersonaTipoScenarios.php
 *
 * Guards the digit-vs-name reconciliation: a "Tipo de Persona" select stores a
 * digit (1/2/3), XLSM import stores a name (fisica/moral/fideicomiso), and both
 * must export the correct <persona_*> element. Also checks formFragment()
 * ships the 3 default options without a catalogo_persona.json.
 */

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/Services/Fep/FepValueFormatter.php';
require_once $ROOT . '/app/Services/Fep/FepXmlWriter.php';
require_once $ROOT . '/app/Services/Fep/FepPersonas.php';

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepPersonas;

$pass = 0; $fail = 0;
function ok($cond, $label) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "  ✗ FAIL: $label\n"; } }

/** Serialize the persona element produced for a given tipo value. */
function xmlFor($tipo): string {
    $w = new FepXmlWriter();
    $p = [
        'tipo' => $tipo,
        'nombre' => 'Juan', 'apellido_paterno' => 'Perez', 'apellido_materno' => 'Lopez',
        'denominacion_razon' => 'ACME SA DE CV',
        'identificador_fideicomiso' => 'F-123',
        'rfc' => 'XAXX010101000',
    ];
    $node = FepPersonas::tipoPersona($w, $p, 'simple');
    return $node->ownerDocument->saveXML($node);
}

echo "== tipo from the form select (digits) ==\n";
ok(str_contains(xmlFor('1'), 'persona_fisica'),  "digit 1 → persona_fisica");
ok(str_contains(xmlFor('2'), 'persona_moral'),   "digit 2 → persona_moral");
ok(str_contains(xmlFor('3'), 'fideicomiso'),     "digit 3 → fideicomiso");

echo "== tipo from XLSM import / round-trip (names) still work ==\n";
ok(str_contains(xmlFor('fisica'),      'persona_fisica'), "name fisica → persona_fisica");
ok(str_contains(xmlFor('moral'),       'persona_moral'),  "name moral → persona_moral");
ok(str_contains(xmlFor('fideicomiso'), 'fideicomiso'),    "name fideicomiso → fideicomiso");

echo "== regression: digit 2 must NOT fall back to física ==\n";
ok(!str_contains(xmlFor('2'), 'persona_fisica'), "digit 2 does not emit persona_fisica");

echo "== formFragment ships default tipo options (no catalogo_persona.json) ==\n";
$frag = FepPersonas::formFragment(['variant' => 'simple']);
$opts = $frag['itemSchema']['tipo']['options'] ?? [];
ok(count($opts) === 3, "3 default options, got " . count($opts));
$byVal = [];
foreach ($opts as $o) { $byVal[$o['value']] = $o['label']; }
ok(isset($byVal['1'], $byVal['2'], $byVal['3']), "option values are 1/2/3");

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit($fail ? 1 : 0);
