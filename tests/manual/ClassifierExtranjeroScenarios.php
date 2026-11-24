<?php
/**
 * DeclaraNOT persona classifier — foreign auto-detection.
 * Plain PHP. Run: php tests/manual/ClassifierExtranjeroScenarios.php
 *
 * A generic foreign RFC (EXTF900101000 física, EXT990101000 moral) must classify
 * as extranjera even when tipo_enajenante is "1" (nacional) — EXTF900101000 also
 * matches the nacional física pattern, so it previously read as nacional. National
 * detection for real Mexican RFCs is unchanged.
 */
namespace App\Services { class CatalogService { function get($a=null,$b=null){return [];} function load($a=null,$b=null){return [];} } }
namespace {
require '/home/claude/archive_extract/app/Services/Schema/SchemaEngine.php';
use App\Services\Schema\SchemaEngine; use App\Services\CatalogService;
$eng = new SchemaEngine(new CatalogService());
$m = (new ReflectionClass($eng))->getMethod('personaCase'); $m->setAccessible(true);
$case = fn($tipo, $rfc) => $m->invoke($eng, $tipo, $rfc);

$fail = 0;
function ck(&$fail,$l,$got,$want){ $ok=$got===$want; echo ($ok?'  ✓ ':"  ✗ ")."$l (got $got)\n"; if(!$ok)$fail++; }

echo "== generic foreign RFC → extranjera regardless of tipo (the fix) ==\n";
ck($fail,'tipo 1 + EXTF900101000 → extranjera_fisica', $case('1','EXTF900101000'), 'extranjera_fisica');
ck($fail,'tipo 1 + EXT990101000 → extranjera_moral',   $case('1','EXT990101000'), 'extranjera_moral');
ck($fail,'tipo 2 + EXTF900101000 → extranjera_fisica', $case('2','EXTF900101000'), 'extranjera_fisica');

echo "== national detection unchanged ==\n";
ck($fail,'tipo 1 + real física RFC → nacional_fisica', $case('1','OEEA651127CFA'), 'nacional_fisica');
ck($fail,'tipo 1 + real moral RFC → nacional_moral',   $case('1','ICJ950101AB1'), 'nacional_moral');

echo "== explicit extranjero with non-generic RFC unchanged ==\n";
ck($fail,'tipo 2 + non-generic → extranjera_invalid',  $case('2','OEEA651127CFA'), 'extranjera_invalid');
ck($fail,'tipo 1 + garbage → unknown',                 $case('1','ABC'), 'unknown');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail?1:0);
}
