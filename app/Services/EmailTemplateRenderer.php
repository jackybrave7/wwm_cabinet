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
                ? wwm_repair_email_html((string)$custom['body_html'])
                : null;

            return [
                'subject' => self::applyVars($custom['subject'], $vars),
                'text' => self::applyVars($custom['body_text'], $vars),
                'html' => $bodyHtml !== null ? self::applyVars($bodyHtml, $vars) : null,
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
            return [
                'subject' => $custom['subject'],
                'text' => $custom['body_text'],
                'html' => wwm_repair_email_html(
                    $custom['body_html'] !== null ? (string)$custom['body_html'] : null
                ),
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
}
