<?php

declare(strict_types=1);

namespace App\Modules\Automation\Graph;

use App\Modules\Automation\Enums\NodeType;

/**
 * A normalized workflow graph.
 *
 * Wire format (also what the builder stores and a published version freezes):
 *
 *     {
 *       "schema_version": 1,
 *       "nodes": [{"id": "n1", "type": "trigger", "position": {"x": 0, "y": 0},
 *                  "data": {"label": "Lead created", "config": {...}}}],
 *       "edges": [{"id": "e1", "source": "n1", "target": "n2", "sourceHandle": null,
 *                  "data": {"loopBack": false}}]
 *     }
 *
 * `sourceHandle` is the named outgoing connection point (`true`/`false` for a condition, `approved` /
 * `rejected` / `error` for an approval, `body` / `done` for a loop, a case id or `default` for a branch,
 * `error` for an action or webhook, null otherwise).
 */
final class Graph
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  array<string, array{id: string, type: NodeType, label: string, config: array<string, mixed>, position: array{x: float|int, y: float|int}}>  $nodes
     * @param  list<array{id: string, source: string, target: string, handle: string|null, loopBack: bool}>  $edges
     */
    public function __construct(public readonly array $nodes, public readonly array $edges) {}

    /**
     * Parse the wire format. Unknown keys are dropped; malformed structure yields an empty graph plus the
     * problems found (the validator reports them).
     *
     * @param  array<string, mixed>  $raw
     * @return array{0: self, 1: list<string>}
     */
    public static function parse(array $raw): array
    {
        $problems = [];
        $nodes = [];
        $edges = [];

        foreach (is_array($raw['nodes'] ?? null) ? $raw['nodes'] : [] as $index => $node) {
            if (! is_array($node)) {
                $problems[] = "nodes.{$index} is not an object";

                continue;
            }

            $id = $node['id'] ?? null;
            $type = is_string($node['type'] ?? null) ? NodeType::tryFrom($node['type']) : null;

            if (! is_string($id) || preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $id) !== 1) {
                $problems[] = "nodes.{$index} has an invalid id";

                continue;
            }

            if (isset($nodes[$id])) {
                $problems[] = "node id [{$id}] is used twice";

                continue;
            }

            if ($type === null) {
                $problems[] = "node [{$id}] has an unknown type";

                continue;
            }

            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $position = is_array($node['position'] ?? null) ? $node['position'] : [];
            $nodes[$id] = [
                'id' => $id,
                'type' => $type,
                'label' => mb_substr(is_string($data['label'] ?? null) ? $data['label'] : '', 0, 120),
                'config' => is_array($data['config'] ?? null) ? $data['config'] : [],
                'position' => [
                    'x' => is_numeric($position['x'] ?? null) ? 0 + $position['x'] : 0,
                    'y' => is_numeric($position['y'] ?? null) ? 0 + $position['y'] : 0,
                ],
            ];
        }

        $edgeIds = [];

        foreach (is_array($raw['edges'] ?? null) ? $raw['edges'] : [] as $index => $edge) {
            if (! is_array($edge)) {
                $problems[] = "edges.{$index} is not an object";

                continue;
            }

            $id = $edge['id'] ?? null;
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;
            $handle = ($edge['sourceHandle'] ?? null) === '' ? null : ($edge['sourceHandle'] ?? null);

            if (! is_string($id) || preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $id) !== 1 || isset($edgeIds[$id])) {
                $problems[] = "edges.{$index} has a missing or duplicate id";

                continue;
            }

            if (! is_string($source) || ! is_string($target)) {
                $problems[] = "edge [{$id}] needs a source and a target";

                continue;
            }

            if ($handle !== null && (! is_string($handle) || preg_match('/^[A-Za-z0-9_\-]{1,32}$/D', $handle) !== 1)) {
                $problems[] = "edge [{$id}] has an invalid handle";

                continue;
            }

            $edgeIds[$id] = true;
            $data = is_array($edge['data'] ?? null) ? $edge['data'] : [];
            $edges[] = [
                'id' => $id,
                'source' => $source,
                'target' => $target,
                'handle' => $handle,
                'loopBack' => ($data['loopBack'] ?? false) === true,
            ];
        }

        return [new self($nodes, $edges), $problems];
    }

    /**
     * Canonical wire form: stable key order, only known keys. This is what is hashed and stored.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'nodes' => array_values(array_map(static fn (array $node): array => [
                'id' => $node['id'],
                'type' => $node['type']->value,
                'position' => $node['position'],
                'data' => ['label' => $node['label'], 'config' => $node['config']],
            ], $this->nodes)),
            'edges' => array_map(static fn (array $edge): array => [
                'id' => $edge['id'],
                'source' => $edge['source'],
                'target' => $edge['target'],
                'sourceHandle' => $edge['handle'],
                'data' => ['loopBack' => $edge['loopBack']],
            ], $this->edges),
        ];
    }

    /** @return array{id: string, type: NodeType, label: string, config: array<string, mixed>, position: array{x: float|int, y: float|int}}|null */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    /** @return array{id: string, type: NodeType, label: string, config: array<string, mixed>, position: array{x: float|int, y: float|int}}|null */
    public function trigger(): ?array
    {
        foreach ($this->nodes as $node) {
            if ($node['type'] === NodeType::Trigger) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Edges leaving a node through one handle (null = the unnamed handle).
     *
     * @return list<array{id: string, source: string, target: string, handle: string|null, loopBack: bool}>
     */
    public function outgoing(string $nodeId, ?string $handle = null): array
    {
        return array_values(array_filter(
            $this->edges,
            static fn (array $edge): bool => $edge['source'] === $nodeId && $edge['handle'] === $handle,
        ));
    }

    /**
     * @return list<array{id: string, source: string, target: string, handle: string|null, loopBack: bool}>
     */
    public function outgoingAll(string $nodeId): array
    {
        return array_values(array_filter($this->edges, static fn (array $edge): bool => $edge['source'] === $nodeId));
    }

    public function hasIncoming(string $nodeId): bool
    {
        foreach ($this->edges as $edge) {
            if ($edge['target'] === $nodeId) {
                return true;
            }
        }

        return false;
    }

    /** The error handler with no incoming edge, used when a failing node has no `error` route of its own. */
    public function globalErrorHandler(): ?string
    {
        foreach ($this->nodes as $node) {
            if ($node['type'] === NodeType::ErrorHandler && ! $this->hasIncoming($node['id'])) {
                return $node['id'];
            }
        }

        return null;
    }
}
