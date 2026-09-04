<?php

namespace Tests\Support;

/**
 * Builds real PDFs in memory so the page-cap tests do not need committed binaries at every page
 * count. The output has a correct cross-reference table because `pdfinfo` is what reads it in
 * ValidateUploadedFile, and a PDF it has to repair is not the thing under test.
 */
final class PdfFixture
{
    /** A structurally valid PDF with `$pages` empty A4-ish pages. */
    public static function pages(int $pages): string
    {
        $objects = [];

        // 1 catalog, 2 page tree, 3..n the pages themselves.
        $kids = implode(' ', array_map(fn (int $i) => ($i + 3).' 0 R', range(0, $pages - 1)));
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = "<< /Type /Pages /Kids [{$kids}] /Count {$pages} >>";

        foreach (range(1, $pages) as $ignored) {
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>';
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$body}\nendobj\n";
        }

        $startXref = strlen($pdf);
        $count = count($objects) + 1;

        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$startXref}\n%%EOF\n";

        return $pdf;
    }

    /** Header of a real PDF followed by rubbish: passes a magic-byte check, fails to parse. */
    public static function corrupt(): string
    {
        return "%PDF-1.4\n".str_repeat('not actually a pdf ', 100);
    }
}
