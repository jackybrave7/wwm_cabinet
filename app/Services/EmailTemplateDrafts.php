<?php
declare(strict_types=1);

namespace Wwm\Services;

final class EmailTemplateDrafts
{
    /**
     * @return array{subject: string, text: string, html: ?string}
     */
    public static function draft(string $id): array
    {
        return match ($id) {
            'demo' => [
                'subject' => '{{name}}, your free demo is ready — start {{course_title}} now',
                'text' => implode("\n", [
                    'Hello {{name}},',
                    '',
                    'Your demo access to "{{course_title}}" is ready.',
                    '',
                    'Open this link — your email and password will be filled in automatically:',
                    '{{login_url}}',
                    '',
                    'You can also sign in manually at:',
                    '{{base_url}}/login',
                    '',
                    'Demo access expires: {{expires_label}}',
                    '',
                    'World Watercolor Masters',
                ]),
                'html' => self::layoutDraft(
                    'Your demo access',
                    implode('', [
                        self::paragraph('Hello {{name}},'),
                        self::paragraph('Your demo access to <span style="font-weight:700;">{{course_title}}</span> is ready.'),
                        self::paragraph('Demo access is active for <strong>{{expires_label}}</strong>.'),
                        self::button('{{login_url}}', 'Watch the demo lesson'),
                        self::credentialsBoxDraft(false),
                        self::courseLinkDraft(),
                        self::supportBlock(),
                    ])
                ),
            ],
            'paid' => [
                'subject' => 'Your course access — World Watercolor Masters',
                'text' => implode("\n", [
                    'Hello {{name}},',
                    '',
                    'Thank you for your purchase!',
                    '',
                    'Your full access to "{{course_title}}" is ready.',
                    '',
                    'Sign in to your cabinet (email and password are prefilled):',
                    '{{login_url}}',
                    '',
                    'Manual sign-in:',
                    '{{base_url}}/login',
                    'Login: {{email}}',
                    'Password: {{password}}',
                    '',
                    'Questions? support@worldwatercolormasters.art',
                    '',
                    'Happy painting!',
                    'World Watercolor Masters',
                ]),
                'html' => self::layoutDraft(
                    'Your full course access',
                    implode('', [
                        self::paragraph('Hello {{name}},'),
                        self::paragraph(
                            'Thank you for your purchase! Your full access to <span style="font-weight:700;">{{course_title}}</span> is ready.'
                        ),
                        self::button('{{login_url}}', 'Sign in with saved password'),
                        self::credentialsBoxDraft(true),
                        self::courseLinkDraft(),
                        self::supportBlock(),
                    ])
                ),
            ],
            'reminder_demo_no_login' => [
                'subject' => 'Your demo is waiting — World Watercolor Masters',
                'text' => implode("\n", [
                    'Hello {{name}},',
                    '',
                    'You requested demo access to "{{course_title}}", but we have not seen you in the cabinet yet.',
                    '',
                    'Sign in to watch your free demo lesson while access is still active:',
                    '{{login_url}}',
                    '',
                    'Manual sign-in:',
                    '{{base_url}}/login',
                    'Login: {{email}}',
                    'Password: {{password}}',
                    '',
                    'Questions? support@worldwatercolormasters.art',
                    '',
                    'World Watercolor Masters',
                ]),
                'html' => self::layoutDraft(
                    'Your demo is waiting',
                    implode('', [
                        self::paragraph('Hello {{name}},'),
                        self::paragraph(
                            'You requested demo access to <span style="font-weight:700;">{{course_title}}</span>, '
                            . 'but we have not seen you in the cabinet yet.'
                        ),
                        self::paragraph(
                            'Your demo is still active — sign in and watch the first lesson while it is available.'
                        ),
                        self::button('{{login_url}}', 'Open my demo lesson'),
                        self::credentialsBoxDraft(false),
                        self::courseLinkDraft(),
                        self::supportBlock(),
                    ])
                ),
            ],
            'reminder_demo_no_lesson' => [
                'subject' => 'Your demo is waiting — {{course_title}}',
                'text' => implode("\n", [
                    'Hello {{name}},',
                    '',
                    'You requested demo access to "{{course_title}}", but it looks like you have not opened the lesson yet.',
                    '',
                    'Your demo is still active — take a few minutes to watch the first lesson:',
                    '{{login_url}}',
                    '',
                    'Manual sign-in:',
                    '{{base_url}}/login',
                    'Login: {{email}}',
                    'Password: {{password}}',
                    '',
                    'Questions? support@worldwatercolormasters.art',
                    '',
                    'World Watercolor Masters',
                ]),
                'html' => self::layoutDraft(
                    'Your demo lesson<br>is still waiting',
                    implode('', [
                        self::paragraph('Hello {{name}},'),
                        self::paragraph(
                            'You requested demo access to <span style="font-weight:700;">{{course_title}}</span>, '
                            . 'but it looks like you have not opened the lesson yet.'
                        ),
                        self::paragraph(
                            'Your demo is still active — take a few minutes to watch the first lesson while it is available.'
                        ),
                        self::button('{{login_url}}', 'Open my demo lesson'),
                        self::credentialsBoxDraft(false),
                        self::courseLinkDraft(),
                        self::supportBlock(),
                    ])
                ),
            ],
            'reminder_demo_expiring' => [
                'subject' => 'Still have not watched? Your demo expires soon',
                'text' => implode("\n", [
                    'Hello {{name}},',
                    '',
                    'This is a friendly reminder: your demo access to "{{course_title}}" will not last much longer.',
                    '',
                    'Demo access expires: {{expires_label}}',
                    '',
                    'Watch now before your access runs out:',
                    '{{login_url}}',
                    '',
                    'Manual sign-in:',
                    '{{base_url}}/login',
                    'Login: {{email}}',
                    'Password: {{password}}',
                    '',
                    'Questions? support@worldwatercolormasters.art',
                    '',
                    'World Watercolor Masters',
                ]),
                'html' => self::layoutDraft(
                    "Don't miss your<br>free demo lesson",
                    implode('', [
                        self::paragraph('Hello {{name}},'),
                        self::paragraph(
                            'This is a friendly reminder: your demo access to <span style="font-weight:700;">{{course_title}}</span> '
                            . 'will not last much longer.'
                        ),
                        self::paragraph(
                            'Watch the full demo lesson before your access expires on <strong>{{expires_label}}</strong>.'
                        ),
                        self::button('{{login_url}}', 'Watch before it expires'),
                        self::credentialsBoxDraft(false),
                        self::courseLinkDraft(),
                        self::supportBlock(),
                    ])
                ),
            ],
            'sale_demo_discount_24h' => [
                'subject' => '{{name}}, your exclusive 40% off ({{coupon_code}}) expires in 24 hours',
                'text' => implode("\n", [
                    'Hi {{name}},',
                    '',
                    'We hope you enjoyed the demo lesson from "{{course_title}}".',
                    '',
                    'We have reserved a 40% discount on full course access — just for you.',
                    '',
                    'Your coupon code: {{coupon_code}}',
                    'This offer expires in 24 hours.',
                    '',
                    'Get full access now:',
                    '{{buy_url}}',
                    '',
                    'Questions? support@worldwatercolormasters.art',
                    '',
                    'Happy painting!',
                    'World Watercolor Masters',
                ]),
                'html' => self::layoutDraft(
                    'Save 40% on<br>full course access',
                    implode('', [
                        self::paragraph('Hi {{name}},'),
                        self::paragraph(
                            'We hope you enjoyed the demo lesson from <span style="font-weight:700;">{{course_title}}</span>.'
                        ),
                        self::paragraph(
                            'We have reserved an exclusive <strong>40% discount</strong> on full course access — just for you.'
                        ),
                        self::couponBoxDraft('Expires in 24 hours'),
                        self::button('{{buy_url}}', 'Get Full Access Now & Save 40%'),
                        self::supportBlock(),
                    ])
                ),
            ],
            'sale_demo_discount_3h' => [
                'subject' => '{{name}}, final call: 40% off ({{coupon_code}}) — only 3 hours left',
                'text' => implode("\n", [
                    'Hi {{name}},',
                    '',
                    'This is your final reminder: your 40% discount on "{{course_title}}" expires in 3 hours.',
                    '',
                    'Your coupon code: {{coupon_code}}',
                    '',
                    'If you do not act now, you will miss:',
                    '- Full video course access with all lessons',
                    '- Step-by-step guidance from Elke Memmler',
                    '- Your reserved 40% discount',
                    '- Lifetime access to course materials',
                    '',
                    'Get instant access now:',
                    '{{buy_url}}',
                    '',
                    'Questions? support@worldwatercolormasters.art',
                    '',
                    'World Watercolor Masters',
                ]),
                'html' => self::layoutDraft(
                    'Only 3 hours left',
                    implode('', [
                        self::paragraph('Hi {{name}},'),
                        self::paragraph(
                            'This is your <strong>final reminder</strong>: your 40% discount on '
                            . '<span style="font-weight:700;">{{course_title}}</span> expires in <strong>3 hours</strong>.'
                        ),
                        self::couponBoxDraft('Use this code before time runs out'),
                        self::bulletList([
                            'Full video course access with all lessons',
                            'Step-by-step guidance from Elke Memmler',
                            'Your reserved 40% discount',
                            'Lifetime access to course materials',
                        ]),
                        self::button('{{buy_url}}', 'Get Instant Access & Save 40%'),
                        self::supportBlock(),
                    ])
                ),
            ],
            'sale_crosssell_50_offer' => [
                'subject' => '{{name}}, 50% off {{course_title}} — code {{coupon_code}} (48 hours)',
                'text' => implode("\n", [
                    'Hi {{name}},',
                    '',
                    'You might enjoy another course from our masters: "{{course_title}}".',
                    '',
                    'For the next 48 hours you have 50% off full access.',
                    '',
                    'Your coupon code: {{coupon_code}}',
                    '',
                    'Get the course:',
                    '{{buy_url}}',
                    '',
                    'Questions? support@worldwatercolormasters.art',
                    '',
                    'Unsubscribe from marketing emails: {{unsubscribe_url}}',
                    '',
                    'World Watercolor Masters',
                ]),
                'html' => self::layoutDraft(
                    '50% off for 48 hours',
                    implode('', [
                        self::paragraph('Hi {{name}},'),
                        self::paragraph(
                            'Discover <span style="font-weight:700;">{{course_title}}</span> — '
                            . 'a full video course from World Watercolor Masters.'
                        ),
                        self::paragraph(
                            'For the next <strong>48 hours</strong> you have an exclusive <strong>50% discount</strong> on full access.'
                        ),
                        self::couponBoxDraft('Valid 48 hours — use at checkout'),
                        self::button('{{buy_url}}', 'Get the course — save 50%'),
                        self::supportBlock(),
                        self::paragraph(
                            '<span style="font-size:12px;color:#666;">'
                            . '<a href="{{unsubscribe_url}}">Unsubscribe</a> from marketing emails.</span>'
                        ),
                    ])
                ),
            ],
            'sale_crosssell_50_reminder' => [
                'subject' => '{{name}}, last hours: 50% off {{course_title}} ({{coupon_code}})',
                'text' => implode("\n", [
                    'Hi {{name}},',
                    '',
                    'Your 50% discount on "{{course_title}}" ends in about 5 hours.',
                    '',
                    'Coupon code: {{coupon_code}}',
                    '',
                    '{{buy_url}}',
                    '',
                    'Unsubscribe: {{unsubscribe_url}}',
                    '',
                    'World Watercolor Masters',
                ]),
                'html' => self::layoutDraft(
                    '5 hours left on your 50% off',
                    implode('', [
                        self::paragraph('Hi {{name}},'),
                        self::paragraph(
                            'Quick reminder: your <strong>50% discount</strong> on '
                            . '<span style="font-weight:700;">{{course_title}}</span> '
                            . 'expires in about <strong>5 hours</strong>.'
                        ),
                        self::couponBoxDraft('Code expires soon'),
                        self::button('{{buy_url}}', 'Use {{coupon_code}} now'),
                        self::supportBlock(),
                        self::paragraph(
                            '<span style="font-size:12px;color:#666;">'
                            . '<a href="{{unsubscribe_url}}">Unsubscribe</a> from marketing emails.</span>'
                        ),
                    ])
                ),
            ],
            'magic' => [
                'subject' => 'Your sign-in link — World Watercolor Masters',
                'text' => implode("\n", [
                    'Hello {{name}},',
                    '',
                    'Open this link to sign in to your account:',
                    '{{magic_link}}',
                    '',
                    'The link is single-use and expires in {{magic_link_hours}} hours.',
                    '',
                    'If you did not request this, you can ignore this email.',
                    '',
                    'World Watercolor Masters',
                ]),
                'html' => null,
            ],
            'reset' => [
                'subject' => 'Reset your WWM password',
                'text' => "Open this link to set a new password (valid 1 hour):\n\n{{reset_link}}\n",
                'html' => null,
            ],
            'login_credentials' => [
                'subject' => 'Your new WWM sign-in details',
                'text' => implode("\n", [
                    'Hello {{name}},',
                    '',
                    'A World Watercolor Masters administrator has reset your cabinet password — most likely because you asked for help signing in.',
                    'Your new sign-in details are below.',
                    '',
                    'Sign in (your email may be prefilled):',
                    '{{login_url}}',
                    '',
                    'Manual sign-in: {{base_url}}/login',
                    'Email: {{email}}',
                    '{{password_line}}',
                    '',
                    'Forgot or want to change your password?',
                    '- Not signed in: request a reset at {{forgot_url}}',
                    '- Step-by-step guide (screenshots): {{recover_help_url}}',
                    '- Already signed in: open Account → {{account_url}}',
                    '',
                    'Questions? support@worldwatercolormasters.art',
                    '',
                    'World Watercolor Masters',
                ]),
                'html' => self::adminLayoutDraft(
                    'Your sign-in details',
                    implode('', [
                        self::paragraph('Hello {{name}},'),
                        self::paragraph(
                            'A <strong>World Watercolor Masters</strong> administrator has reset your cabinet password '
                            . '— most likely because you asked for help signing in. '
                            . 'Use the <strong>new</strong> details below to sign in at '
                            . '<strong>my.worldwatercolormasters.art</strong>.'
                        ),
                        self::button('{{login_url}}', 'Sign in to your cabinet'),
                        self::studentLoginCredentialsBoxDraft(),
                        self::studentPasswordHelpDraft(),
                        self::supportBlock(),
                    ])
                ),
            ],
            'admin_invite' => [
                'subject' => 'Your administrator access — World Watercolor Masters',
                'text' => implode("\n", [
                    'Hello {{name}},',
                    '',
                    'You have been granted administrator access to the WWM student cabinet.',
                    '',
                    'Permissions: {{permissions_list}}',
                    '',
                    'Open the admin panel (sign-in may be prefilled):',
                    '{{login_url}}',
                    '',
                    'Manual sign-in: {{base_url}}/login',
                    'Email: {{email}}',
                    '{{password_line}}',
                    '',
                    'World Watercolor Masters',
                ]),
                'html' => self::adminLayoutDraft(
                    'Administrator access',
                    implode('', [
                        self::paragraph('Hello {{name}},'),
                        self::paragraph(
                            'You have been granted <strong>administrator access</strong> to the WWM cabinet.'
                        ),
                        self::paragraph('<strong>Permissions:</strong> {{permissions_list}}'),
                        self::button('{{login_url}}', 'Open admin panel'),
                        self::adminCredentialsBoxDraft(),
                        self::supportBlock(),
                    ])
                ),
            ],
            default => throw new \InvalidArgumentException('Unknown email template: ' . $id),
        };
    }

