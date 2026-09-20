<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\EmailAutomation;

final class EmailAutomationNodeCatalog
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function courseOptionsForEditor(): array
    {
        $out = [];
        foreach ((new CourseCatalog())->all() as $course) {
            $slug = trim((string)($course['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $title = trim((string)($course['title'] ?? $slug));
            $out[] = [
                'value' => $slug,
                'label' => $title !== '' ? $title . ' (' . $slug . ')' : $slug,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function entryModeOptionsForEditor(): array
    {
        $labels = EmailAutomation::entryModeLabels();
        $out = [];
        foreach ($labels as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, label: string, short: string, description: string, outputs: int, class: string}>
     */
    public static function palette(): array
    {
        return [
            [
                'id' => 'trigger',
                'label' => 'Старт',
                'short' => 'Старт',
                'description' => 'Точка входа: кто попадает в цепочку и с каким курсом (настройки в свойствах блока).',
                'outputs' => 1,
                'class' => 'automation-node--trigger',
            ],
            [
                'id' => 'grant_demo',
                'label' => 'Выдать демо',
                'short' => 'Демо',
                'description' => 'Демо-доступ на срок demo_hours курса. Обычно демо уже выдано через /api/demo — блок можно пропустить.',
                'outputs' => 1,
                'class' => 'automation-node--grant',
            ],
            [
                'id' => 'delay',
                'label' => 'Пауза',
                'short' => 'Пауза',
                'description' => 'Ждать указанное время, затем перейти к следующему блоку. Cron обрабатывает очередь.',
                'outputs' => 1,
                'class' => 'automation-node--delay',
            ],
            [
                'id' => 'condition',
                'label' => 'Условие',
                'short' => 'Условие',
                'description' => 'Ветвление: верхний выход — «да», нижний — «нет».',
                'outputs' => 2,
                'class' => 'automation-node--condition',
            ],
            [
                'id' => 'send_template',
                'label' => 'Письмо',
                'short' => 'Письмо',
                'description' => 'Шаблон из раздела Emails. Cross-sell — с отпиской и List-Unsubscribe.',
                'outputs' => 1,
                'class' => 'automation-node--email',
            ],
            [
                'id' => 'notify_staff',
                'label' => 'Уведомить staff',
                'short' => 'Staff',
                'description' => 'Запись в лог + письмо выбранным администраторам (или staff_notify_email в config, если никого не отмечено).',
                'outputs' => 1,
                'class' => 'automation-node--staff',
            ],
            [
                'id' => 'end',
                'label' => 'Конец',
                'short' => 'Конец',
                'description' => 'Завершить run для ученика. У каждой завершённой ветки должен быть свой «Конец».',
                'outputs' => 0,
                'class' => 'automation-node--end',
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function conditionOptions(): array
    {
        return [
            [
                'value' => 'has_paid_any',
                'label' => 'Купил любой курс',
                'hint' => 'Полный доступ хотя бы к одному курсу.',
                'needs_course' => false,
            ],
            [
                'value' => 'has_paid_course',
                'label' => 'Купил этот курс',
                'hint' => 'Оплата именно выбранного slug (ниже).',
                'needs_course' => true,
            ],
            [
                'value' => 'has_active_demo',
                'label' => 'Активное демо на курс',
                'hint' => 'Демо ещё не истекло по expires_at.',
                'needs_course' => true,
            ],
            [
                'value' => 'demo_lesson_opened',
                'label' => 'Открыл демо-урок',
                'hint' => 'Тег AVO / открытие урока в кабинете.',
                'needs_course' => true,
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function mailTemplates(): array
    {
        $out = [];
        foreach (EmailTemplateCatalog::all() as $meta) {
            $id = (string)($meta['id'] ?? '');
            if ($id === '' || $id === 'test' || empty($meta['has_html'])) {
                continue;
            }
            if (!in_array($id, [
                'demo',
                'reminder_demo_no_login',
                'reminder_demo_no_lesson',
                'reminder_demo_expiring',
                'sale_demo_discount_24h',
                'sale_demo_discount_3h',
                'sale_crosssell_50_offer',
                'sale_crosssell_50_reminder',
            ], true)) {
                continue;
            }
            $out[] = [
                'value' => $id,
                'label' => (string)($meta['label'] ?? $id),
                'edit_url' => '/admin/emails/' . $id . '/edit',
                'kind' => MarketingEmailDelivery::isMarketingTemplate($id) ? 'marketing' : 'transactional',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $automation row from email_automations
     * @return array<string, mixed>
     */
    public static function editorConfig(?array $automation = null): array
    {
        $automation ??= [];
        $entryMode = EmailAutomation::normalizeEntryMode((string)($automation['entry_mode'] ?? ''));
        $entryLabels = EmailAutomation::entryModeLabels();

        return [
            'palette' => self::palette(),
            'conditions' => self::conditionOptions(),
            'templates' => self::mailTemplates(),
            'courses' => self::courseOptionsForEditor(),
            'entry_modes' => self::entryModeOptionsForEditor(),
            'marketing_templates' => MarketingEmailDelivery::automationMarketingTemplateIds(),
            'staff_notify_default_email' => trim((string)(wwm_config()['staff_notify_email'] ?? '')),
            'staff_admins' => StaffNotifyRecipients::optionsForEditor(),
            'staff_notify_placeholders' => [
                '{{student_name}}',
                '{{student_email}}',
                '{{course_slug}}',
                '{{automation_title}}',
                '{{automation_slug}}',
                '{{step_label}}',
                '{{admin_student_url}}',
            ],
            'demo_email_edit_url' => '/admin/emails/demo/edit',
            'automation' => [
                'title' => (string)($automation['title'] ?? ''),
                'slug' => (string)($automation['slug'] ?? ''),
                'course_slug' => (string)($automation['course_slug'] ?? ''),
                'entry_mode' => $entryMode,
                'entry_mode_label' => $entryLabels[$entryMode] ?? $entryMode,
            ],
            'end_outcomes' => [
                ['value' => 'completed', 'label' => 'Обычное завершение'],
                ['value' => 'converted', 'label' => 'Конверсия (оплата)'],
                ['value' => 'exited', 'label' => 'Вышел без покупки'],
                ['value' => 'other', 'label' => 'Другое'],
            ],
        ];
    }

    /**
     * Default data for a new node on canvas.
     *
     * @return array<string, mixed>
     */
    public static function defaultNodeData(string $type, string $nodeId, string $courseSlug): array
    {
        $label = $type;
        foreach (self::palette() as $item) {
            if ($item['id'] === $type) {
                $label = $item['label'];
                break;
            }
        }

        $data = [
            'node_id' => $nodeId,
            'type' => $type,
            'label' => $label,
        ];

        return match ($type) {
            'grant_demo' => $data + [
                'course_slug' => $courseSlug,
                'skip_if_paid' => true,
                'skip_if_demo_active' => true,
            ],
            'revoke_demo' => $data + ['course_slug' => $courseSlug],
            'delay' => $data + ['seconds' => 3600],
            'condition' => $data + ['condition' => 'has_paid_any', 'course_slug' => $courseSlug],
            'send_template' => $data + [
                'template' => 'reminder_demo_no_login',
                'course_slug' => $courseSlug,
                'skip_if_paid_course' => false,
            ],
            'notify_staff' => $data + [
                'staff_admin_ids' => [],
                'staff_recipients' => '',
                'notify_subject' => 'WWM automation: {{step_label}}',
                'notify_body' => "{{student_name}}\n{{student_email}}\n{{course_slug}}\n{{automation_title}}\n{{admin_student_url}}",
            ],
            'end' => $data + ['outcome' => 'completed'],
            'trigger' => $data + [
                'entry_mode' => EmailAutomation::ENTRY_DEMO_GRANT,
                'process_course_slug' => preg_replace('/[^a-z0-9\-]/', '', $courseSlug),
            ],
            default => $data,
        };
    }
}
