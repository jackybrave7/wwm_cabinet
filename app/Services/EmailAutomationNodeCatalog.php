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
                'label' => 'Start',
                'short' => 'Старт',
                'description' => 'Entry when student joins the funnel',
                'outputs' => 1,
                'class' => 'automation-node--trigger',
            ],
            [
                'id' => 'grant_demo',
                'label' => 'Grant demo',
                'short' => 'Демо',
                'description' => 'Create demo access (usually skip if demo webhook already ran)',
                'outputs' => 1,
                'class' => 'automation-node--grant',
            ],
            [
                'id' => 'delay',
                'label' => 'Wait',
                'short' => 'Пауза',
                'description' => 'Pause before the next step',
                'outputs' => 1,
                'class' => 'automation-node--delay',
            ],
            [
                'id' => 'condition',
                'label' => 'Condition',
                'short' => 'Условие',
                'description' => 'Yes / No branch',
                'outputs' => 2,
                'class' => 'automation-node--condition',
            ],
            [
                'id' => 'send_template',
                'label' => 'Send email',
                'short' => 'Письмо',
                'description' => 'Transactional template from cabinet',
                'outputs' => 1,
                'class' => 'automation-node--email',
            ],
            [
                'id' => 'revoke_demo',
                'label' => 'Revoke demo',
                'short' => 'Отзыв',
                'description' => 'Remove demo access',
                'outputs' => 1,
                'class' => 'automation-node--revoke',
            ],
            [
                'id' => 'notify_staff',
                'label' => 'Notify staff',
                'short' => 'Staff',
                'description' => 'Log + optional email to staff',
                'outputs' => 1,
                'class' => 'automation-node--staff',
            ],
            [
                'id' => 'end',
                'label' => 'End',
                'short' => 'Конец',
                'description' => 'Stop the flow',
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
            ['value' => 'has_paid_any', 'label' => 'Purchased any course'],
            ['value' => 'has_paid_course', 'label' => 'Purchased this course'],
            ['value' => 'demo_lesson_opened', 'label' => 'Opened demo lesson'],
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
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function editorConfig(): array
    {
        return [
            'palette' => self::palette(),
            'conditions' => self::conditionOptions(),
            'templates' => self::mailTemplates(),
            'courses' => self::courseOptionsForEditor(),
            'entry_modes' => self::entryModeOptionsForEditor(),
            'marketing_templates' => MarketingEmailDelivery::automationMarketingTemplateIds(),
            'demo_email_edit_url' => '/admin/emails/demo/edit',
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
            'grant_demo', 'revoke_demo' => $data + ['course_slug' => $courseSlug, 'send_email' => $type === 'grant_demo'],
            'delay' => $data + ['seconds' => 3600],
            'condition' => $data + ['condition' => 'has_paid_any', 'course_slug' => $courseSlug],
            'send_template' => $data + ['template' => 'reminder_demo_no_login', 'course_slug' => $courseSlug],
            default => $data,
        };
    }
}
