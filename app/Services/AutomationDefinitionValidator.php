<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Structural checks for email automation flow definitions (nodes + edges).
 */
final class AutomationDefinitionValidator
{
    /**
     * @param array<string, mixed> $definition
     * @return list<string> human-readable issues (empty = ok)
     */
    public static function issues(array $definition): array
    {
        $issues = [];
        $nodes = $definition['nodes'] ?? null;
        $edges = $definition['edges'] ?? null;

        if (!is_array($nodes) || $nodes === []) {
            return ['Добавьте хотя бы один блок на схему.'];
        }
        if (!is_array($edges)) {
            return ['Некорректный список связей (edges).'];
        }

        $entryIds = [];
        foreach ($nodes as $id => $node) {
            if (!is_array($node)) {
                $issues[] = 'Узел «' . $id . '» повреждён.';
                continue;
            }
            $type = (string)($node['type'] ?? '');
            if ($type === 'trigger' || $id === 'start') {
                $entryIds[] = (string)$id;
            }
        }
        if ($entryIds === []) {
            $issues[] = 'Нужен блок «Старт» (type: trigger, обычно id start).';
        }

        $out = [];
        $in = [];
        foreach (array_keys($nodes) as $id) {
            $out[$id] = [];
            $in[$id] = [];
        }

        foreach ($edges as $i => $edge) {
            if (!is_array($edge)) {
                $issues[] = 'Связь #' . ($i + 1) . ' некорректна.';
                continue;
            }
            $from = (string)($edge['from'] ?? '');
            $to = (string)($edge['to'] ?? '');
            if ($from === '' || $to === '') {
                $issues[] = 'Связь #' . ($i + 1) . ': укажите from и to.';
                continue;
            }
            if (!isset($nodes[$from])) {
                $issues[] = 'Связь из неизвестного блока «' . $from . '».';
                continue;
            }
            if (!isset($nodes[$to])) {
                $issues[] = 'Связь в неизвестный блок «' . $to . '».';
                continue;
            }
            $branch = (string)($edge['branch'] ?? 'next');
            $out[$from][] = ['to' => $to, 'branch' => $branch];
            $in[$to][] = ['from' => $from, 'branch' => $branch];
        }

        $hasEnd = false;
        foreach ($nodes as $id => $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string)($node['type'] ?? '');
            $label = self::nodeLabel($id, $node);
            $outCount = count($out[$id] ?? []);
            $inCount = count($in[$id] ?? []);

            if ($type === 'end') {
                $hasEnd = true;
                if ($inCount === 0) {
                    $issues[] = '«' . $label . '»: блок «Конец» не подключён (нет входящей связи).';
                }
                if ($outCount > 0) {
                    $issues[] = '«' . $label . '»: после «Конец» не должно быть исходящих связей.';
                }
                continue;
            }

            if ($type === 'trigger' || $id === 'start') {
                if ($outCount === 0) {
                    $issues[] = '«' . $label . '»: от старта должна идти связь к следующему шагу.';
                }
                continue;
            }

            if ($inCount === 0 && $outCount === 0) {
                $issues[] = '«' . $label . '»: блок изолирован (нет связей).';
                continue;
            }
            if ($inCount === 0) {
                $issues[] = '«' . $label . '»: нет входящей связи (недостижим из старта).';
            }
            if ($outCount === 0) {
                $issues[] = '«' . $label . '»: нет исходящей связи (добавьте шаг или «Конец»).';
            }

            if ($type === 'condition') {
                $branches = array_map(static fn (array $e): string => (string)($e['branch'] ?? 'next'), $out[$id] ?? []);
                $hasYes = in_array('yes', $branches, true);
                $hasNo = in_array('no', $branches, true);
                if (!$hasYes || !$hasNo) {
                    $issues[] = '«' . $label . '»: у условия должны быть обе ветки — «да» и «нет».';
                }
            }

            $paramIssue = self::nodeParameterIssue($id, $node);
            if ($paramIssue !== null) {
                $issues[] = $paramIssue;
            }
        }

        if (!$hasEnd) {
            $issues[] = 'Добавьте хотя бы один блок «Конец» (end) и соедините завершающие ветки.';
        }

