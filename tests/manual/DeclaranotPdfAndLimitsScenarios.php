<?php
// DeclaraNOT (enajenación): wide Pago/Copropiedad render as PDF cards (not an
// over-wide table), and numero_operacion_entidad is capped at 18 chars.
// Run: php tests/manual/DeclaranotPdfAndLimitsScenarios.php
namespace App\Services { class CatalogService {
    public function load($n, $d) { $p = $this->catalogPath($n, $d); return file_exists($p) ? json_decode(file_get_contents($p), true) : null; }
    public function catalogPath(string $catalogName, string $moduleDir): string {
        $own = "{$moduleDir}/catalogs/{$catalogName}.json"; if (file_exists($own)) return $own;
        $m = @json_decode((string) @file_get_contents("{$moduleDir}/module.json"), true); $parent = is_array($m) ? ($m['extends'] ?? null) : null;
        if (is_string($parent) && $parent !== '') { $inh = rtrim($moduleDir, '/') . '/' . trim($parent, '/') . "/catalogs/{$catalogName}.json"; if (file_exists($inh)) return $inh; }
        return $own; }
    public function isValidValue($n,$d,$v){ return true; } } }
namespace Carbon { class Carbon { private $d; function __construct($d){$this->d=$d;} static function parse($x){ return new self(new \DateTimeImmutable((string)$x)); } function format($f){ return $this->d->format($f);} } }
namespace {
function app($c){ return new $c; }
function data_get($a,$path){ foreach(explode('.',$path) as $k){ if(!is_array($a)||!array_key_exists($k,$a)) return null; $a=$a[$k]; } return $a; }
function resource_path($p){ return (getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2)) . "/resources/$p"; }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
require_once "$R/app/Support/ExportDate.php";
foreach (['Modules/ModuleControllerContract','Modules/ModuleInput',
  'Services/Schema/IdDates','Services/Schema/ValidationIssue','Services/Schema/EngineResult','Services/Schema/SchemaEngine','Services/Pdf/ReviewPdfPresenter'] as $f) require "$R/app/$f.php";
$D = "$R/modules/declaranot/v1";
require "$D/Controller.php";
use Modules\Declaranot\V1\Controller;
$fail = 0; function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }

// Recursively find the first block whose title matches (case-insensitive).
function findBlock(array $sections, string $title): ?array {
    $walk = function ($blocks) use (&$walk, $title) {
        foreach ($blocks as $b) {
            if (isset($b['title']) && $b['title'] !== null && mb_strtolower($b['title']) === mb_strtolower($title)) return $b;
            foreach (['blocks'] as $k) if (!empty($b[$k])) { $r = $walk($b[$k]); if ($r) return $r; }
            if (($b['type'] ?? '') === 'cards') foreach ($b['items'] as $it) { $r = $walk($it['blocks'] ?? []); if ($r) return $r; }
        }
        return null;
    };
    foreach ($sections as $s) { $r = $walk($s['blocks']); if ($r) return $r; }
    return null;
}
// All block titles present anywhere in a section's block tree (for the Total card).
function cardTitles(array $block): array {
    if (($block['type'] ?? '') !== 'cards') return [];
    return array_map(fn($it) => $it['title'], $block['items']);
}
// The top-level blocks of a section (matched by the section's own title).
function sectionBlocks(array $sections, string $title): array {
    foreach ($sections as $s) if (mb_strtolower($s['title']) === mb_strtolower($title)) return $s['blocks'];
    return [];
}

$ctrl = new Controller($D);
$fs = $ctrl->formSchema();
$engine = new App\Services\Schema\SchemaEngine(new App\Services\CatalogService);

$data = [
    'numero_escritura' => '12345', 'fecha_firma_escritura' => '2026-11-05', 'tipo_inmueble' => '1', 'avaluo_inmueble' => 3500000,
    'pagos_inmueble' => [['monto' => 3500000, 'tipo_pago_inmueble' => '3', 'institucion_financiera' => '001', 'numero_cuenta' => '123']],
    'pago' => [[
        'ingresos_enajenacion' => 3500000, 'ingresos_exentos' => 0, 'deducciones_autorizadas' => 3310695, 'ganancia_perdida' => 189305,
        'years_adquisicion_venta' => 1, 'ganancia_acumulable' => 189305, 'ganancia_no_acumulable' => 0,
        'isr_federacion' => 2514, 'numero_operacion_federacion' => '653785260', 'fecha_pago_federacion' => '2026-11-20',
        'isr_entidad' => 9466, 'numero_operacion_entidad' => '100200300', 'fecha_pago_entidad' => '2026-11-20',
    ]],
    'copropiedad' => ['existe_copropiedad' => '1', 'integrantes' => [
        ['rfc' => 'BALM8907274G8', 'porcentaje' => 50, 'ingresos_enajenacion' => 1750000, 'deducciones_autorizadas' => 1655348, 'ganancia_perdida' => 94652, 'ganancia_acumulable' => 94652, 'ganancia_no_acumulable' => 0, 'isr_federacion' => 1257, 'isr_entidad' => 4733],
        ['rfc' => 'LOFM9012073Y1', 'porcentaje' => 50, 'ingresos_enajenacion' => 1750000, 'deducciones_autorizadas' => 1655348, 'ganancia_perdida' => 94652, 'ganancia_acumulable' => 94652, 'ganancia_no_acumulable' => 0, 'isr_federacion' => 1257, 'isr_entidad' => 4733],
    ]],
];
$eng = $engine->process($fs, $data, $D);
$pdf = (new App\Services\Pdf\ReviewPdfPresenter($engine))->present($fs, $eng->data);

// --- PDF layout ---
$pagoBlocks = sectionBlocks($pdf, 'Pago');
check('Pago renders as cards, not a wide table', ($pagoBlocks[0]['type'] ?? null) === 'cards');

$intBlock = findBlock($pdf, 'Integrantes');
check('Copropiedad integrantes render as cards', ($intBlock['type'] ?? null) === 'cards');
check('integrantes cards include a Total card', in_array('Total', cardTitles($intBlock ?? []), true));
check('two integrantes → 2 rows + Total = 3 cards', count($intBlock['items'] ?? []) === 3);

// Narrow all-leaf array stays a table (regression guard)
$inmBlocks = sectionBlocks($pdf, 'Pagos del Inmueble');
check('pagos_inmueble (narrow) still renders as a table', ($inmBlocks[0]['type'] ?? null) === 'table');

// --- maxlength on numero_operacion_entidad ---
$paths = fn($res) => array_map(fn($i) => $i->path . '|' . $i->rule, $res->issues);
$over = $data; $over['pago'][0]['numero_operacion_entidad'] = str_repeat('9', 19);
$rOver = $engine->process($fs, $over, $D);
check('19-digit numero_operacion_entidad → maxlength issue', in_array('pago.0.numero_operacion_entidad|maxlength', $paths($rOver), true));

$ok = $data; $ok['pago'][0]['numero_operacion_entidad'] = str_repeat('9', 18);
$rOk = $engine->process($fs, $ok, $D);
check('18-digit numero_operacion_entidad → no maxlength issue', !in_array('pago.0.numero_operacion_entidad|maxlength', $paths($rOk), true));

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
}
