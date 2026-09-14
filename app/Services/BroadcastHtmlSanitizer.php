<?php
declare(strict_types=1);

namespace Wwm\Services;

final class BroadcastHtmlSanitizer
{
    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? $html;
        $html = preg_replace('#\s(on\w+|formaction)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace('#(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace('#\ssrc\s*=\s*("|\')data:[^\1]*\1#i', '', $html) ?? $html;

        return $html;
    }

    public static function plainTextFromHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $text = preg_replace('#<(br|/p|/div|/li|/tr)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
