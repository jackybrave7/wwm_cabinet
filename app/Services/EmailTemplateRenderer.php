<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\EmailTemplate;

final class EmailTemplateRenderer
{
    /**
     * @param array<string, scalar|null> $context
     * @return array{subject: string, text: string, html: ?string}
     */
    public static function render(string $templateId, array $context): array
    {
        $vars = self::normalizeVars($context);
        $custom = EmailTemplate::find(wwm_pdo(), $templateId);
        if ($custom !== null) {
            $bodyHtml = $custom['body_html'] !== null
                ? self::finalizeHtmlBody(
                    self::applyVars((string)$custom['body_html'], $vars),
                    $vars,
                    $templateId,
                    $context
                )
                : null;

            return [
                'subject' => self::applyVars($custom['subject'], $vars),
                'text' => self::applyVars($custom['body_text'], $vars),
                'html' => $bodyHtml,
            ];
        }

        return self::builtIn($templateId, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array{subject: string, text: string, html: ?string}
     */
    public static function builtIn(string $templateId, array $context): array
    {
        return EmailTemplateCatalog::builtInMessage($templateId, $context);
    }

    /**
     * @return array{subject: string, text: string, html: ?string, customized: bool}
     */
    public static function forAdmin(string $templateId): array
    {
        $custom = EmailTemplate::find(wwm_pdo(), $templateId);
        if ($custom !== null) {
            $html = null;
            if ($custom['body_html'] !== null && trim((string)$custom['body_html']) !== '') {
                $raw = wwm_sanitize_utf8((string)$custom['body_html']);
                try {
                    $html = wwm_repair_email_html($raw);
                } catch (\Throwable $e) {
                    wwm_log('email template repair failed for ' . $templateId . ': ' . $e->getMessage());
                    $html = $raw;
                }
            }

            return [
                'subject' => wwm_sanitize_utf8((string)$custom['subject']),
                'text' => wwm_sanitize_utf8((string)$custom['body_text']),
                'html' => is_string($html) ? $html : null,
                'customized' => true,
            ];
        }

        $preview = EmailTemplateCatalog::placeholderDraft($templateId);

        return [
            'subject' => $preview['subject'],
            'text' => $preview['text'],
            'html' => $preview['html'],
            'customized' => false,
        ];
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array<string, string>
     */
    public static function normalizeVars(array $context): array
    {
        $vars = [
            'base_url' => wwm_base_url(),
            'login_url' => '',
            'magic_link' => '',
            'reset_link' => '',
            'name' => '',
            'email' => '',
            'course_title' => '',
            'cover_url' => '',
            'logo_url' => wwm_email_logo_url(),
            'course_page_url' => '',
            'password' => '',
            'expires_label' => '',
            'buy_url' => '',
            'coupon_code' => '',
            'magic_link_hours' => '',
        ];

        foreach ($context as $key => $value) {
            if (!is_string($key) || $value === null) {
                continue;
            }
            $vars[$key] = is_scalar($value) ? (string)$value : '';
        }

        if ($vars['name'] === '') {
            $vars['name'] = 'there';
        }

        return $vars;
    }

    /**
     * @param array<string, string> $vars
     */
    public static function applyVars(string $template, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * @param array<string, string> $vars
     */
    private static function finalizeHtmlBody(string $html, array $vars, string $templateId, array $context): string
    {
        $html = wwm_repair_email_html($html) ?? '';
        $html = wwm_email_ensure_cover_row($html, $vars['cover_url'] ?? '') ?? '';
        $html = EmailTemplateDrafts::finalizeHtml($html, $vars) ?? '';

        $issues = wwm_email_html_issues($html);
        $critical = array_filter(
            $issues,
            static fn (string $issue): bool => str_contains($issue, 'broken')
                || str_contains($issue, 'unbalanced')
        );
        if ($critical !== []) {
            try {
                $builtin = EmailTemplateCatalog::builtInMessage($templateId, $context);
                $builtinHtml = $builtin['html'] ?? null;
                if (is_string($builtinHtml) && trim($builtinHtml) !== '') {
                    wwm_log(
                        'email template ' . $templateId
                        . ' fell back to built-in HTML after repair: '
                        . implode(', ', $critical)
                    );

                    return $builtinHtml;
                }
            } catch (\InvalidArgumentException) {
                // keep repaired custom html
            }
        }

        return $html;
    }
}
