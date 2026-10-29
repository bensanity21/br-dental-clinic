<?php
// Development check: identify static patient-portal text without a Filipino entry.
$page = __DIR__ . '/../user.html';
$dictionary = __DIR__ . '/i18n.js';
$html = file_get_contents($page);
$js = file_get_contents($dictionary);
preg_match('/const filipino = \{([\s\S]*?)\n\};/', $js, $dictionaryBlock);
preg_match_all("/'((?:\\\\.|[^'])*)'\\s*:/", $dictionaryBlock[1] ?? '', $matches);
$keys = array_fill_keys(array_map('stripcslashes', $matches[1] ?? []), true);

$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="UTF-8">' . $html);
libxml_clear_errors();
$xpath = new DOMXPath($document);
$sections = $xpath->query('//section[starts-with(@id, "view-patient-")]');
$allowUntranslated = array_fill_keys([
    'BR Dental Clinic', 'Dr. Ma. Elena B. Retuerna', 'DR',
    '73 Nyugan Road, Paliparan 1', 'Dasmariñas City, Cavite',
    '73 Nyugan Road, Paliparan 1, Dasmariñas City, Cavite',
    '09066636112', '*', '09:00 AM', '10:30 AM', '01:30 PM', '03:00 PM',
    'P', '—', 'English', 'Paliparan 1, Dasmariñas City',
], true);
$missing = [];
foreach ($sections as $section) {
    foreach ($xpath->query('.//text()[normalize-space()]', $section) as $textNode) {
        $phrase = preg_replace('/\s+/u', ' ', trim($textNode->nodeValue));
        if (!isset($keys[$phrase]) && !isset($allowUntranslated[$phrase])) {
            $missing[$phrase] = true;
        }
    }
}
if ($missing) {
    fwrite(STDERR, "Untranslated static patient-portal text:\n - " . implode("\n - ", array_keys($missing)) . "\n");
    exit(1);
}
echo 'Static patient-portal text coverage passed (' . count($keys) . " reviewed phrases).\n";
