<?php
/**
 * scripts/tools/extract-block.php - safely remove a top-level block from portal.js
 *
 * Usage:
 *   php scripts/tools/extract-block.php "start marker" "end marker"
 *
 * Removes everything from the line containing the start marker up to but not
 * including the line containing the end marker. Fails loudly if the markers are
 * missing or ambiguous, and verifies the result still parses before writing.
 *
 * This exists because hand-editing a 2,500-line file by line number is how you
 * corrupt it. Every extraction goes through here.
 */

$root = dirname(__DIR__, 2);
$file = $root . '/scripts/portal.js';

if ($argc < 3) {
    fwrite(STDERR, "usage: php extract-block.php <start-marker> <end-marker>\n");
    exit(1);
}

$startMarker = $argv[1];
$endMarker   = $argv[2];

$source = file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "cannot read $file\n");
    exit(1);
}

$startCount = substr_count($source, $startMarker);
$endCount   = substr_count($source, $endMarker);

if ($startCount !== 1) {
    fwrite(STDERR, "start marker must appear exactly once, found $startCount\n");
    exit(1);
}
if ($endCount !== 1) {
    fwrite(STDERR, "end marker must appear exactly once, found $endCount\n");
    exit(1);
}

$start = strpos($source, $startMarker);
$end   = strpos($source, $endMarker);

if ($end < $start) {
    fwrite(STDERR, "end marker appears before start marker\n");
    exit(1);
}

// Align to line boundaries so we never cut a statement in half.
$start = strrpos(substr($source, 0, $start), "\n") + 1;
$end   = strrpos(substr($source, 0, $end), "\n") + 1;

$removed = substr($source, $start, $end - $start);
$result  = substr($source, 0, $start) . substr($source, $end);

// Write to a temp file and syntax-check before touching the real one.
// The extension must stay .mjs/.js or node refuses to parse it.
$tmp = dirname($file) . '/.portal_check.js';
file_put_contents($tmp, $result);

exec('node --check ' . escapeshellarg(str_replace('/', '\\', $tmp)) . ' 2>&1', $out, $code);
if ($code !== 0) {
    @unlink($tmp);
    fwrite(STDERR, "result would not parse - aborting:\n" . implode("\n", $out) . "\n");
    exit(1);
}
@unlink($tmp);

file_put_contents($file, $result);

printf("removed %d lines (%d bytes)\n", substr_count($removed, "\n"), strlen($removed));
printf("portal.js is now %d lines\n", substr_count($result, "\n"));
