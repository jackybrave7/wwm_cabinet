<?php
declare(strict_types=1);

namespace Wwm\Services;

final class TransactionalEmail
{
    /**
     * @return array{subject: string, text: string, html: string}
     */
    public static function paidAccess(
        string $name,
        string $email,
        string $courseTitle,
        ?string $courseCoverUrl,
        ?string $coursePageUrl,
        string $magicLoginUrl,
        string $prefilledLoginUrl,
        string $password
    ): array {
        $greeting = $name !== '' ? 'Hello ' . $name . ',' : 'Hello,';
        $subject = 'Your course access — World Watercolor Masters';

        $text = implode("\n", [
            $greeting,
            '',
            'Thank you for your purchase!',
            '',
            'Your full access to "' . $courseTitle . '" is ready.',
            '',
            'One-click sign-in:',
            $magicLoginUrl,
            '',
            'Sign in with email and password filled in:',
            $prefilledLoginUrl,
            '',
            'Manual sign-in:',
            wwm_base_url() . '/login',
            'Login: ' . $email,
            'Password: ' . $password,
            '',
            'Questions? support@worldwatercolormasters.art',
            '',
            'Happy painting!',
            'World Watercolor Masters',
        ]);

        $html = self::layout(
            'Your full course access',
            self::subtitle($courseTitle),
            $courseCoverUrl,
            implode('', [
                self::paragraph($greeting),
                self::paragraph(
                    'Thank you for your purchase! Your full access to <strong>'
                    . self::e($courseTitle)
                    . '</strong> is ready.'
                ),
                self::button($magicLoginUrl, 'Start watching'),
                self::spacer(8),
                self::paragraph(
                    'Or sign in with your email and password prefilled:',
                    '14px',
                    '#6e6e6e'
                ),
                self::button($prefilledLoginUrl, 'Sign in with saved password'),
                self::credentialsBox($email, $password),
                self::courseLink($coursePageUrl),
                self::supportBlock(),
            ])
        );

        return compact('subject', 'text', 'html');
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    public static function demoAccess(
        string $name,
        string $email,
        string $courseTitle,
        ?string $courseCoverUrl,
        ?string $coursePageUrl,
        string $loginUrl,
        string $expiresLabel,
        ?string $password = null
    ): array {
        $greeting = $name !== '' ? 'Hello ' . $name . ',' : 'Hello,';
        $subject = 'Your demo access — World Watercolor Masters';

        $text = implode("\n", [
            $greeting,
            '',
            'Your demo access to "' . $courseTitle . '" is ready.',
            '',
            'Open this link — your email and password will be filled in automatically:',
            $loginUrl,
            '',
            'You can also sign in manually at:',
            wwm_base_url() . '/login',
            '',
            'Demo access expires: ' . $expiresLabel,
            '',
            'World Watercolor Masters',
        ]);

        $html = self::layout(
            'Your demo access',
            self::subtitle($courseTitle),
            $courseCoverUrl,
            implode('', [
                self::paragraph($greeting),
                self::paragraph(
                    'Your demo access to <strong>' . self::e($courseTitle) . '</strong> is ready.'
                ),
                self::paragraph('Demo access is active for <strong>' . self::e($expiresLabel) . '</strong>.'),
                self::button($loginUrl, 'Watch the demo lesson'),
                self::credentialsBox(
                    $email,
                    $password ?? self::demoPasswordLabel(),
                    wwm_base_url() . '/login',
                    false
                ),
                self::courseLink($coursePageUrl),
                self::supportBlock(),
            ])
        );

        return compact('subject', 'text', 'html');
    }

    private static function demoPasswordLabel(): string
    {
        $password = trim((string)(wwm_config()['demo_default_password'] ?? ''));
        return $password !== '' ? $password : '(use the sign-in link above)';
    }

    private static function layout(
        string $title,
        string $subtitleHtml,
        ?string $coverUrl,
        string $bodyHtml
    ): string {
        $coverBlock = self::coverImage($coverUrl);

        return '<!DOCTYPE html>'
            . '<html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . self::e($title) . '</title>'
            . '<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400;1,700&display=swap" rel="stylesheet">'
            . '<style>'
            . 'body{margin:0;padding:0;background:#faf6f0;}'
            . 'img{border:0;display:block;max-width:100%;height:auto;}'
            . '@media only screen and (max-width:640px){'
            . '.wrapper{width:100%!important;}'
            . '.pad{padding-left:24px!important;padding-right:24px!important;}'
            . '.wwm-logo{font-size:18px!important;}'
            . '.email-title{font-size:28px!important;}'
            . '.btn a{display:block!important;}'
            . '}'
            . '</style></head>'
            . '<body style="margin:0;padding:0;background:#faf6f0;font-family:Arial,Helvetica,sans-serif;color:#0a0a0a;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#faf6f0;">'
            . '<tr><td align="center" style="padding:32px 16px;">'
            . '<table role="presentation" class="wrapper" width="600" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 12px 40px rgba(10,10,10,0.08);">'
            . self::logoRow()
            . '<tr><td class="pad" style="padding:8px 40px 20px;background:#ffffff;">'
            . '<h1 class="email-title" style="margin:0;font-family:\'Playfair Display\',Georgia,\'Times New Roman\',serif;'
            . 'font-size:36px;line-height:1.2;font-weight:700;color:#1a110a;text-align:center;">'
            . self::e($title) . '</h1>'
            . $subtitleHtml
            . '</td></tr>'
            . $coverBlock
            . '<tr><td class="pad" style="padding:0 40px 24px;background:#ffffff;">' . $bodyHtml . '</td></tr>'
            . self::footerRow()
            . '</table></td></tr></table></body></html>';
    }

    private static function logoRow(): string
    {
        return '<tr><td class="pad" align="center" style="padding:28px 40px 4px;background:#ffffff;">'
            . '<a href="https://worldwatercolormasters.art" style="text-decoration:none;">'
            . '<span class="wwm-logo" style="display:inline-block;font-family:\'Playfair Display\',Georgia,\'Times New Roman\',serif;'
            . 'font-size:22px;line-height:1.15;letter-spacing:-0.01em;">'
            . '<span style="font-weight:900;color:#1a110a;">World Watercolor</span>'
            . '<span style="font-style:italic;font-weight:400;color:#c0440e;"> Masters</span>'
            . '</span></a>'
            . '<p style="margin:6px 0 0;font-size:12px;line-height:1.4;color:#6e6e6e;text-align:center;">'
            . 'by Bratec Lis School</p>'
            . '</td></tr>';
    }

    private static function footerRow(): string
    {
        return '<tr><td class="pad" style="padding:24px 40px 32px;background:#1a110a;border-radius:0 0 12px 12px;">'
            . '<p style="margin:0 0 8px;font-size:15px;line-height:1.5;color:#faf6f0;font-style:italic;">Happy painting!</p>'
            . '<p style="margin:0 0 4px;font-size:15px;line-height:1.5;color:#faf6f0;">World Watercolor Masters</p>'
            . '<p style="margin:0;font-size:14px;line-height:1.5;color:#c8bdb3;">'
            . '<a href="https://worldwatercolormasters.art" style="color:#faf6f0;text-decoration:underline;">'
            . 'worldwatercolormasters.art</a>'
            . ' · <a href="mailto:support@worldwatercolormasters.art" style="color:#faf6f0;text-decoration:underline;">'
            . 'support@worldwatercolormasters.art</a></p>'
            . '</td></tr>';
    }

    private static function subtitle(string $courseTitle): string
    {
        return '<p style="margin:12px 0 0;font-size:15px;line-height:1.5;color:#6e6e6e;text-align:center;">'
            . self::e($courseTitle) . '</p>';
    }

    private static function coverImage(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '' || !str_starts_with($url, 'https://')) {
            return '';
        }

        return '<tr><td class="pad" style="padding:0 40px 24px;background:#ffffff;">'
            . '<img src="' . self::e($url) . '" width="520" alt="" '
            . 'style="width:100%;border-radius:8px;border:1px solid #e5e5e5;"></td></tr>';
    }

