<?php

namespace KeyAgency\AssetUsage\Export;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Turns a report, or one part of it, into a PDF. Remote files, PHP and
 * JavaScript in the HTML are off: the thumbnails come along as data URIs, and
 * file names are content users control. The page numbers are added by the
 * page once it has joined the parts (support/joinPdf.js).
 */
class Pdf
{
    private const FONT = 'DejaVu Sans';

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

        return (string) $dompdf->output();
    }

    /** A data URI like the thumbnails, since dompdf reads no files here. */
    private function logo(): string
    {
        return 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents(__DIR__.'/../../resources/images/key-agency.jpg'));
    }
}
