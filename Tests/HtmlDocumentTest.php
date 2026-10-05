<?php

// Standalone round-trip test for the HTML packer (no Neos needed):
//   php Tests/HtmlDocumentTest.php
declare(strict_types=1);

require __DIR__ . '/../Classes/Api/HtmlDocument.php';

use Supertext\NeosTranslation\Api\HtmlDocument;

$segments = [
    ['text' => 'Fish & Chips < 10 CHF, "quoted"', 'html' => false],
    ['text' => "Monday|9-18\nSaturday|10-16\n\nSunday|closed", 'html' => false],
    ['text' => '<p>We ship <strong>Swiss chocolate</strong>.</p><ul><li>Fast</li></ul>', 'html' => true],
    ['text' => 'Grüezi – café, naïve, 日本語', 'html' => false],
];
$isHtml = array_map(static fn(array $s): bool => $s['html'], $segments);

// Simulate Supertext re-serialising the document with different whitespace.
$document = str_replace("</div>\n", "</div>\n\n   ", HtmlDocument::build($segments));
$parsed = HtmlDocument::parse($document, $isHtml);

$failures = 0;
foreach ($segments as $i => $segment) {
    if (($parsed[$i] ?? null) !== $segment['text']) {
        $failures++;
        fwrite(STDERR, sprintf("Segment %d mismatch:\n  expected: %s\n  actual:   %s\n", $i, json_encode($segment['text']), json_encode($parsed[$i] ?? null)));
    }
}
echo $failures === 0 ? "OK: " . count($segments) . " segments round-trip unchanged\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