    private static function paragraph(string $html, string $fontSize = '17px', string $color = '#2a2a2a'): string
    {
        return '<p style="margin:0 0 16px;font-size:' . $fontSize . ';line-height:1.6;color:' . $color . ';">'
            . $html . '</p>';
    }

    private static function spacer(int $px): string
    {
        return '<div style="height:' . $px . 'px;line-height:' . $px . 'px;font-size:0;">&nbsp;</div>';
    }

    private static function button(string $url, string $label): string
    {
        return '<table role="presentation" class="btn" cellpadding="0" cellspacing="0" border="0" align="center" '
            . 'style="margin:8px auto 0;border-radius:8px;background:#e63027;">'
            . '<tr><td align="center" style="border-radius:8px;background:#e63027;">'
            . '<a href="' . self::e($url) . '" target="_blank" '
            . 'style="display:inline-block;padding:16px 32px;font-size:17px;font-weight:700;color:#ffffff;'
            . 'text-decoration:none;font-family:Arial,Helvetica,sans-serif;">'
            . self::e($label) . '</a></td></tr></table>';
    }

    private static function linkBlock(string $url, string $label = 'Open link'): string
    {
        return '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;">'
            . '<a href="' . self::e($url) . '" target="_blank" style="color:#b81e16;text-decoration:underline;">'
            . self::e($label) . '</a></p>';
    }

