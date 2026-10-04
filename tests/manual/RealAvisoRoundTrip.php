<?php
namespace Carbon { class Carbon { private $d; function __construct($d){$this->d=$d;} static function parse($x){ return new self(new \DateTimeImmutable((string)$x)); } function format($f){ return $this->d->format($f);} } }
namespace {
function now(){ return Carbon\Carbon::parse('2026-10-03'); }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
// Real SAT aviso (contains personal data → not committed). Usage: AVISO_FILE=/path/AVISOS_2.txt php tests/manual/RealAvisoRoundTrip.php
$FILE = getenv('AVISO_FILE') ?: exit("Set AVISO_FILE=/path/to/real_aviso.txt\n");
foreach (['Modules/ExporterContract','Modules/ImporterContract','Modules/ImportResult','Modules/ImportException','Services/Import/TxtDecoder','Services/Import/ImportService'] as $f) require "$R/app/$f.php";
require "$R/modules/avisos_uif/v1/Importer.php";
use App\Services\Import\{ImportService, TxtDecoder};
$fail = 0;
function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }
$exp = new Modules\AvisosUif\V1\Exporter(); $imp = new Modules\AvisosUif\V1\Importer();
$real = file_get_contents($FILE);
$res = (new ImportService())->import($real, $imp, $exp);
$d = $res->data;

echo "Import the real 2-operation aviso (26464)\n";
check('2 operations, acumuladas = Sí (930004:1)', count($d['operaciones']) === 2 && $d['operaciones_acumuladas'] === '1');
check('op 1: 1 adquirente + 2 vendedores; op 2: 1 + 2 (linked by GUID, not by position)',
  count($d['operaciones'][0]['adquirentes']) === 1 && count($d['operaciones'][0]['vendedores']) === 2 &&
  count($d['operaciones'][1]['adquirentes']) === 1 && count($d['operaciones'][1]['vendedores']) === 2);
check('op 1 vendor order RAFAEL, INGRID; op 2 INGRID, RAFAEL', $d['operaciones'][0]['vendedores'][0]['nombre'] === 'RAFAEL ALBERTO' && $d['operaciones'][1]['vendedores'][0]['nombre'] === 'INGRID LETICIA');
check('inmuebles attached to their operation (folio 20 / 9793)', $d['operaciones'][0]['inmueble']['folio_real'] === '20' && $d['operaciones'][1]['inmueble']['folio_real'] === '9793');
check('donación (tipo 3): automatic liquidación recognised → no pagos in the form', $d['operaciones'][0]['pagos'] === [] && $d['operaciones'][1]['pagos'] === []);
check('valor avalúo 2,565,800 (nuda propiedad) kept', $d['operaciones'][0]['inmueble']['valor_avaluo'] == 2565800);

echo "Re-export and compare with the real file\n";
$again = $exp->export($d + ['_notaria' => $res->meta['notaria']], 'txt');
$a = TxtDecoder::lines(TxtDecoder::decode($real)); $b = TxtDecoder::lines(TxtDecoder::decode($again));
$diff = []; for ($i = 0; $i < max(count($a), count($b)); $i++) if (($a[$i] ?? null) !== ($b[$i] ?? null)) $diff[] = $i + 1;
check('every line identical to the real SAT file (' . count($a) . ' lines)', !$diff);
foreach (array_slice($diff, 0, 5) as $ln) echo "    line $ln\n      real: " . ($a[$ln-1] ?? '∅') . "\n      ours: " . ($b[$ln-1] ?? '∅') . "\n";
check('ImportService round-trip flag', $res->meta['round_trip'] === true);
check('re-exported file is pure ASCII', !preg_match('/[\x80-\xFF]/', $again));
$crlf = substr_count($again, "\r\n");
echo "  NOTE line endings — real file: " . (str_contains($real, "\r\n") ? 'CRLF' : 'LF') . ", ours: " . ($crlf ? 'CRLF' : 'LF') . "\n";
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
}
