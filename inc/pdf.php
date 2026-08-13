<?php
declare(strict_types=1);

/**
 * Minimaler PDF-Writer: eine Seite, ein eingebettetes JPEG – mehr braucht der
 * Druck-Export nicht, und es bleibt bei null Abhängigkeiten.
 *
 * Das JPEG wird verlustarm (DCTDecode) eingebettet; bei 2048 px auf 80 mm
 * entspricht das ~650 dpi – deutlich über Druckqualität.
 */
function pdf_single_image(string $jpeg, int $imgW, int $imgH, float $widthMm = 80.0): string
{
    $wPt = $widthMm * 72 / 25.4;
    $hPt = $wPt * $imgH / $imgW;
    $content = sprintf('q %.2F 0 0 %.2F 0 0 cm /Im1 Do Q', $wPt, $hPt);

    $out = "%PDF-1.4\n";
    $offsets = [];
    $add = function (int $num, string $body) use (&$out, &$offsets): void {
        $offsets[$num] = strlen($out);
        $out .= $num . " 0 obj\n" . $body . "\nendobj\n";
    };

    $add(1, '<</Type/Catalog/Pages 2 0 R>>');
    $add(2, '<</Type/Pages/Kids[3 0 R]/Count 1>>');
    $add(3, sprintf('<</Type/Page/Parent 2 0 R/MediaBox[0 0 %.2F %.2F]'
        . '/Resources<</XObject<</Im1 4 0 R>>>>/Contents 5 0 R>>', $wPt, $hPt));
    $add(4, '<</Type/XObject/Subtype/Image/Width ' . $imgW . '/Height ' . $imgH
        . '/ColorSpace/DeviceRGB/BitsPerComponent 8/Filter/DCTDecode/Length ' . strlen($jpeg) . ">>\nstream\n"
        . $jpeg . "\nendstream");
    $add(5, '<</Length ' . strlen($content) . ">>\nstream\n" . $content . "\nendstream");

    $xref = strlen($out);
    $out .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) {
        $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $out .= "trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n" . $xref . "\n%%EOF";
    return $out;
}
