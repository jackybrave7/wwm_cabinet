<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\LoginLink;

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
            'Sign in to your cabinet (email and password are prefilled):',
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
                self::button($prefilledLoginUrl, 'Sign in with saved password'),
                self::credentialsBox($email, $password, $prefilledLoginUrl),
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

    /**
     * @return array{subject: string, text: string, html: string}
     */
    public static function reminderDemoNoLogin(
        string $name,
        string $email,
        string $courseTitle,
        ?string $courseCoverUrl,
        ?string $coursePageUrl,
        string $loginUrl,
        ?string $password = null
    ): array {
        $greeting = $name !== '' ? 'Hello ' . $name . ',' : 'Hello,';
        $subject = 'Your demo is waiting — World Watercolor Masters';

        $text = implode("\n", [
            $greeting,
            '',
            'You requested demo access to "' . $courseTitle . '", but we have not seen you in the cabinet yet.',
            '',
            'Sign in to watch your free demo lesson while access is still active:',
            $loginUrl,
            '',
            'Manual sign-in:',
            wwm_base_url() . '/login',
            'Login: ' . $email,
            $password !== null && $password !== '' ? 'Password: ' . $password : '',
            '',
            'Questions? support@worldwatercolormasters.art',
            '',
            'World Watercolor Masters',
        ]);

        $html = self::demoReminderLayout(
            'Your demo is waiting',
            $courseTitle,
            $courseCoverUrl,
            implode('', [
                self::paragraph($greeting),
                self::paragraph(
                    'You requested demo access to <strong>' . self::e($courseTitle) . '</strong>, '
                    . 'but we have not seen you in the cabinet yet.'
                ),
                self::paragraph(
                    'Your demo is still active — sign in and watch the first lesson while it is available.'
                ),
                self::button($loginUrl, 'Open my demo lesson'),
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

    /**
     * @return array{subject: string, text: string, html: string}
     */
    public static function reminderDemoNoLesson(
        string $name,
        string $email,
        string $courseTitle,
        ?string $courseCoverUrl,
        ?string $coursePageUrl,
        string $loginUrl,
        ?string $password = null
    ): array {
        $greeting = $name !== '' ? 'Hello ' . $name . ',' : 'Hello,';
        $subject = 'Your demo is waiting — ' . $courseTitle;

        $text = implode("\n", [
            $greeting,
            '',
            'You requested demo access to "' . $courseTitle . '", but it looks like you have not opened the lesson yet.',
            '',
            'Your demo is still active — take a few minutes to watch the first lesson:',
            $loginUrl,
            '',
            'Manual sign-in:',
            wwm_base_url() . '/login',
            'Login: ' . $email,
            $password !== null && $password !== '' ? 'Password: ' . $password : '',
            '',
            'Questions? support@worldwatercolormasters.art',
            '',
            'World Watercolor Masters',
        ]);

        $html = self::demoReminderLayout(
            "Your demo lesson\nis still waiting",
            $courseTitle,
            $courseCoverUrl,
            implode('', [
                self::paragraph($greeting),
                self::paragraph(
                    'You requested demo access to <strong>' . self::e($courseTitle) . '</strong>, '
                    . 'but it looks like you have not opened the lesson yet.'
                ),
                self::paragraph(
                    'Your demo is still active — take a few minutes to watch the first lesson while it is available.'
                ),
                self::button($loginUrl, 'Open my demo lesson'),
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

    /**
     * @return array{subject: string, text: string, html: string}
     */
    public static function reminderDemoExpiring(
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
        $subject = 'Still have not watched? Your demo expires soon';

        $text = implode("\n", [
            $greeting,
            '',
            'This is a friendly reminder: your demo access to "' . $courseTitle . '" will not last much longer.',
            '',
            'Demo access expires: ' . $expiresLabel,
            '',
            'Watch now before your access runs out:',
            $loginUrl,
            '',
            'Manual sign-in:',
            wwm_base_url() . '/login',
            'Login: ' . $email,
            $password !== null && $password !== '' ? 'Password: ' . $password : '',
            '',
            'Questions? support@worldwatercolormasters.art',
            '',
            'World Watercolor Masters',
        ]);

        $html = self::demoReminderLayout(
            "Don't miss your\nfree demo lesson",
            $courseTitle,
            $courseCoverUrl,
            implode('', [
                self::paragraph($greeting),
                self::paragraph(
                    'This is a friendly reminder: your demo access to <strong>'
                    . self::e($courseTitle)
                    . '</strong> will not last much longer.'
                ),
                self::paragraph(
                    'Watch the full demo lesson before your access expires on <strong>'
                    . self::e($expiresLabel)
                    . '</strong>.'
                ),
                self::button($loginUrl, 'Watch before it expires'),
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

    /**
     * @return array{subject: string, text: string, html: null}
     */
    public static function magicLinkPreview(?string $name = null): array
    {
        $loginUrl = wwm_base_url() . '/auth/magic?token=sample-token-for-preview';

        return self::magicLinkMessage($name ?? '', $loginUrl);
    }

    /**
     * @return array{subject: string, text: string, html: null}
     */
    public static function magicLinkMessage(string $name, string $loginUrl): array
    {
        $greeting = $name !== '' ? 'Hello ' . $name . ',' : 'Hello,';
        $hours = (int)(LoginLink::ttlSeconds() / 3600);

        return [
            'subject' => 'Your sign-in link — World Watercolor Masters',
            'text' => implode("\n", [
                $greeting,
                '',
                'Open this link to sign in to your account:',
                $loginUrl,
                '',
                'The link is single-use and expires in ' . $hours . ' hours.',
                '',
                'If you did not request this, you can ignore this email.',
                '',
                'World Watercolor Masters',
            ]),
            'html' => null,
        ];
    }

    /**
     * @return array{subject: string, text: string, html: null}
     */
    public static function passwordResetPreview(): array
    {
        return self::passwordResetMessage(wwm_base_url() . '/reset?token=sample-token-for-preview');
    }

    /**
     * @return array{subject: string, text: string, html: null}
     */
    public static function passwordResetMessage(string $link): array
    {
        return [
            'subject' => 'Reset your WWM password',
            'text' => "Open this link to set a new password (valid 1 hour):\n\n" . $link . "\n",
            'html' => null,
        ];
    }

    private static function demoReminderLayout(
        string $title,
        string $courseTitle,
        ?string $coverUrl,
        string $bodyHtml
    ): string {
        return self::layout(
            str_replace("\n", ' ', $title),
            self::subtitle($courseTitle),
            $coverUrl,
            $bodyHtml,
            str_replace("\n", '<br>', self::e($title))
        );
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
        string $bodyHtml,
        ?string $titleHtml = null
    ): string {
        $coverBlock = self::coverImage($coverUrl);
        $titleBlock = '<h1 class="email-title" style="margin:0;font-family:\'Playfair Display\',Georgia,\'Times New Roman\',serif;'
            . 'font-size:36px;line-height:1.2;font-weight:700;color:#1a110a;text-align:center;">'
            . ($titleHtml ?? self::e($title)) . '</h1>';

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
            . $titleBlock
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
