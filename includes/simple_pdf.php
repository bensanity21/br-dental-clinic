<?php
// Minimal dependency-free PDF writer for printable, ASCII-font clinic reports.
function simplePdfText(string $value): string {
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function buildSimplePdf(array $lines): string {
    $linesPerPage = 48;
    $pages = array_chunk($lines ?: ['No report data.'], $linesPerPage);
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $kids = [];
    foreach ($pages as $index => $pageLines) {
        $pageId = 4 + ($index * 2);
        $streamId = $pageId + 1;
        $kids[] = $pageId . ' 0 R';
        $stream = "BT\n/F1 9 Tf\n48 756 Td\n12 TL\n";
        foreach ($pageLines as $line) {
            $stream .= '(' . simplePdfText((string)$line) . ") Tj\nT*\n";
        }
        $stream .= "ET\n";
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $streamId . ' 0 R >>';
        $objects[$streamId] = "<< /Length " . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $size = max(array_keys($objects)) + 1;
    $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
    for ($id = 1; $id < $size; $id++) $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0) . "\n";
    $pdf .= "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return $pdf;
}
