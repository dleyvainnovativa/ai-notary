<?php
namespace Carbon { class Carbon { private $d; function __construct($d){$this->d=$d;} static function parse($x){ return new self(new \DateTimeImmutable((string)$x)); } function format($f){ return $this->d->format($f);} } }
namespace App\Modules { interface ExporterContract {} }
namespace {
function now(){ return Carbon\Carbon::parse('2026-10-03'); }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
require "$R/modules/avisos_uif/v1/Exporter.php";
$fail = 0; function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }
$d = ['referencia_aviso'=>'1','solicitante'=>['rfc'=>'MUÑO800101AB1','nombre'=>'JOSÉ ÁNGEL','apellido_paterno'=>'MUÑOZ','apellido_materno'=>'PEÑA'],
 'operaciones'=>[['fecha_operacion'=>'2026-07-30','tipo_transmision'=>'1',
  'adquirentes'=>[['tipo_persona'=>'1','rfc'=>'OEEA651127CFA','nombre'=>'MARÍA DE LOS ÁNGELES','apellido_paterno'=>'NÚÑEZ','apellido_materno'=>'GÜEMES','domicilio'=>['tipo_domicilio'=>'1','entidad_federativa'=>'30','calle'=>'AV. CUAUHTÉMOC','codigo_postal'=>'91700','colonia'=>'La Cañada Hípica','municipio'=>'BOCA DEL RÍO']]],
  'vendedores'=>[], 'inmueble'=>['tipo_bien'=>'3','valor_pactado'=>1,'valor_avaluo'=>1,'domicilio'=>['entidad_federativa'=>'30','calle'=>'CALLE ÉBANO','colonia'=>'Jardines de Mocambo','municipio'=>'BOCA DEL RÍO']],
  'pagos'=>[['fecha_pago'=>'2026-07-30','forma_pago'=>'1','instrumento'=>'8','moneda'=>'1','monto'=>1]]]]];
$bytes = (new Modules\AvisosUif\V1\Exporter())->export($d, 'txt');
$t = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
echo "Accent / Ñ normalization (UIF)\n";
check('names lose accents and Ñ: JOSE ANGEL MUNOZ PENA', str_contains($t, '|JOSE ANGEL|MUNOZ|PENA'));
check('Ü → U, Ú/Ñ → U/N: MARIA DE LOS ANGELES NUNEZ GUEMES', str_contains($t, 'MARIA DE LOS ANGELES|NUNEZ|GUEMES'));
check('RFC with Ñ → N (MUNO800101AB1)', str_contains($t, 'MUNO800101AB1'));
check('calle / municipio folded (AV. CUAUHTEMOC, BOCA DEL RIO, CALLE EBANO)', str_contains($t, 'AV. CUAUHTEMOC') && str_contains($t, 'BOCA DEL RIO') && str_contains($t, 'CALLE EBANO'));
check('colonia keeps accents AND ñ exactly (La Cañada Hípica)', str_contains($t, '|La Cañada Hípica|'));
check('file is Windows-1252 (ANSI): only the colonia has non-ASCII bytes', preg_match_all('/[\x80-\xFF]/', $bytes) === 2 && str_contains($bytes, "La Ca\xF1ada H\xEDpica"));
$d2 = $d; $d2['operaciones'][0]['adquirentes'][0]['domicilio']['colonia'] = 'Centro';
check('no accented colonia → pure ASCII file', !preg_match('/[\x80-\xFF]/', (new Modules\AvisosUif\V1\Exporter())->export($d2, 'txt')));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
}
