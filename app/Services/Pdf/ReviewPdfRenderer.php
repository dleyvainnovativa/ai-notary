<?php

namespace App\Services\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders the review view model to PDF bytes with dompdf (pure PHP → works on
 * shared hosting). Template: resources/views/pdf/review.php (plain PHP).
 */
class ReviewPdfRenderer
{
    public function __construct(private ?string $templatePath = null, private ?string $tempDir = null) {}

    public function html(array $doc, array $sections): string
    {
        $template = $this->templatePath ?? resource_path('views/pdf/review.php');
        ob_start();
        try {
            (static function (string $__template, array $doc, array $sections) {
                include $__template;
            })($template, $doc, $sections);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function pdf(array $doc, array $sections): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);        // never fetch URLs from document data
        $options->set('isPhpEnabled', false);
        $options->set('dpi', 96);
        if ($this->tempDir && !is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0775, true);
        }
        if ($this->tempDir && is_dir($this->tempDir)) {
            $options->set('tempDir', $this->tempDir);
            $options->set('fontCache', $this->tempDir);
        }

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($doc, $sections), 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        // Footer on every page: page numbers (dompdf fills the placeholders)
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $w = $canvas->get_width();
        $h = $canvas->get_height();
        $canvas->page_text($w - 110, $h - 34, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7, [0.42, 0.45, 0.5]);
        $canvas->page_text(42, $h - 34, $doc['module'] . ' · ' . $doc['reference'], $font, 7, [0.42, 0.45, 0.5]);

        return $dompdf->output();
    }
}
