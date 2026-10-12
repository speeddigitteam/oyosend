<?php

declare(strict_types=1);

namespace App\Modules\Automation\Graph;

/** What the validator found in a graph: blocking errors and advisory warnings, each tied to a node or edge. */
final class ValidationReport
{
    /** @var list<array{severity: string, code: string, message: string, node_id: string|null, edge_id: string|null}> */
    private array $issues = [];

    public function __construct(public Graph $graph) {}

    public function add(string $severity, string $code, string $message, ?string $nodeId = null, ?string $edgeId = null): void
    {
        $this->issues[] = [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'node_id' => $nodeId,
            'edge_id' => $edgeId,
        ];
    }

    public function error(string $code, string $message, ?string $nodeId = null, ?string $edgeId = null): void
    {
        $this->add('error', $code, $message, $nodeId, $edgeId);
    }

    public function warning(string $code, string $message, ?string $nodeId = null, ?string $edgeId = null): void
    {
        $this->add('warning', $code, $message, $nodeId, $edgeId);
    }

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue['severity'] === 'error') {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{severity: string, code: string, message: string, node_id: string|null, edge_id: string|null}> */
    public function issues(): array
    {
        return $this->issues;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(static fn (array $issue): string => $issue['code'], $this->issues);
    }

    /** @return array{valid: bool, issues: list<array{severity: string, code: string, message: string, node_id: string|null, edge_id: string|null}>} */
    public function toArray(): array
    {
        return ['valid' => ! $this->hasErrors(), 'issues' => $this->issues];
    }
}