    private static function credentialsBox(
        string $email,
        string $password,
        ?string $loginUrl = null,
        bool $showPasswordHint = true
    ): string {
        $loginUrl ??= wwm_base_url() . '/login';
        $hint = $showPasswordHint
            ? '<p style="margin:14px 0 0;font-size:14px;line-height:1.5;color:#6e6e6e;">'
                . 'You can change your password anytime under Account after signing in.</p>'
            : '';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="margin-top:8px;background:#faf6f0;border-radius:8px;border:1px solid #e5e5e5;">'
            . '<tr><td style="padding:20px 24px;">'
            . '<p style="margin:0 0 12px;font-size:15px;font-weight:700;color:#1a110a;text-transform:uppercase;'
            . 'letter-spacing:0.04em;">Sign-in details</p>'
            . '<p style="margin:0 0 8px;font-size:16px;line-height:1.5;color:#2a2a2a;">'
            . '<strong>Cabinet:</strong> <a href="' . self::e($loginUrl) . '" target="_blank" style="color:#b81e16;text-decoration:underline;">'
            . 'Sign in to your cabinet</a></p>'
            . '<p style="margin:0 0 8px;font-size:16px;line-height:1.5;color:#2a2a2a;">'
            . '<strong>Email:</strong> ' . self::e($email) . '</p>'
            . '<p style="margin:0;font-size:16px;line-height:1.5;color:#2a2a2a;">'
            . '<strong>Password:</strong> ' . self::e($password) . '</p>'
            . $hint
            . '</td></tr></table>';
    }

    private static function courseLink(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '' || !str_starts_with($url, 'https://')) {
            return '';
        }

        return self::spacer(8)
            . '<p style="margin:0 0 12px;font-size:16px;line-height:1.5;">'
            . '<a href="' . self::e($url) . '" style="color:#b81e16;text-decoration:none;">'
            . 'Course description on the website →</a></p>';
    }

    private static function supportBlock(): string
    {
        return '<p style="margin:0;font-size:16px;line-height:1.5;color:#2a2a2a;">'
            . 'Questions? Email us at '
            . '<a href="mailto:support@worldwatercolormasters.art" style="color:#b81e16;text-decoration:none;">'
            . 'support@worldwatercolormasters.art</a></p>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
