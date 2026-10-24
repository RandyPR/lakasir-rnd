<?php

namespace App\Services\Tenants;

/**
 * Turns user supplied HTML (legacy RichEditor order notes) into safe plain text.
 * The result is only ever echoed with {{ }} or sent to a thermal printer as text.
 */
final class PlainText
{
    private function __construct()
    {
    }

    public static function fromHtml(?string $html, int $max = 1000): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $text = mb_scrub($html);

        // Drop the contents of elements that are never text.
        $text = preg_replace('#<(script|style)\b[^>]*>.*?(</\1\s*>|$)#is', '', $text) ?? '';

        // Block level boundaries become line breaks before the tags are removed.
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? '';
        $text = preg_replace('#</(p|div|li|h[1-6])\s*>#i', "\n", $text) ?? '';

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Entities may have produced controls or non-breaking spaces: normalise after decoding.
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\t"], ["\n", "\n", ' ', ' '], $text);
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/', '', $text) ?? '';

        $lines = array_map(
            fn (string $line): string => trim(preg_replace('/ {2,}/', ' ', $line) ?? ''),
            explode("\n", $text)
        );
        $text = trim(implode("\n", $lines));
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        if ($max > 0) {
            $text = rtrim(mb_substr($text, 0, $max));
        }

        return $text === '' ? null : $text;
    }
}
