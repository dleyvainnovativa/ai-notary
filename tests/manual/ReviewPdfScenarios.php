<?php
namespace App\Services { class CatalogService { public function load($n,$d){ $p="$d/catalogs/$n.json"; return file_exists($p)?json_decode(file_get_contents($p),true):null; } public function isValidValue($n,$d,$v){return true;} } }
namespace {
function app($c){ return new $c; }
function data_get($a,$path){ foreach(explode('.',$path) as $k){ if(!is_array($a)||!array_key_exists($k,$a)) return null; $a=$a[$k]; } return $a; }
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
$DOMPDF = getenv('DOMPDF_AUTOLOAD') ?: $R . '/vendor/autoload.php';
require $DOMPDF;
foreach (['Modules/ModuleControllerContract','Modules/ModuleInput','Services/Schema/IdDates','Services/Schema/ValidationIssue','Services/Schema/EngineResult','Services/Schema/SchemaEngine','Services/Pdf/ReviewPdfPresenter','Services/Pdf/ReviewPdfRenderer'] as $f) require "$R/app/$f.php";
require "$R/modules/avisos_uif/v1/Controller.php";

$engine = new App\Services\Schema\SchemaEngine(new App\Services\CatalogService);
$p = new App\Services\Pdf\ReviewPdfPresenter($engine);
$fs = (new Modules\AvisosUif\V1\Controller("$R/modules/avisos_uif/v1"))->formSchema();
$fail = 0;
function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }
function find(array $blocks, callable $pred) { foreach ($blocks as $b) { if ($pred($b)) return $b; foreach (['blocks'] as $k) if (!empty($b[$k])) { $r = find($b[$k], $pred); if ($r) return $r; } if (($b['type'] ?? '') === 'cards') foreach ($b['items'] as $c) { $r = find($c['blocks'], $pred); if ($r) return $r; } } return null; }
function cells(array $blocks): array { $out = []; foreach ($blocks as $b) { if ($b['type']==='grid') foreach ($b['items'] as $i) $out[$i['label']] = $i; } return $out; }

$persona = fn($tipo, $extra) => array_merge(['tipo_persona'=>$tipo,'rfc'=>null,'curp'=>null,'fecha_nacimiento'=>null,'nombre'=>null,'apellido_paterno'=>null,'apellido_materno'=>null,'razon_social'=>null,'fecha_constitucion'=>null,'giro_mercantil'=>null,'numero_fideicomiso'=>null,'nacionalidad'=>'MX','actividad_economica'=>null,'domicilio'=>['tipo_domicilio'=>'1','entidad_federativa'=>'30','calle'=>'X','num_ext'=>'1','codigo_postal'=>'91948','colonia'=>'Flores del Valle','municipio'=>'VERACRUZ']], $extra);
$data = ['referencia_aviso'=>'26763','prioridad'=>'1','tipo_alerta'=>'100','operaciones_acumuladas'=>'0',
  'solicitante'=>['rfc'=>'SARE570420CQ7','curp'=>'SARE570420HNENMD02','nombre'=>'EDUARDO','apellido_paterno'=>'SAN MARTIN'],
  'operaciones'=>[['fecha_operacion'=>'2026-07-30','tipo_transmision'=>'1',
    'adquirentes'=>[
      $persona('1',['rfc'=>'OEEA651127CFA','nombre'=>'ARTURO','apellido_paterno'=>'ORTEGA','apellido_materno'=>'ESCUDERO']),
      $persona('2',['rfc'=>'ICJ950101AB1','razon_social'=>'INMOBILIARIA JUBRISA SA DE CV']),
      $persona('3',['rfc'=>'BBA830831LJ2','razon_social'=>'BANCO X, FIDUCIARIO','numero_fideicomiso'=>'F/1234']),
    ],
    'vendedores'=>[], 'inmueble'=>['valor_pactado'=>2100000,'domicilio'=>['colonia'=>'VISTA ALEGRE']],
    'pagos'=>[['fecha_pago'=>'2026-07-30','monto'=>210000,'moneda'=>'1'],['fecha_pago'=>'2026-07-30','monto'=>1890000.5,'moneda'=>'1']]]]];
$data = $engine->process($fs, $data, "$R/modules/avisos_uif/v1")->data;
$sections = $p->present($fs, $data, [['path'=>'operaciones.0.pagos.1.fecha_pago','message'=>'Fecha tomada…'],['path'=>'operaciones.0.adquirentes.0.domicilio','message'=>'Domicilio tomado de X']]);