        if ($edges === [] && count($nodes) > 1) {
            $issues[] = 'Соедините блоки линиями — схема из нескольких блоков без связей не сохранится.';
        }

        $reachable = self::reachableFromEntries($nodes, $out, $entryIds);
        foreach (array_keys($nodes) as $id) {
            $node = $nodes[$id];
            if (!is_array($node)) {
                continue;
            }
            $type = (string)($node['type'] ?? '');
            if ($type === 'end') {
                continue;
            }
            if (!in_array($id, $reachable, true) && $type !== 'trigger' && $id !== 'start') {
                $issues[] = '«' . self::nodeLabel($id, $node) . '»: не достигается от старта по связям.';
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function validate(array $definition): void
    {
        $issues = self::issues($definition);
        if ($issues !== []) {
            throw new \InvalidArgumentException(implode("\n", $issues));
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function nodeLabel(string $id, array $node): string
    {
        $label = trim((string)($node['label'] ?? ''));
        if ($label !== '') {
            return $label . ' (' . $id . ')';
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function nodeParameterIssue(string $id, array $node): ?string
    {
        $type = (string)($node['type'] ?? '');
        $label = self::nodeLabel($id, $node);

        if ($type === 'send_template') {
            $tpl = trim((string)($node['template'] ?? ''));
            if ($tpl === '') {
                return '«' . $label . '»: выберите шаблон письма.';
            }
            if (MarketingEmailDelivery::isMarketingTemplate($tpl)) {
                $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($node['course_slug'] ?? ''));
                if ($slug === '') {
                    return '«' . $label . '»: для cross-sell письма укажите курс (course slug).';
                }
            }
        }

        if ($type === 'grant_demo') {
            $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($node['course_slug'] ?? ''));
            if ($slug === '') {
                return '«' . $label . '»: укажите курс демо.';
            }
        }

        if ($type === 'condition') {
            $cond = (string)($node['condition'] ?? '');
            if ($cond === '') {
                return '«' . $label . '»: выберите тип условия.';
            }
            if (in_array($cond, ['has_paid_course', 'has_active_demo', 'demo_lesson_opened'], true)) {
                $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($node['course_slug'] ?? ''));
                if ($slug === '') {
                    return '«' . $label . '»: для этого условия нужен курс.';
                }
            }
        }

        if ($type === 'revoke_demo') {
            return '«' . $label . '»: блок «Отзыв демо» устарел — демо истекает по таймеру курса. Удалите блок и соедините ветки напрямую.';
        }

        if ($type === 'end') {
            $outcome = (string)($node['outcome'] ?? '');
            $allowed = ['completed', 'converted', 'exited', 'other', ''];
            if ($outcome !== '' && !in_array($outcome, $allowed, true)) {
                return '«' . $label . '»: некорректный тип завершения.';
            }
        }

        if ($type === 'delay') {
            $sec = (int)($node['seconds'] ?? -1);
            if ($sec < 0) {
                return '«' . $label . '»: укажите длительность паузы.';
            }
        }

        if ($type === 'notify_staff') {
            $ids = $node['staff_admin_ids'] ?? null;
            if (is_array($ids)) {
                foreach ($ids as $key) {
                    if (trim((string)$key) === '') {
                        return '«' . $label . '»: пустой id администратора в списке получателей.';
                    }
                }
            }
            $raw = trim((string)($node['staff_recipients'] ?? ''));
            if ($raw !== '') {
                foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $part) {
                    $email = trim($part);
                    if ($email === '') {
                        continue;
                    }
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        return '«' . $label . '»: некорректный email в legacy-поле staff_recipients.';
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, list<array{to: string, branch: string}>> $out
     * @param list<string> $entryIds
     * @return list<string>
     */
    private static function reachableFromEntries(array $nodes, array $out, array $entryIds): array
    {
        $seen = [];
        $queue = $entryIds;
        while ($queue !== []) {
            $id = array_shift($queue);
            if ($id === null || isset($seen[$id]) || !isset($nodes[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($out[$id] ?? [] as $edge) {
                $to = $edge['to'];
                if (!isset($seen[$to])) {
                    $queue[] = $to;
                }
            }
        }

        return array_keys($seen);
    }
}
