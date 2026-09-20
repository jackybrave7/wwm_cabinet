<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Converts AVO business-process export (nodes_json + connections_json) into cabinet automation definition.
 */
final class AvoAutomationImporter
{
    /**
     * @return array{title: string, definition: array<string, mixed>, warnings: list<string>}
     */
    public static function fromAvoExport(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON');
        }

        $nodes = $data['nodes_json'] ?? null;
        if (!is_array($nodes) || $nodes === []) {
            throw new \InvalidArgumentException('Missing nodes_json');
        }

        $warnings = [];
        $byId = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['blockId'])) {
                continue;
            }
            $byId[(string)$node['blockId']] = $node;
        }

        $title = 'Imported AVO automation';
        foreach ($nodes as $node) {
            if ((string)($node['action_type'] ?? '') === '1') {
                $title = trim((string)($node['title'] ?? $title));
                break;
            }
        }

        $courseSlug = 'elke-en';
        foreach ($nodes as $node) {
            if ((string)($node['action_type'] ?? '') !== '35') {
                continue;
            }
            $url = (string)($node['properties']['url'] ?? '');
            if (preg_match('/course=([a-z0-9\-]+)/', $url, $m)) {
                $courseSlug = $m[1];
                break;
            }
        }

        $definition = self::canonicalElkeDemoDefinition($courseSlug);
        $warnings[] = 'AVO graph was mapped to the canonical Elke demo funnel definition; review delays and branches in the editor.';

        return [
            'title' => $title,
            'definition' => $definition,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function canonicalElkeDemoDefinition(string $courseSlug = 'elke-en'): array
    {
        $path = WWM_ROOT . '/data/automations/elke-en-demo-subscription.v1.json';
        if (is_readable($path)) {
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                if (isset($decoded['meta']) && is_array($decoded['meta'])) {
                    $decoded['meta']['course_slug'] = $courseSlug;
                }

                return $decoded;
            }
        }

        throw new \RuntimeException('Canonical automation template missing');
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function validateDefinition(array $definition): void
    {
        AutomationDefinitionValidator::validate($definition);
    }
}
