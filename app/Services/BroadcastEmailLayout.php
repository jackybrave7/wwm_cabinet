<?php
declare(strict_types=1);

namespace Wwm\Services;

final class BroadcastEmailLayout
{
    public static function forDelivery(string $html, string $subject): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $inner = self::extractBodyInner($html);
        $inner = self::stripDarkColorSchemeMeta($inner);

        return self::wrapInner($inner, $subject);
    }

    private static function extractBodyInner(string $html): string
    {
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $match)) {
            return trim((string)$match[1]);
        }

        if (preg_match('/<html\b/i', $html)) {
            $stripped = preg_replace('#</?(html|head|body)\b[^>]*>#i', '', $html) ?? $html;
            $stripped = preg_replace('#<head\b.*?</head>#is', '', $stripped) ?? $stripped;

            return trim($stripped);
        }

        return $html;
    }

    private static function stripDarkColorSchemeMeta(string $html): string
    {
        $html = preg_replace('#<meta[^>]+name=["\']color-scheme["\'][^>]*>#i', '', $html) ?? $html;
        $html = preg_replace(
            '#<body\b([^>]*)\bstyle=["\']([^"\']*)["\']#i',
            static function (array $m): string {
                $style = (string)$m[2];
                if (preg_match('/background(?:-color)?\s*:\s*#(0{3,6}|111|1a1a1a)\b/i', $style)) {
                    $style = preg_replace(
                        '/background(?:-color)?\s*:\s*#[0-9a-f]{3,8}\b/i',
                        'background-color:#ffffff',
                        $style
                    ) ?? $style;
                }

                return '<body' . $m[1] . ' style="' . $style . '"';
            },
            $html
        ) ?? $html;

        return $html;
    }

    private static function wrapInner(string $inner, string $subject): string
    {
        $titleHtml = '';
        $subject = trim($subject);
        if ($subject !== '' && !self::bodyStartsWithTitle($inner)) {
            $titleHtml = '<h1 style="margin:0 0 18px;padding:0;font-size:22px;line-height:1.35;font-weight:700;color:#1a1a1a;font-family:Georgia,\'Times New Roman\',serif;">'
                . htmlspecialchars($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '</h1>';
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light only">'
            . '<meta name="supported-color-schemes" content="light">'
            . '</head><body style="margin:0;padding:0;background:#eceae6;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eceae6;">'
            . '<tr><td align="center" style="padding:16px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;">'
            . '<tr><td style="padding:24px 20px;font-family:Georgia,\'Times New Roman\',serif;font-size:16px;line-height:1.55;color:#1a1a1a;">'
            . $titleHtml
            . $inner
            . '</td></tr></table></td></tr></table></body></html>';
    }

    private static function bodyStartsWithTitle(string $inner): bool
    {
        $snippet = mb_substr(ltrim($inner), 0, 400);

        return (bool)preg_match('/^<h1\b/i', $snippet);
    }
}
