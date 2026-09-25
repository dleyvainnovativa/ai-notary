<?php
// Minimal stubs so the REAL SchemaEngine + module formSchemas run without Laravel.
namespace App\Services { class CatalogService {
    public function load($name, $dir) { $p = "$dir/catalogs/$name.json"; return file_exists($p) ? json_decode(file_get_contents($p), true) : null; }
    public function isValidValue($n, $d, $v) { return true; }
}}
namespace {
function app($c) { return new $c; }
function data_get($a, $path) { foreach (explode('.', $path) as $k) { if (!is_array($a) || !array_key_exists($k, $a)) return null; $a = $a[$k]; } return $a; }
$ROOT = dirname(__DIR__, 2);
foreach (['Modules/ModuleControllerContract', 'Modules/ModuleInput', 'Services/Schema/IdDates', 'Services/Schema/ValidationIssue', 'Services/Schema/EngineResult', 'Services/Schema/SchemaEngine'] as $f) require "$ROOT/app/$f.php";
require "$ROOT/modules/avisos_uif/v1/Controller.php";
require "$ROOT/modules/declaranot/v1/Controller.php";
use App\Services\Schema\IdDates;

$fail = 0;
function check($l, $c) { global $fail; echo ($c ? '  PASS ' : '  FAIL ') . "$l\n"; if (!$c) $fail++; }

echo "IdDates — real IDs from deed 26763\n";
check('CURP GAGA580413MCLRRN04 → 1958-04-13', IdDates::fromCurp('GAGA580413MCLRRN04') === '1958-04-13');
check('CURP SARE570420HNENMD02 → 1957-04-20', IdDates::fromCurp('SARE570420HNENMD02') === '1957-04-20');
check('CURP OEET621022MDFRSR01 → 1962-10-22', IdDates::fromCurp('OEET621022MDFRSR01') === '1962-10-22');
check('CURP born 2000+ (letter at pos 17) → 2005-03-12', IdDates::fromCurp('LOPA050312HVZRRLA1') === '2005-03-12');
check('CURP bad date → null', IdDates::fromCurp('GAGA581332MCLRRN04') === null);
check('RFC física OEEA651127CFA (Arturo, no CURP in deed) → 1965-11-27', IdDates::fromRfcFisica('OEEA651127CFA', 2026) === '1965-11-27');
check('RFC física 05 → 2005 (adult in 2026)', IdDates::fromRfcFisica('LOPA050312AB1', 2026) === '2005-03-12');
check('RFC física 10 → 1910 (century guess, CURP covers minors)', IdDates::fromRfcFisica('LOPA100312AB1', 2026) === '1910-03-12');
check('RFC física generic EXTF900101000 → null', IdDates::fromRfcFisica('EXTF900101000') === null);
check('RFC moral ICJ950101AB1 → 1995-01-01', IdDates::fromRfcMoral('ICJ950101AB1', 2026) === '1995-01-01');
check('RFC moral 15 → 2015', IdDates::fromRfcMoral('ABC150101AB1', 2026) === '2015-01-01');
check('RFC moral generic → null', IdDates::fromRfcMoral('EXT990101000') === null);
check('RFC física is not read as moral', IdDates::fromRfcMoral('OEEA651127CFA') === null);

echo "SchemaEngine + UIF formSchema\n";
$uif = new Modules\AvisosUif\V1\Controller("$ROOT/modules/avisos_uif/v1");
$engine = new App\Services\Schema\SchemaEngine(new App\Services\CatalogService);
$data = [
  'referencia_aviso' => '26763',
  'solicitante' => ['rfc' => 'SARE570420CQ7', 'curp' => 'SARE570420HNENMD02', 'fecha_nacimiento' => null, 'nombre' => 'EDUARDO'],
  'operaciones' => [[
    'fecha_operacion' => '2026-07-30',
    'adquirentes' => [
      ['tipo_persona' => '1', 'rfc' => 'OEEA651127CFA', 'curp' => null, 'fecha_nacimiento' => null],            // RFC only, empty → fill
      ['tipo_persona' => '2', 'rfc' => 'ICJ950101AB1', 'fecha_constitucion' => null],                          // moral
    ],
    'vendedores' => [
      ['tipo_persona' => '1', 'rfc' => 'SARE570420CQ7', 'curp' => 'SARE570420HNENMD02', 'fecha_nacimiento' => '1957-04-02'], // AI misread → CURP wins
      ['tipo_persona' => '1', 'rfc' => 'GAGA580413B21', 'curp' => null, 'fecha_nacimiento' => '1958-04-14'],  // RFC only, has value → keep
      ['tipo_persona' => '1', 'rfc' => 'EXTF900101000', 'curp' => null, 'fecha_nacimiento' => '1970-01-01'],  // generic → keep deed
    ],
  ]],
];
$res = $engine->process($uif->formSchema(), $data, "$ROOT/modules/avisos_uif/v1")->data;
$op = $res['operaciones'][0];
check('solicitante fecha from CURP (object)', $res['solicitante']['fecha_nacimiento'] === '1957-04-20');
check('adquirente RFC-only empty → 1965-11-27 (array row)', $op['adquirentes'][0]['fecha_nacimiento'] === '1965-11-27');
check('adquirente moral fecha_constitucion → 1995-01-01', $op['adquirentes'][1]['fecha_constitucion'] === '1995-01-01');
check('vendedor CURP overwrites AI misread (a)', $op['vendedores'][0]['fecha_nacimiento'] === '1957-04-20');
check('vendedor RFC-only keeps existing value (b)', $op['vendedores'][1]['fecha_nacimiento'] === '1958-04-14');
check('vendedor generic RFC keeps deed value', $op['vendedores'][2]['fecha_nacimiento'] === '1970-01-01');

echo "SchemaEngine + declaranot formSchema (existing derive_from_array_length still works)\n";
$dec = new Modules\Declaranot\V1\Controller("$ROOT/modules/declaranot/v1");
$res = $engine->process($dec->formSchema(), [
  'enajenantes' => [['tipo_enajenante' => '2', 'rfc' => 'EXTF900101000', 'curp' => 'SARE570420HNENMD02', 'fecha_nacimiento' => null]],
  'copropiedad' => ['integrantes' => [['rfc' => 'X']]],
], "$ROOT/modules/declaranot/v1")->data;
check('extranjera with CURP → date from CURP', $res['enajenantes'][0]['fecha_nacimiento'] === '1957-04-20');
check('existe_copropiedad still derived = 1', ($res['copropiedad']['existe_copropiedad'] ?? null) === '1');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
}
