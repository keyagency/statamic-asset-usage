<?php

namespace KeyAgency\AssetUsage\Export;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Turns a report into a PDF. Remote files, PHP and JavaScript in the HTML are
 * off: the thumbnails come along as data URIs, and file names are content
 * users control.
 */
class Pdf
{
    private const FONT = 'DejaVu Sans';

    private const FONT_SIZE = 7;

    /** Matches the bottom margin in the view, so the page number sits inside it. */
    private const MARGIN = 28;

    public function render(Report $report): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont(self::FONT);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('asset-usage::export', ['report' => $report, 'logo' => $this->logo()])->render(), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $this->numberPages($dompdf);

        return (string) $dompdf->output();
    }

    /** A data URI like the thumbnails, since dompdf reads no files here. */
    private function logo(): string
    {
        return 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents(__DIR__.'/../../resources/images/key-agency.jpg'));
    }

    /** Only known once everything is laid out, so dompdf writes it onto each page afterwards. */
    private function numberPages(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont(self::FONT);

        $text = __('asset-usage::messages.export.page', ['page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']);

        // Measured with numbers in place of the placeholders, which dompdf only fills in while writing.
        $width = $metrics->getTextWidth(str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], '000', $text), $font, self::FONT_SIZE);

        $canvas->page_text(
            $canvas->get_width() - self::MARGIN - $width,
            $canvas->get_height() - self::MARGIN + 8,
            $text,
            $font,
            self::FONT_SIZE,
            [0.42, 0.45, 0.5],
        );
    }
}
