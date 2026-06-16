<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory;

class TextExtractor
{
    /** Result object: text + page count + whether it looked scanned. */
    public function extract(string $absolutePath, string $mime): ExtractionResult
    {
        return match ($mime) {
            'application/pdf' => $this->extractPdf($absolutePath),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            => $this->extractDocx($absolutePath),
            default => throw new \RuntimeException("Unsupported type: {$mime}"),
        };
    }

    private function extractPdf(string $path): ExtractionResult
    {
        try {
            $pdf = (new PdfParser)->parseFile($path);
            $pageCount = count($pdf->getPages());
            $text = trim($pdf->getText());
        } catch (\Throwable $e) {
            // smalot/pdfparser can throw on complex PDFs (e.g. "regex too large").
            // Treat as unreadable → graceful fail downstream, token released.
            Log::warning('PDF parse failed', ['error' => $e->getMessage()]);
            return new ExtractionResult('', null, scanned: true);
        }

        if (mb_strlen($text) < 20) {
            return new ExtractionResult('', $pageCount, scanned: true);
        }

        return new ExtractionResult($text, $pageCount, scanned: false);
    }

    private function extractDocx(string $path): ExtractionResult
    {
        $phpWord = IOFactory::load($path);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->elementText($element) . "\n";
            }
        }

        return new ExtractionResult(trim($text), null, scanned: false);
    }

    private function elementText($element): string
    {
        if (method_exists($element, 'getText')) {
            $t = $element->getText();
            return is_string($t) ? $t : '';
        }
        // Nested elements (tables, text runs)
        if (method_exists($element, 'getElements')) {
            $out = '';
            foreach ($element->getElements() as $child) {
                $out .= $this->elementText($child) . ' ';
            }
            return $out;
        }
        return '';
    }
}
