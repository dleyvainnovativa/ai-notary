<?php
namespace Carbon { class Carbon { private $d; function __construct($d){$this->d=$d;} static function parse($x){ return new self(new \DateTimeImmutable((string)$x)); } function format($f){ return $this->d->format($f);} } }
namespace {
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
$FILE = getenv('DECLARANOT_FILE') ?: exit("Set DECLARANOT_FILE=/path/real.txt\n");
foreach (['Modules/ExporterContract','Modules/ImporterContract','Modules/ImportResult','Modules/ImportException','Services/Import/TxtDecoder','Services/Import/ImportService'] as $f) require "$R/app/$f.php";
require "$R/modules/declaranot/v1/Exporter.php"; require "$R/modules/declaranot/v1/Importer.php";
use App\Services\Import\{ImportService, TxtDecoder};
$exp = new Modules\Declaranot\V1\Exporter(); $real = file_get_contents($FILE);
$res = (new ImportService())->import($real, new Modules\Declaranot\V1\Importer(), $exp);
$a = TxtDecoder::lines(TxtDecoder::decode($real)); $b = TxtDecoder::lines(TxtDecoder::decode($exp->export($res->data, 'txt')));
$diff = 0; for ($i = 0; $i < max(count($a), count($b)); $i++) if (($a[$i] ?? null) !== ($b[$i] ?? null)) { $diff++; echo "line ".($i+1)."\n  real: ".($a[$i]??'∅')."\n  ours: ".($b[$i]??'∅')."\n"; }
echo ($diff ? "FAIL $diff line(s) differ" : "PASS all " . count($a) . " lines identical") . "\n";
echo "warnings: " . (json_encode(array_column($res->warnings, 'message'), JSON_UNESCAPED_UNICODE)) . "\n";
echo "enajenante: {$res->data['enajenantes'][0]['nombre']} | adquiriente: {$res->data['adquirientes'][0]['nombre']}\n";
}
