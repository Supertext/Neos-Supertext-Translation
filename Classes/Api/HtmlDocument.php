<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Api;

/**
 * Packs many strings into one HTML document and splits the translated document
 * back apart. Supertext preserves markup and attributes and only translates text
 * nodes, so every segment travels inside <div data-st-id="N">…</div>.
 *
 * Plain-text segments are escaped (line breaks sent as <br>) so they survive the
 * round trip unchanged; HTML segments (rich text) are sent as-is.
 */
final class HtmlDocument
{
    private const LINE_BREAK_MARKER = "\u{1E}";

    /**
     * @param array<int, array{text: string, html: bool}> $segments
     */
    public static function build(array $segments): string
    {
        $html = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"></head><body>\n";
        foreach ($segments as $id => $segment) {
            if ($segment['html']) {
                $content = $segment['text'];
            } else {
                $text = str_replace(["\r\n", "\r"], "\n", $segment['text']);
                $content = str_replace("\n", '<br>', htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $html .= '<div data-st-id="' . (int)$id . '">' . $content . "</div>\n";
        }
        return $html . '</body></html>';
    }

    /**
     * @param array<int, bool> $isHtml segment id => whether it was sent as HTML
     * @return array<int, string> segment id => translated text
     */
    public static function parse(string $html, array $isHtml): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $result = [];
        $nodes = (new \DOMXPath($dom))->query('//div[@data-st-id]');
        if ($nodes === false) {
            return $result;
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $id = (int)$node->getAttribute('data-st-id');
            if (($isHtml[$id] ?? false) === true) {
                $inner = '';
                foreach ($node->childNodes as $child) {
                    $inner .= (string)$dom->saveHTML($child);
                }
                $result[$id] = trim($inner);
                continue;
            }
            // Plain text: <br> are the real line breaks; any other whitespace
            // (including source formatting newlines) collapses to one space.
            foreach (iterator_to_array($node->getElementsByTagName('br')) as $br) {
                $br->parentNode?->replaceChild($dom->createTextNode(self::LINE_BREAK_MARKER), $br);
            }
            $text = preg_replace('/\s+/u', ' ', $node->textContent) ?? $node->textContent;
            $text = preg_replace('/ ?' . self::LINE_BREAK_MARKER . ' ?/u', "\n", $text) ?? $text;
            $result[$id] = trim($text, " ");
        }
        return $result;
    }
}