    /**
     * @param array<string, string> $vars
     */
    public static function finalizeHtml(?string $html, array $vars): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        if (trim((string)($vars['cover_url'] ?? '')) === '') {
            $html = (string)preg_replace('/<!-- cover:start -->.*?<!-- cover:end -->/s', '', $html);
        }
        if (trim((string)($vars['course_page_url'] ?? '')) === '') {
            $html = (string)preg_replace('/<!-- course-link:start -->.*?<!-- course-link:end -->/s', '', $html);
        }

        return $html;
    }

    private static function adminLayoutDraft(string $titleHtml, string $bodyHtml): string
    {
        $plainTitle = strip_tags(str_replace('<br>', ' ', $titleHtml));

        return '<!DOCTYPE html>'
            . '<html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $plainTitle . '</title>'
            . wwm_email_font_link_tag()
            . wwm_email_head_styles()
            . '</head>'
            . '<body style="margin:0;padding:0;background:#faf6f0;font-family:Arial,Helvetica,sans-serif;color:#0a0a0a;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#faf6f0;">'
            . '<tr><td align="center" style="padding:32px 16px;">'
            . '<table role="presentation" class="wrapper" width="600" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 12px 40px rgba(10,10,10,0.08);">'
            . self::logoRowDraft()
            . '<tr><td class="pad" style="padding:8px 40px 20px;background:#ffffff;">'
            . '<h1 class="email-title" style="' . wwm_email_title_inline_style() . '">'
            . $titleHtml . '</h1>'
            . '</td></tr>'
            . '<tr><td class="pad" style="padding:0 40px 24px;background:#ffffff;">' . $bodyHtml . '</td></tr>'
            . self::footerRow()
            . '</table></td></tr></table></body></html>';
    }

    private static function studentLoginCredentialsBoxDraft(): string
    {
        return '<!-- credentials:start -->'
            . '<div style="margin-top:8px;padding:20px 24px;background:#faf6f0;border-radius:8px;border:1px solid #e5e5e5;">'
            . '<p style="margin:0 0 12px;font-size:15px;font-weight:700;color:#1a110a;text-transform:uppercase;'
            . 'letter-spacing:0.04em;">Sign-in details</p>'
            . '<p style="margin:0 0 8px;font-size:16px;line-height:1.5;color:#2a2a2a;">'
            . '<strong>Email:</strong> {{email}}</p>'
            . '<p style="margin:0;font-size:16px;line-height:1.5;color:#2a2a2a;">{{password_line}}</p>'
            . '</div><!-- credentials:end -->';
    }

    private static function studentPasswordHelpDraft(): string
    {
        return '<div style="margin:20px 0 0;padding:16px 20px;background:#fff8f6;border-radius:8px;border:1px solid #f0d8d3;">'
            . '<p style="margin:0 0 10px;font-size:15px;font-weight:700;color:#1a110a;">Forgot or want to change your password?</p>'
            . '<p style="margin:0 0 8px;font-size:15px;line-height:1.55;color:#2a2a2a;">'
            . '<strong>Not signed in:</strong> request a reset at '
            . '<a href="{{forgot_url}}" style="color:#b81e16;text-decoration:underline;">{{forgot_url}}</a>. '
            . 'See our <a href="{{recover_help_url}}" style="color:#b81e16;text-decoration:underline;">step-by-step guide</a> '
            . 'with screenshots (spam folder, expired links, and more).</p>'
            . '<p style="margin:0;font-size:15px;line-height:1.55;color:#2a2a2a;">'
            . '<strong>Already signed in:</strong> open '
            . '<a href="{{account_url}}" style="color:#b81e16;text-decoration:underline;">Account</a> '
            . 'in the top menu and update your password anytime.</p>'
            . '</div>';
    }

    private static function adminCredentialsBoxDraft(): string
    {
        return '<!-- credentials:start -->'
            . '<div style="margin-top:8px;padding:20px 24px;background:#faf6f0;border-radius:8px;border:1px solid #e5e5e5;">'
            . '<p style="margin:0 0 12px;font-size:15px;font-weight:700;color:#1a110a;text-transform:uppercase;'
            . 'letter-spacing:0.04em;">Sign-in details</p>'
            . '<p style="margin:0 0 8px;font-size:16px;line-height:1.5;color:#2a2a2a;">'
            . '<strong>Email:</strong> {{email}}</p>'
            . '<p style="margin:0 0 8px;font-size:16px;line-height:1.5;color:#2a2a2a;">{{password_line}}</p>'
            . '<p style="margin:14px 0 0;font-size:14px;line-height:1.5;color:#6e6e6e;">'
            . 'Forgot your password? <a href="{{forgot_url}}" style="color:#b81e16;text-decoration:underline;">Reset it here</a>.</p>'
            . '</div><!-- credentials:end -->';
    }

    private static function layoutDraft(string $titleHtml, string $bodyHtml): string
    {
        $plainTitle = strip_tags(str_replace('<br>', ' ', $titleHtml));

        return '<!DOCTYPE html>'
            . '<html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $plainTitle . '</title>'
            . wwm_email_font_link_tag()
            . wwm_email_head_styles()
            . '</head>'
            . '<body style="margin:0;padding:0;background:#faf6f0;font-family:Arial,Helvetica,sans-serif;color:#0a0a0a;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#faf6f0;">'
            . '<tr><td align="center" style="padding:32px 16px;">'
            . '<table role="presentation" class="wrapper" width="600" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 12px 40px rgba(10,10,10,0.08);">'
            . self::logoRowDraft()
            . '<tr><td class="pad" style="padding:8px 40px 20px;background:#ffffff;">'
            . '<h1 class="email-title" style="' . wwm_email_title_inline_style() . '">'
            . $titleHtml . '</h1>'
            . self::subtitleDraft()
            . '</td></tr>'
            . self::coverImageDraft()
            . '<tr><td class="pad" style="padding:0 40px 24px;background:#ffffff;">' . $bodyHtml . '</td></tr>'
            . self::footerRow()
            . '</table></td></tr></table></body></html>';
    }

    private static function logoRowDraft(): string
    {
        return wwm_email_logo_row_html();
    }

    private static function subtitleDraft(): string
    {
        return '<p style="margin:12px 0 0;font-size:15px;line-height:1.5;color:#6e6e6e;text-align:center;">'
            . '{{course_title}}</p>';
    }

    private static function coverImageDraft(): string
    {
        return '<!-- cover:start --><tr><td class="pad" style="padding:0 40px 24px;background:#ffffff;">'
            . '<img src="{{cover_url}}" width="520" alt="Course cover" '
            . 'style="width:100%;border-radius:8px;border:1px solid #e5e5e5;"></td></tr><!-- cover:end -->';
    }

    private static function credentialsBoxDraft(bool $showPasswordHint): string
    {
        $hint = $showPasswordHint
            ? '<p style="margin:14px 0 0;font-size:14px;line-height:1.5;color:#6e6e6e;">'
                . 'You can change your password anytime under Account after signing in.</p>'
            : '';

        return '<!-- credentials:start -->'
            . '<div style="margin-top:8px;padding:20px 24px;background:#faf6f0;border-radius:8px;border:1px solid #e5e5e5;">'
            . '<p style="margin:0 0 12px;font-size:15px;font-weight:700;color:#1a110a;text-transform:uppercase;'
            . 'letter-spacing:0.04em;">Sign-in details</p>'
            . '<p style="margin:0 0 8px;font-size:16px;line-height:1.5;color:#2a2a2a;">'
            . '<strong>Cabinet:</strong> <a href="{{base_url}}/login" target="_blank" style="color:#b81e16;text-decoration:underline;">'
            . 'Sign in to your cabinet</a></p>'
            . '<p style="margin:0 0 8px;font-size:16px;line-height:1.5;color:#2a2a2a;">'
            . '<strong>Email:</strong> {{email}}</p>'
            . '<p style="margin:0;font-size:16px;line-height:1.5;color:#2a2a2a;">'
            . '<strong>Password:</strong> {{password}}</p>'
            . $hint
            . '</div><!-- credentials:end -->';
    }

    private static function courseLinkDraft(): string
    {
        return '<!-- course-link:start -->'
            . self::spacer(8)
            . '<p style="margin:0 0 12px;font-size:16px;line-height:1.5;">'
            . '<a href="{{course_page_url}}" style="color:#b81e16;text-decoration:none;">'
            . 'Course description on the website →</a></p>'
            . '<!-- course-link:end -->';
    }

    private static function couponBoxDraft(string $note): string
    {
        return '<!-- coupon:start -->'
            . '<div style="margin:8px 0 20px;padding:20px 24px;text-align:center;background:#faf6f0;border-radius:8px;border:1px solid #e5e5e5;">'
            . '<p style="margin:0 0 8px;font-size:14px;line-height:1.4;color:#6e6e6e;text-transform:uppercase;'
            . 'letter-spacing:0.06em;">Your coupon code</p>'
            . '<p style="margin:0 0 8px;font-size:28px;line-height:1.2;font-weight:700;color:#e63027;'
            . 'letter-spacing:0.08em;">{{coupon_code}}</p>'
            . '<p style="margin:0;font-size:15px;line-height:1.5;color:#2a2a2a;">' . self::e($note) . '</p>'
            . '</div><!-- coupon:end -->';
    }

    /**
     * @param list<string> $items
     */
    private static function bulletList(array $items): string
    {
        $html = '<!-- benefits:start -->'
            . '<div style="margin:0 0 20px;padding:16px 20px;background:#fff8f6;border-radius:8px;border:1px solid #f0d8d3;">'
            . '<p style="margin:0 0 12px;font-size:15px;font-weight:700;color:#1a110a;">'
            . 'If you do not act now, you will miss:</p>';

        foreach ($items as $item) {
            $html .= '<p style="margin:0 0 10px;font-size:16px;line-height:1.5;color:#2a2a2a;">'
                . '<span style="color:#e63027;font-weight:700;padding-right:8px;">•</span>'
                . self::e($item) . '</p>';
        }

        return $html . '</div><!-- benefits:end -->';
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

    private static function paragraph(string $html): string
    {
        return '<p style="margin:0 0 16px;font-size:17px;line-height:1.6;color:#2a2a2a;">' . $html . '</p>';
    }

    private static function spacer(int $px): string
    {
        return '<div style="height:' . $px . 'px;line-height:' . $px . 'px;font-size:0;">&nbsp;</div>';
    }

    private static function button(string $url, string $label): string
    {
        return wwm_email_button_html($url, $label);
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
