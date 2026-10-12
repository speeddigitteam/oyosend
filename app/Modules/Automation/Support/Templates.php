<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

/**
 * Starter graphs. A template is only a pre-built draft in the same wire format the builder produces: it
 * grants nothing and is validated exactly like any other graph when published.
 */
final class Templates
{
    /**
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return [
            'schema_version' => 1,
            'nodes' => [
                self::node('trigger', 'trigger', 0, 0, ['trigger_key' => 'automation.trigger.manual', 'config' => []]),
                self::node('end', 'end', 0, 160, ['status' => 'success']),
            ],
            'edges' => [self::edge('e1', 'trigger', 'end')],
        ];
    }

    /**
     * @return list<array{key: string, name: string, description: string, graph: array<string, mixed>}>
     */
    public static function catalogue(): array
    {
        return [
            [
                'key' => 'approval_gate',
                'name' => 'Manual request with approval',
                'description' => 'Started by hand; a member with approval rights decides before the workflow ends.',
                'graph' => [
                    'schema_version' => 1,
                    'nodes' => [
                        self::node('trigger', 'trigger', 0, 0, ['trigger_key' => 'automation.trigger.manual', 'config' => []]),
                        self::node('approve', 'approval', 0, 160, [
                            'title' => 'Approve this request',
                            'message' => '',
                            'approver' => ['type' => 'permission', 'permission' => 'automation.approvals.decide'],
                            'prevent_self_approval' => true,
                            'timeout_hours' => 72,
                            'on_timeout' => 'reject',
                        ]),
                        self::node('done', 'end', -160, 340, ['status' => 'success']),
                        self::node('declined', 'end', 160, 340, ['status' => 'failed', 'message' => 'Rejected']),
                    ],
                    'edges' => [
                        self::edge('e1', 'trigger', 'approve'),
                        self::edge('e2', 'approve', 'done', 'approved'),
                        self::edge('e3', 'approve', 'declined', 'rejected'),
                    ],
                ],
            ],
            [
                'key' => 'welcome_delay',
                'name' => 'Welcome follow-up after a day',
                'description' => 'When someone joins the workspace, wait a day and prepare a follow-up message.',
                'graph' => [
                    'schema_version' => 1,
                    'nodes' => [
                        self::node('trigger', 'trigger', 0, 0, ['trigger_key' => 'core.member.joined', 'config' => []]),
                        self::node('wait', 'delay', 0, 150, ['amount' => 1, 'unit' => 'days']),
                        self::node('message', 'transform', 0, 300, ['mappings' => [
                            ['target' => 'greeting', 'operation' => 'template', 'value' => 'Welcome, {{ trigger.name }}!'],
                        ]]),
                        self::node('end', 'end', 0, 450, ['status' => 'success']),
                    ],
                    'edges' => [
                        self::edge('e1', 'trigger', 'wait'),
                        self::edge('e2', 'wait', 'message'),
                        self::edge('e3', 'message', 'end'),
                    ],
                ],
            ],
            [
                'key' => 'scheduled_webhook',
                'name' => 'Scheduled webhook call',
                'description' => 'Every weekday morning, post a signed message to an endpoint you registered.',
                'graph' => [
                    'schema_version' => 1,
                    'nodes' => [
                        self::node('trigger', 'trigger', 0, 0, ['trigger_key' => 'automation.trigger.schedule', 'config' => ['cron' => '0 9 * * 1-5']]),
                        self::node('call', 'webhook', 0, 150, ['endpoint_id' => '', 'payload' => ['event' => 'daily_check']]),
                        self::node('end', 'end', 0, 300, ['status' => 'success']),
                    ],
                    'edges' => [
                        self::edge('e1', 'trigger', 'call'),
                        self::edge('e2', 'call', 'end'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function node(string $id, string $type, int $x, int $y, array $config): array
    {
        return ['id' => $id, 'type' => $type, 'position' => ['x' => $x, 'y' => $y], 'data' => ['label' => '', 'config' => $config]];
    }

    /** @return array<string, mixed> */
    private static function edge(string $id, string $source, string $target, ?string $handle = null): array
    {
        return ['id' => $id, 'source' => $source, 'target' => $target, 'sourceHandle' => $handle, 'data' => ['loopBack' => false]];
    }
}
