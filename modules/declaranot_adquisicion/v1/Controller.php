<?php

namespace Modules\DeclaranotAdquisicion\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../declaranot/v1/Controller.php';

/**
 * DeclaraNOT por adquisición de bienes (SAT layout 910xxx, Configuracion type 25).
 *
 * Reuses DeclaraNOT's person forms (adquirientes / enajenantes), catalogs (via
 * module.json "extends"), the nuda-propiedad value breakdown and the review UI.
 * Differences from DeclaraNOT:
 *   - no "Pagos del inmueble" (900002) and no "Datos informativos" (900004);
 *   - new "Monto de la operación" (910001, 0 for free acquisitions);
 *   - ISR por adquisición (910004): ingreso acumulable | ISR | núm. operación | fecha;
 *   - copropiedad (910007): rfc | % | monto operación | avalúo | ingreso acumulable | ISR.
 */
class Controller extends \Modules\Declaranot\V1\Controller
{
    public function __construct(private string $ownDir)
    {
        parent::__construct($ownDir);
    }

    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura pública',
                required: true,
                promptPath: 'adquisicion_prompt.txt',
                schemaPath: 'adquisicion_schema.json',
                outputPath: 'adquisicion_output.json',
                description: 'El documento de escritura notariado. Esta es la fuente principal para la extracción.',
            ),
            new ModuleInput(
                key: 'calculo',
                label: 'Guía de cálculo de ISR por adquisición',
                required: false,
                promptPath: 'calculo_adquisicion_prompt.txt',
                schemaPath: 'calculo_adquisicion_schema.json',
                outputPath: 'calculo_adquisicion_output.json',
                description: 'Opcional. El cálculo del ISR por adquisición de bienes. Agrega el pago y la copropiedad.',
            ),
        ];
    }

    public function schema(): array
    {
        $escritura = json_decode(file_get_contents($this->ownDir . '/adquisicion_schema.json'), true);
        $calculo = json_decode(file_get_contents($this->ownDir . '/calculo_adquisicion_schema.json'), true);
        return ['schema_version' => '1.0.0', 'fields' => array_merge($escritura['fields'], $calculo['fields'])];
    }

    public function formSchema(): array
    {
        $base = parent::formSchema();
        $bf = $base['fields'];
        $money = ['type' => 'number', 'format' => 'round', 'integer' => true, 'money' => true];

        $fields = [];
        foreach (['numero_escritura', 'fecha_firma_escritura', 'tipo_inmueble', 'especifica_inmueble', 'avaluo_inmueble'] as $k) {
            $fields[$k] = $bf[$k];
        }
        $fields['monto_operacion'] = ['label' => 'Monto de la Operación', 'required' => true,
            'subtitle' => 'Precio pactado. 0 si la adquisición es gratuita (donación, herencia, legado).'] + $money;
        $fields['adquirientes'] = $bf['adquirientes'];
        $fields['enajenantes'] = $bf['enajenantes'];

        $fields['pago'] = [
            'item_label' => 'Pago',
            'label' => 'ISR por Adquisición',
            'type' => 'array',
            'itemSchema' => [
                'ingreso_acumulable' => ['label' => 'Ingreso Acumulable', 'required' => true] + $money,
                'isr_federacion' => ['label' => 'ISR Federación', 'required' => true] + $money,
                'numero_operacion' => ['label' => 'Número de Operación', 'type' => 'text', 'required_if' => ['isr_federacion' => ['op' => '>', 'value' => 0]]],
                'fecha_pago' => ['label' => 'Fecha de Pago', 'type' => 'date', 'required_if' => ['isr_federacion' => ['op' => '>', 'value' => 0]]],
            ],
        ];

        $cop = $bf['copropiedad'];
        $cop['itemSchema']['integrantes']['itemSchema'] = [
            'rfc' => ['label' => 'RFC', 'type' => 'text', 'validation' => ['format' => 'rfc']],
            'porcentaje' => ['label' => 'Porcentaje (%)', 'type' => 'number', 'format' => 'round', 'integer' => true],
            'monto_operacion' => ['label' => 'Monto de la Operación'] + $money,
            'valor_avaluo' => ['label' => 'Valor de Avalúo'] + $money,
            'ingreso_acumulable' => ['label' => 'Ingreso Acumulable'] + $money,
            'isr_federacion' => ['label' => 'ISR Federación'] + $money,
        ];
        $fields['copropiedad'] = $cop;
        $fields['representante_comun'] = $bf['representante_comun'];

        $sections = [
            ['title' => 'Información General', 'subtitle' => 'Datos generales del inmueble', 'fields' => ['numero_escritura', 'fecha_firma_escritura', 'tipo_inmueble', 'especifica_inmueble', 'avaluo_inmueble', 'monto_operacion']],
            ['title' => 'Adquirientes', 'fields' => ['adquirientes']],
            ['title' => 'Enajenantes', 'fields' => ['enajenantes']],
            ['title' => 'ISR por Adquisición', 'fields' => ['pago']],
            ['title' => 'Copropiedad', 'fields' => ['copropiedad']],
            ['title' => 'Representante Común', 'fields' => ['representante_comun']],
        ];

        return ['fields' => $fields, 'sections' => $sections];
    }
}