echo "Structure\n";
check('3 sections in schema order', array_column($sections,'title') === ['Datos del Aviso','Persona que Solicita','Detalle de la Operación']);
$aviso = cells($sections[0]['blocks']);
check('select shows label, not code (prioridad)', ($aviso['Prioridad']['value'] ?? null) !== '1' && $aviso['Prioridad']['value'] !== null);
check('hidden-by-condition field omitted (descripcion_alerta needs tipo 9999)', !isset($aviso['Descripción de alerta']));
$ops = $sections[2]['blocks'];
$acum = cells($ops);
check('"Seleccionar opción" (value 0) prints as empty, not as a label', array_key_exists('¿Desea agregar operaciones acumuladas?', $acum) && $acum['¿Desea agregar operaciones acumuladas?']['value'] === null);
$opCards = find($ops, fn($b) => ($b['type'] ?? '') === 'cards');
check('operación card titled with item_label', $opCards['items'][0]['title'] === 'Operación 1');

echo "Persona cases (visibility follows the form)\n";
$adq = find($opCards['items'][0]['blocks'], fn($b) => ($b['type'] ?? '') === 'cards' && $b['title'] === 'Adquirentes');
[$fis, $mor, $fid] = $adq['items'];
check('física title has full name', $fis['title'] === 'Adquirente 1 · ARTURO ORTEGA ESCUDERO');
check('badge = tipo de persona label', $fis['badge'] === 'Persona Física');
$cf = cells($fis['blocks']); $cm = cells($mor['blocks']); $cfi = cells($fid['blocks']);
check('física shows CURP, hides razón social', isset($cf['CURP']) && !isset($cf['Denominación o Razón Social']));
check('física birth date derived from RFC (1965-11-27)', $cf['Fecha de Nacimiento']['value'] === '27/11/1965');
check('física missing required CURP flagged', $cf['CURP']['missing'] === true);
check('moral shows razón social, hides CURP / nombre', isset($cm['Denominación o Razón Social']) && !isset($cm['CURP']) && !isset($cm['Nombre(s)']));
check('moral title uses razón social', str_ends_with($mor['title'], 'INMOBILIARIA JUBRISA SA DE CV'));
check('moral fecha_constitucion derived from RFC (01/01/1995)', $cm['Fecha de Constitución']['value'] === '01/01/1995');
check('fideicomiso shows número de fideicomiso, hides nacionalidad', isset($cfi['Número, Referencia o Identificador del Fideicomiso']) && !isset($cfi['País de Nacionalidad']));
$domGroup = find($fis['blocks'], fn($b) => ($b['type'] ?? '') === 'group');
check('object note attached to Domicilio group', $domGroup['notes'] === ['Domicilio tomado de X']);
check('colonia (cp_target, not in options) prints raw value', cells($domGroup['blocks'])['Colonia']['value'] === 'Flores del Valle');

echo "Pagos table\n";
$tbl = find($opCards['items'][0]['blocks'], fn($b) => ($b['type'] ?? '') === 'table');
check('pagos rendered as table', $tbl !== null && count($tbl['rows']) === 2);
$montoIdx = array_search('Monto', array_column($tbl['columns'], 'label'));
check('money formatting $1,890,000.50', $tbl['rows'][1][$montoIdx]['value'] === '$1,890,000.50');
check('money total $2,100,000.50', $tbl['totals'][$montoIdx] === '$2,100,000.50');
check('date dd/mm/yyyy in table', $tbl['rows'][0][0]['value'] === '30/07/2026');
check('cell note attached (pagos.1.fecha_pago)', $tbl['rows'][1][0]['notes'] === ['Fecha tomada…']);
$inm = find($opCards['items'][0]['blocks'], fn($b) => ($b['type'] ?? '') === 'group' && $b['title'] === 'Inmueble');
check('valor pactado money $2,100,000.00', cells($inm['blocks'])['Valor Pactado']['value'] === '$2,100,000.00');

echo "Escaping + stress render\n";
$data['operaciones'][0]['adquirentes'][0]['nombre'] = '<script>alert(1)</script> & "X"';
$big = $data;
for ($o = 0; $o < 3; $o++) { $op = $data['operaciones'][0]; $op['adquirentes'] = array_merge($op['adquirentes'], $op['adquirentes'], [$op['adquirentes'][0], $op['adquirentes'][1]]); $op['vendedores'] = $op['adquirentes']; $big['operaciones'][$o] = $op; }
$r = new App\Services\Pdf\ReviewPdfRenderer("$R/resources/views/pdf/review.php", sys_get_temp_dir() . '/dompdf_test');
$html = $r->html(['module'=>'Avisos UIF','reference'=>'26763','generated_at'=>'x','status'=>null,'notaria'=>null,'draft_saved_at'=>null], $p->present($fs, $big, []));
check('HTML escapes data (no raw <script>)', !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'));
$t = microtime(true);
$pdf = $r->pdf(['module'=>'Avisos UIF','reference'=>'26763','generated_at'=>'x','status'=>null,'notaria'=>null,'draft_saved_at'=>null], $p->present($fs, $big, []));
$secs = microtime(true) - $t;

check(sprintf('3 operaciones × 16 personas rendered in %.1fs (< 20s)', $secs), str_starts_with($pdf, '%PDF') && $secs < 20);
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
}
