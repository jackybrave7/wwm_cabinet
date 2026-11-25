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
            return ['Add at least one block to the flow.'];
        }
        if (!is_array($edges)) {
            return ['The list of connections (edges) is invalid.'];
        }

        $entryIds = [];
        foreach ($nodes as $id => $node) {
            if (!is_array($node)) {
                $issues[] = 'Block “' . $id . '” is damaged.';
                continue;
            }
            $type = (string)($node['type'] ?? '');
            if ($type === 'trigger' || $id === 'start') {
                $entryIds[] = (string)$id;
            }
        }
        if ($entryIds === []) {
            $issues[] = 'A Start block is required (type: trigger, usually id start).';
        }

        $out = [];
        $in = [];
        foreach (array_keys($nodes) as $id) {
            $out[$id] = [];
            $in[$id] = [];
        }

        foreach ($edges as $i => $edge) {
            if (!is_array($edge)) {
                $issues[] = 'Connection #' . ($i + 1) . ' is invalid.';
                continue;
            }
            $from = (string)($edge['from'] ?? '');
            $to = (string)($edge['to'] ?? '');
            if ($from === '' || $to === '') {
                $issues[] = 'Connection #' . ($i + 1) . ': set from and to.';
                continue;
            }
            if (!isset($nodes[$from])) {
                $issues[] = 'Connection from an unknown block “' . $from . '”.';
                continue;
            }
            if (!isset($nodes[$to])) {
                $issues[] = 'Connection to an unknown block “' . $to . '”.';
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
                    $issues[] = '“' . $label . '”: the End block is not connected (no incoming line).';
                }
                if ($outCount > 0) {
                    $issues[] = '“' . $label . '”: nothing should leave an End block.';
                }
                continue;
            }

            if ($type === 'trigger' || $id === 'start') {
                if ($outCount === 0) {
                    $issues[] = '“' . $label . '”: Start needs a line to the next step.';
                }
                continue;
            }

            if ($inCount === 0 && $outCount === 0) {
                $issues[] = '“' . $label . '”: this block is isolated (no connections).';
                continue;
            }
            if ($inCount === 0) {
                $issues[] = '“' . $label . '”: no incoming line (cannot be reached from Start).';
            }
            if ($outCount === 0) {
                $issues[] = '“' . $label . '”: no outgoing line (add a step or End).';
            }

            if ($type === 'condition' || $type === 'wait_event') {
                $branches = array_map(static fn (array $e): string => (string)($e['branch'] ?? 'next'), $out[$id] ?? []);
                $hasYes = in_array('yes', $branches, true);
                $hasNo = in_array('no', $branches, true);
                if (!$hasYes || !$hasNo) {
                    $issues[] = '“' . $label . '”: the block needs both a yes branch and a no branch.';
                }
            }

            $paramIssue = self::nodeParameterIssue($id, $node);
            if ($paramIssue !== null) {
                $issues[] = $paramIssue;
            }
        }

        if (!$hasEnd) {
            $issues[] = 'Add at least one End block and connect the finishing branches.';
        }

        if ($edges === [] && count($nodes) > 1) {
            $issues[] = 'Connect the blocks. A flow of several blocks with no lines cannot be saved.';
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
                $issues[] = '“' . self::nodeLabel($id, $node) . '”: cannot be reached from Start.';
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
                return '“' . $label . '”: choose an email template.';
            }
            if (str_starts_with($tpl, 'sale_crosssell_')) {
                $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($node['course_slug'] ?? ''));
                if ($slug === '') {
                    return '“' . $label . '”: a cross-sell letter needs a course (course slug).';
                }
            }
        }

        if ($type === 'grant_demo') {
            $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($node['course_slug'] ?? ''));
            if ($slug === '') {
                return '“' . $label . '”: set the demo course.';
            }
        }

        if ($type === 'condition') {
            $cond = (string)($node['condition'] ?? '');
            if ($cond === '') {
                return '“' . $label . '”: choose a condition.';
            }
            if (in_array($cond, ['has_paid_course', 'has_active_demo', 'demo_lesson_opened'], true)) {
                $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($node['course_slug'] ?? ''));
                if ($slug === '') {
                    return '“' . $label . '”: this condition needs a course.';
                }
            }
        }

        if ($type === 'revoke_demo') {
            return '“' . $label . '”: the Revoke demo block is obsolete — demo expires on the course timer. Remove the block and connect the branches directly.';
        }

        if ($type === 'end') {
            $outcome = (string)($node['outcome'] ?? '');
            $allowed = ['completed', 'converted', 'exited', 'other', ''];
            if ($outcome !== '' && !in_array($outcome, $allowed, true)) {
                return '“' . $label . '”: invalid end type.';
            }
        }

        if ($type === 'delay') {
            $mode = trim((string)($node['delay_mode'] ?? ''));
            $until = trim((string)($node['until'] ?? ''));
            if ($mode === 'duration') {
                $until = '';
            }
            if ($mode === 'until' && $until === '') {
                return '“' . $label . '”: set the date and time for this pause.';
            }
            if ($until !== '' && strtotime($until) === false) {
                return '“' . $label . '”: that pause time is not valid.';
            }
            $sec = (int)($node['seconds'] ?? -1);
            if ($until === '' && $sec < 0) {
                return '“' . $label . '”: set how long the pause lasts.';
            }
        }

        if ($type === 'wait_event') {
            $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($node['course_slug'] ?? ''));
            if ($slug === '') {
                return '“' . $label . '”: set a course.';
            }
            if ((int)($node['timeout_seconds'] ?? 0) < 60) {
                return '“' . $label . '”: set a wait timeout.';
            }
        }

        if ($type === 'notify_staff') {
            $ids = $node['staff_admin_ids'] ?? null;
            if (is_array($ids)) {
                foreach ($ids as $key) {
                    if (trim((string)$key) === '') {
                        return '“' . $label . '”: an empty administrator id is in the recipient list.';
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
                        return '“' . $label . '”: invalid email in the legacy staff_recipients field.';
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
