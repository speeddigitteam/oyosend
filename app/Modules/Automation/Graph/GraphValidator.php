<?php

declare(strict_types=1);

namespace App\Modules\Automation\Graph;

use App\Core\Automation\AutomationRegistry;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Automation\Engine\ActorGuard;
use App\Modules\Automation\Engine\OperatorRegistry;
use App\Modules\Automation\Engine\SchemaChecker;
use App\Modules\Automation\Engine\Transformer;
use App\Modules\Automation\Engine\VariableResolver;
use App\Modules\Automation\Enums\NodeType;
use App\Modules\Automation\Models\WebhookEndpoint;
use App\Modules\Automation\Support\Limits;
use Cron\CronExpression;

/**
 * Validates a workflow graph before it can be saved as publishable.
 *
 * Structure (one trigger, known node types, valid edges and handles, no cycles except declared loop
 * back-edges, every node reachable), per-node configuration, registry membership (unknown trigger or action
 * keys are refused) and - given a publisher - the permission and entitlement of every trigger and action.
 * Nothing here executes anything.
 */
final class GraphValidator
{
    public function __construct(
        private readonly AutomationRegistry $registry,
        private readonly OperatorRegistry $operators,
        private readonly VariableResolver $variables,
        private readonly SchemaChecker $schemas,
        private readonly ActorGuard $guard,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  The graph as sent by the builder.
     * @param  TenantMembership|null  $actor  Who the workflow would run as; permission checks run only when given.
     * @param  bool  $strict  Publishing: permission/entitlement problems are errors rather than warnings.
     */
    public function validate(array $raw, ?TenantMembership $actor = null, bool $strict = false): ValidationReport
    {
        [$graph, $problems] = Graph::parse($raw);
        $report = new ValidationReport($graph);

        foreach ($problems as $problem) {
            $report->error('malformed', __('The graph is malformed: :problem.', ['problem' => $problem]));
        }

        if (strlen((string) json_encode($raw)) > Limits::int('max_graph_bytes')) {
            $report->error('too_large', __('The workflow is too large.'));

            return $report;
        }

        if (count($graph->nodes) > Limits::int('max_nodes')) {
            $report->error('too_many_nodes', __('A workflow may have at most :max nodes.', ['max' => Limits::int('max_nodes')]));

            return $report;
        }

        if (count($graph->edges) > Limits::int('max_edges')) {
            $report->error('too_many_edges', __('A workflow may have at most :max connections.', ['max' => Limits::int('max_edges')]));

            return $report;
        }

        $this->trigger($graph, $report);
        $this->edges($graph, $report);

        foreach ($graph->nodes as $node) {
            $this->node($graph, $node, $report, $actor, $strict);
        }

        $this->cycles($graph, $report);
        $this->loops($graph, $report);
        $this->reachability($graph, $report);

        return $report;
    }

    private function trigger(Graph $graph, ValidationReport $report): void
    {
        $triggers = array_filter($graph->nodes, static fn (array $node): bool => $node['type'] === NodeType::Trigger);

        if (count($triggers) === 0) {
            $report->error('no_trigger', __('A workflow needs exactly one trigger.'));
        } elseif (count($triggers) > 1) {
            $report->error('many_triggers', __('A workflow can have only one trigger.'));
        }

        $handlers = array_filter(
            $graph->nodes,
            static fn (array $node): bool => $node['type'] === NodeType::ErrorHandler && ! $graph->hasIncoming($node['id']),
        );

        if (count($handlers) > 1) {
            $report->error('many_global_handlers', __('Only one error handler may be left unconnected as the workflow-wide handler.'));
        }
    }

    private function edges(Graph $graph, ValidationReport $report): void
    {
        $seen = [];

        foreach ($graph->edges as $edge) {
            $source = $graph->node($edge['source']);
            $target = $graph->node($edge['target']);

            if ($source === null || $target === null) {
                $report->error('dangling_edge', __('A connection points to a node that does not exist.'), null, $edge['id']);

                continue;
            }

            if ($edge['source'] === $edge['target']) {
                $report->error('self_edge', __('A node cannot connect to itself.'), $edge['source'], $edge['id']);
            }

            if ($target['type'] === NodeType::Trigger) {
                $report->error('edge_into_trigger', __('Nothing can connect into the trigger.'), $edge['target'], $edge['id']);
            }

            if ($source['type'] === NodeType::End) {
                $report->error('edge_from_end', __('An end node cannot have outgoing connections.'), $edge['source'], $edge['id']);
            }

            if ($edge['loopBack'] && $target['type'] !== NodeType::Loop) {
                $report->error('bad_loop_back', __('Only a connection into a loop node can be a loop-back.'), $edge['source'], $edge['id']);
            }

            $allowed = $this->handles($source);

            if ($edge['handle'] === null ? ! in_array(null, $allowed, true) : ! in_array($edge['handle'], $allowed, true)) {
                $report->error('bad_handle', __('This connection leaves the node from an output it does not have.'), $edge['source'], $edge['id']);
            }

            $key = $edge['source'].'|'.($edge['handle'] ?? '').'|'.$edge['target'];

            if (isset($seen[$key])) {
                $report->error('duplicate_edge', __('Two identical connections.'), $edge['source'], $edge['id']);
            }

            $seen[$key] = true;
        }
    }

    /**
     * @param  array{id: string, type: NodeType, label: string, config: array<string, mixed>, position: array{x: float|int, y: float|int}}  $node
     * @return list<string|null>
     */
    private function handles(array $node): array
    {
        return match ($node['type']) {
            NodeType::Condition => ['true', 'false'],
            NodeType::Approval => ['approved', 'rejected', 'error'],
            NodeType::Loop => ['body', 'done'],
            NodeType::Action, NodeType::Webhook => [null, 'error'],
            NodeType::Branch => [...array_map(
                static fn (mixed $case): string => is_array($case) ? (string) ($case['id'] ?? '') : '',
                is_array($node['config']['cases'] ?? null) ? array_values($node['config']['cases']) : [],
            ), 'default'],
            NodeType::End => [],
            default => [null],
        };
    }

    /**
     * @param  array{id: string, type: NodeType, label: string, config: array<string, mixed>, position: array{x: float|int, y: float|int}}  $node
     */
    private function node(Graph $graph, array $node, ValidationReport $report, ?TenantMembership $actor, bool $strict): void
    {
        $config = $node['config'];
        $id = $node['id'];

        match ($node['type']) {
            NodeType::Trigger => $this->triggerNode($config, $id, $report, $actor, $strict),
            NodeType::Condition => $this->conditionGroup($config, $id, $report, 1),
            NodeType::Branch => $this->branchNode($config, $id, $report),
            NodeType::Action => $this->actionNode($config, $id, $report, $actor, $strict),
            NodeType::Delay => $this->delayNode($config, $id, $report),
            NodeType::Schedule => $this->scheduleNode($config, $id, $report),
            NodeType::Parallel => $this->parallelNode($graph, $config, $id, $report),
            NodeType::Approval => $this->approvalNode($config, $id, $report),
            NodeType::Webhook => $this->webhookNode($config, $id, $report),
            NodeType::Transform => $this->transformNode($config, $id, $report),
            NodeType::Loop => $this->loopNode($config, $id, $report),
            NodeType::ErrorHandler => $this->errorHandlerNode($config, $id, $report),
            NodeType::End => $this->endNode($config, $id, $report),
        };

        $this->references($graph, $node, $report);
    }

    /** @param  array<string, mixed>  $config */
    private function triggerNode(array $config, string $id, ValidationReport $report, ?TenantMembership $actor, bool $strict): void
    {
        $key = $config['trigger_key'] ?? null;
        $class = is_string($key) ? ($this->registry->registeredTriggers()[$key] ?? null) : null;

        if ($class === null) {
            $report->error('unknown_trigger', __('This trigger is not available.'), $id);

            return;
        }

        $this->checkInput($class::inputSchema(), is_array($config['config'] ?? null) ? $config['config'] : [], $id, $report, 'config');

        if ($key === 'automation.trigger.schedule') {
            $cron = (string) (($config['config']['cron'] ?? null) ?? '');

            if ($cron === '' || ! CronExpression::isValidExpression($cron)) {
                $report->error('bad_cron', __('The schedule is not a valid cron expression.'), $id);
            }
        }

        $this->authorize($class::module(), $class::requiredPermission(), $id, $report, $actor, $strict);
    }

    /** @param  array<string, mixed>  $config */
    private function actionNode(array $config, string $id, ValidationReport $report, ?TenantMembership $actor, bool $strict): void
    {
        $key = $config['action_key'] ?? null;
        $class = is_string($key) ? ($this->registry->registeredActions()[$key] ?? null) : null;

        if ($class === null) {
            $report->error('unknown_action', __('This action is not available.'), $id);

            return;
        }

        $input = $config['input'] ?? [];

        if (! is_array($input)) {
            $report->error('bad_input', __('The action input must be an object.'), $id);
        } else {
            $this->checkInput($class::inputSchema(), $input, $id, $report, 'input');
        }

        $this->retry($config, $id, $report);
        $this->authorize($class::module(), $class::requiredPermission(), $id, $report, $actor, $strict);
    }

    private function authorize(string $module, ?string $permission, string $id, ValidationReport $report, ?TenantMembership $actor, bool $strict): void
    {
        if (! $this->actorKnown($actor)) {
            return;
        }

        $denial = $this->guard->denial($actor, $permission, $module);

        if ($denial !== null) {
            $report->add($strict ? 'error' : 'warning', $denial, match ($denial) {
                'module_not_entitled' => __('The workspace does not have the app this step needs.'),
                'permission_missing', 'permission_scope' => __('The member this workflow runs as does not hold the permission this step needs.'),
                default => __('This step cannot run as the selected member.'),
            }, $id);
        }
    }

    private function actorKnown(?TenantMembership $actor): bool
    {
        return $actor !== null;
    }

    /**
     * Static input check: values that are templates are only known at run time and are checked then.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $input
     */
    private function checkInput(array $schema, array $input, string $id, ValidationReport $report, string $where): void
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists((string) $required, $input)) {
                $report->error('missing_input', __('Missing required field :field.', ['field' => (string) $required]), $id);
            }
        }

        foreach ($input as $key => $value) {
            $key = (string) $key;

            if (! isset($properties[$key]) && ($schema['additionalProperties'] ?? true) === false) {
                $report->error('unknown_input', __('Unknown field :field.', ['field' => $key]), $id);

                continue;
            }

            if (isset($properties[$key]) && is_array($properties[$key]) && ! $this->variables->isDynamic($value)) {
                foreach ($this->schemas->check($properties[$key], $value, $key) as $problem) {
                    $report->error('invalid_input', __('Invalid :where: :problem.', ['where' => $where, 'problem' => $problem]), $id);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $config */
    private function retry(array $config, string $id, ValidationReport $report): void
    {
        if (! isset($config['retry'])) {
            return;
        }

        $retry = $config['retry'];

        if (! is_array($retry)
            || ! is_int($retry['max_attempts'] ?? null) || $retry['max_attempts'] < 1 || $retry['max_attempts'] > Limits::int('max_attempts')
            || ! is_int($retry['backoff_seconds'] ?? 30) || ($retry['backoff_seconds'] ?? 30) < 1 || ($retry['backoff_seconds'] ?? 30) > Limits::int('max_backoff_seconds')) {
            $report->error('bad_retry', __('Retry needs 1 to :max attempts and a backoff in seconds.', ['max' => Limits::int('max_attempts')]), $id);
        }
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function conditionGroup(array $group, string $id, ValidationReport $report, int $depth, int &$count = 0): void
    {
        if ($depth > Limits::int('max_condition_depth')) {
            $report->error('condition_too_deep', __('Conditions are nested too deeply.'), $id);

            return;
        }

        if (! in_array($group['match'] ?? 'all', ['all', 'any'], true)) {
            $report->error('bad_match', __('A condition group matches all or any of its rules.'), $id);
        }

        $rules = $group['rules'] ?? [];

        if (! is_array($rules) || ! array_is_list($rules)) {
            $report->error('bad_rules', __('A condition group needs a list of rules.'), $id);

            return;
        }

        foreach ($rules as $rule) {
            if (++$count > Limits::int('max_condition_rules')) {
                $report->error('too_many_rules', __('Too many condition rules.'), $id);

                return;
            }

            if (! is_array($rule)) {
                $report->error('bad_rule', __('A condition rule is malformed.'), $id);

                continue;
            }

            if (isset($rule['rules'])) {
                $this->conditionGroup($rule, $id, $report, $depth + 1, $count);

                continue;
            }

            $operator = (string) ($rule['operator'] ?? '');
            $field = $rule['field'] ?? null;

            if (! is_string($field) || $field === '' || strlen($field) > 200) {
                $report->error('bad_field', __('A condition rule needs a field.'), $id);
            }

            if (! $this->operators->has($operator)) {
                $report->error('unknown_operator', __('Unknown condition operator.'), $id);

                continue;
            }

            $arity = $this->operators->arity($operator);

            if (in_array($arity, ['one', 'two', 'list'], true) && ! array_key_exists('value', $rule)) {
                $report->error('missing_value', __('A condition rule needs a value.'), $id);
            }

            if ($arity === 'two' && ! array_key_exists('value2', $rule)) {
                $report->error('missing_value', __('A condition rule needs a value.'), $id);
            }

            if ($arity === 'list' && isset($rule['value']) && ! is_array($rule['value']) && ! $this->variables->isDynamic($rule['value'])) {
                $report->error('bad_value', __('This operator needs a list of values.'), $id);
            }
        }
    }

    /** @param  array<string, mixed>  $config */
    private function branchNode(array $config, string $id, ValidationReport $report): void
    {
        $cases = $config['cases'] ?? null;

        if (! is_array($cases) || ! array_is_list($cases) || $cases === [] || count($cases) > 10) {
            $report->error('bad_cases', __('A branch needs 1 to 10 cases.'), $id);

            return;
        }

        $seen = [];

        foreach ($cases as $case) {
            $caseId = is_array($case) ? ($case['id'] ?? null) : null;

            if (! is_string($caseId) || preg_match('/^[a-z0-9_]{1,32}$/D', $caseId) !== 1 || $caseId === 'default' || isset($seen[$caseId])) {
                $report->error('bad_case_id', __('Each branch case needs a unique id.'), $id);

                continue;
            }

            $seen[$caseId] = true;
            $count = 0;
            $this->conditionGroup($case, $id, $report, 1, $count);
        }
    }

    /** @param  array<string, mixed>  $config */
    private function delayNode(array $config, string $id, ValidationReport $report): void
    {
        $amount = $config['amount'] ?? null;
        $unit = $config['unit'] ?? null;
        $minutes = is_int($amount) ? $amount * match ($unit) {
            'minutes' => 1, 'hours' => 60, 'days' => 1440, default => 0,
        } : 0;

        if ($minutes < 1 || $minutes > Limits::int('max_delay_days') * 1440) {
            $report->error('bad_delay', __('A delay is between one minute and :days days.', ['days' => Limits::int('max_delay_days')]), $id);
        }
    }

    /** @param  array<string, mixed>  $config */
    private function scheduleNode(array $config, string $id, ValidationReport $report): void
    {
        $mode = $config['mode'] ?? 'cron';

        if ($mode === 'cron') {
            $cron = $config['cron'] ?? null;

            if (! is_string($cron) || ! CronExpression::isValidExpression($cron)) {
                $report->error('bad_cron', __('The schedule is not a valid cron expression.'), $id);
            }
        } elseif ($mode === 'at') {
            if (! is_string($config['at'] ?? null) || $config['at'] === '') {
                $report->error('bad_at', __('Choose when the workflow should continue.'), $id);
            }
        } else {
            $report->error('bad_mode', __('A schedule waits for a cron time or a date.'), $id);
        }
    }

    /** @param  array<string, mixed>  $config */
    private function parallelNode(Graph $graph, array $config, string $id, ValidationReport $report): void
    {
        $branches = count($graph->outgoing($id));

        if ($branches < 2 || $branches > Limits::int('max_parallel_branches')) {
            $report->error('bad_branches', __('A parallel step needs 2 to :max branches.', ['max' => Limits::int('max_parallel_branches')]), $id);
        }

        $join = $config['join_node'] ?? null;

        if ($join !== null && (! is_string($join) || $graph->node($join) === null)) {
            $report->error('bad_join', __('The join node does not exist.'), $id);
        }
    }

    /** @param  array<string, mixed>  $config */
    private function approvalNode(array $config, string $id, ValidationReport $report): void
    {
        $title = $config['title'] ?? null;

        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 200) {
            $report->error('bad_title', __('An approval needs a title of up to 200 characters.'), $id);
        }

        $approver = is_array($config['approver'] ?? null) ? $config['approver'] : [];

        if (($approver['type'] ?? null) === 'permission') {
            $permission = (string) ($approver['permission'] ?? '');

            if ($this->modules->findPermission($permission) === null) {
                $report->error('bad_approver', __('The approver permission does not exist.'), $id);
            }
        } elseif (($approver['type'] ?? null) === 'membership') {
            if (! is_string($approver['membership_id'] ?? null) || $approver['membership_id'] === '') {
                $report->error('bad_approver', __('Choose who approves.'), $id);
            } elseif (TenantMembership::query()->whereKey($approver['membership_id'])->doesntExist()) {
                $report->error('bad_approver', __('The selected approver is not a member of this workspace.'), $id);
            }
        } else {
            $report->error('bad_approver', __('Choose who approves.'), $id);
        }

        $hours = $config['timeout_hours'] ?? Limits::int('approval_default_hours');

        if (! is_int($hours) || $hours < 1 || $hours > Limits::int('approval_max_hours')) {
            $report->error('bad_timeout', __('The approval timeout is 1 to :max hours.', ['max' => Limits::int('approval_max_hours')]), $id);
        }

        if (! in_array($config['on_timeout'] ?? 'reject', ['approve', 'reject', 'fail'], true)) {
            $report->error('bad_timeout', __('On timeout an approval is approved, rejected or fails.'), $id);
        }

        if (isset($config['prevent_self_approval']) && ! is_bool($config['prevent_self_approval'])) {
            $report->error('bad_flag', __('The self-approval setting must be on or off.'), $id);
        }
    }

    /** @param  array<string, mixed>  $config */
    private function webhookNode(array $config, string $id, ValidationReport $report): void
    {
        $endpoint = $config['endpoint_id'] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            $report->error('no_endpoint', __('Choose a webhook endpoint.'), $id);
        } else {
            $model = WebhookEndpoint::query()->find($endpoint);

            if ($model === null) {
                $report->error('unknown_endpoint', __('The webhook endpoint does not exist.'), $id);
            } elseif (! $model->is_active) {
                $report->warning('inactive_endpoint', __('The webhook endpoint is switched off.'), $id);
            }
        }

        if (isset($config['payload']) && ! is_array($config['payload'])) {
            $report->error('bad_payload', __('The webhook payload must be an object.'), $id);
        }

        $this->retry($config, $id, $report);
    }

    /** @param  array<string, mixed>  $config */
    private function transformNode(array $config, string $id, ValidationReport $report): void
    {
        $mappings = $config['mappings'] ?? null;

        if (! is_array($mappings) || ! array_is_list($mappings) || $mappings === [] || count($mappings) > Limits::int('max_mappings')) {
            $report->error('bad_mappings', __('A transformation needs 1 to :max mappings.', ['max' => Limits::int('max_mappings')]), $id);

            return;
        }

        $targets = [];

        foreach ($mappings as $mapping) {
            $target = is_array($mapping) ? ($mapping['target'] ?? null) : null;
            $operation = is_array($mapping) ? ($mapping['operation'] ?? null) : null;

            if (! is_string($target) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $target) !== 1) {
                $report->error('bad_target', __('A mapping target must be a plain name.'), $id);

                continue;
            }

            if (isset($targets[$target])) {
                $report->error('duplicate_target', __('Two mappings write the same name.'), $id);
            }

            $targets[$target] = true;

            if (! is_string($operation) || ! isset(Transformer::OPERATIONS[$operation])) {
                $report->error('unknown_operation', __('Unknown mapping operation.'), $id);
            }
        }
    }

    /** @param  array<string, mixed>  $config */
    private function loopNode(array $config, string $id, ValidationReport $report): void
    {
        $max = Limits::int('max_loop_iterations');
        $limit = $config['max_iterations'] ?? $max;

        if (! is_int($limit) || $limit < 1 || $limit > $max) {
            $report->error('bad_loop_limit', __('A loop runs at most :max times.', ['max' => $max]), $id);
        }

        if (isset($config['count'])) {
            if (! is_int($config['count']) || $config['count'] < 1 || $config['count'] > $max) {
                $report->error('bad_loop_count', __('A loop runs 1 to :max times.', ['max' => $max]), $id);
            }
        } elseif (! is_string($config['items'] ?? null) || $config['items'] === '') {
            $report->error('bad_loop_source', __('A loop needs a list to go through or a fixed count.'), $id);
        }
    }

    /** @param  array<string, mixed>  $config */
    private function errorHandlerNode(array $config, string $id, ValidationReport $report): void
    {
        if (! in_array($config['then'] ?? 'resume', ['resume', 'fail'], true)) {
            $report->error('bad_handler', __('After handling an error the workflow resumes or fails.'), $id);
        }
    }

    /** @param  array<string, mixed>  $config */
    private function endNode(array $config, string $id, ValidationReport $report): void
    {
        if (! in_array($config['status'] ?? 'success', ['success', 'failed'], true)) {
            $report->error('bad_end', __('An end node finishes the workflow as a success or a failure.'), $id);
        }
    }

    /**
     * Every `{{ path }}` and bare path must start from a known root, and `steps.<id>` must name a real node.
     *
     * @param  array{id: string, type: NodeType, label: string, config: array<string, mixed>, position: array{x: float|int, y: float|int}}  $node
     */
    private function references(Graph $graph, array $node, ValidationReport $report): void
    {
        $paths = $this->variables->references($node['config']);
        $config = $node['config'];

        foreach ($this->barePaths($node['type'], $config) as $path) {
            $paths[] = $path;
        }

        foreach (array_unique($paths) as $path) {
            $segments = explode('.', $path);

            if (! in_array($segments[0], VariableResolver::ROOTS, true)) {
                $report->error('bad_reference', __('Unknown variable :path.', ['path' => $path]), $node['id']);

                continue;
            }

            if ($segments[0] === 'steps' && (! isset($segments[1]) || $graph->node($segments[1]) === null || $segments[1] === $node['id'])) {
                $report->error('bad_reference', __('Unknown variable :path.', ['path' => $path]), $node['id']);
            }
        }
    }

    /**
     * Fields that hold a bare context path rather than a template.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function barePaths(NodeType $type, array $config): array
    {
        $paths = [];

        $collect = static function (mixed $value) use (&$paths): void {
            if (is_string($value) && $value !== '' && ! str_contains($value, '{{')
                && preg_match('/^'.VariableResolver::PATH.'$/D', $value) === 1) {
                $paths[] = $value;
            }
        };

        $walkRules = function (array $group) use (&$walkRules, $collect): void {
            foreach (is_array($group['rules'] ?? null) ? $group['rules'] : [] as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                if (isset($rule['rules'])) {
                    $walkRules($rule);
                } else {
                    $collect($rule['field'] ?? null);
                }
            }
        };

        match ($type) {
            NodeType::Condition => $walkRules($config),
            NodeType::Branch => array_map(static function (mixed $case) use ($walkRules): null {
                if (is_array($case)) {
                    $walkRules($case);
                }

                return null;
            }, is_array($config['cases'] ?? null) ? $config['cases'] : []),
            NodeType::Loop => $collect($config['items'] ?? null),
            NodeType::Transform => array_map(static function (mixed $mapping) use ($collect): null {
                if (is_array($mapping)) {
                    $collect($mapping['source'] ?? null);
                }

                return null;
            }, is_array($config['mappings'] ?? null) ? $config['mappings'] : []),
            default => null,
        };

        return $paths;
    }

    private function cycles(Graph $graph, ValidationReport $report): void
    {
        $adjacent = [];

        foreach ($graph->edges as $edge) {
            if (! $edge['loopBack'] && isset($graph->nodes[$edge['source']], $graph->nodes[$edge['target']])) {
                $adjacent[$edge['source']][] = $edge['target'];
            }
        }

        $state = [];

        $visit = function (string $id) use (&$visit, &$state, $adjacent, $report): bool {
            $state[$id] = 1;

            foreach ($adjacent[$id] ?? [] as $next) {
                if (($state[$next] ?? 0) === 1) {
                    $report->error('cycle', __('The workflow contains a cycle. Only a loop node may be returned to.'), $next);

                    return true;
                }

                if (($state[$next] ?? 0) === 0 && $visit($next)) {
                    return true;
                }
            }

            $state[$id] = 2;

            return false;
        };

        foreach (array_keys($graph->nodes) as $id) {
            if (($state[$id] ?? 0) === 0 && $visit($id)) {
                return;
            }
        }
    }

    private function loops(Graph $graph, ValidationReport $report): void
    {
        foreach ($graph->nodes as $node) {
            if ($node['type'] !== NodeType::Loop) {
                continue;
            }

            $id = $node['id'];
            $bodyEdges = $graph->outgoing($id, 'body');
            $back = array_values(array_filter($graph->edges, static fn (array $edge): bool => $edge['target'] === $id && $edge['loopBack']));

            if (count($bodyEdges) !== 1) {
                $report->error('loop_body', __('A loop needs exactly one body connection.'), $id);

                continue;
            }

            if ($back === []) {
                $report->error('loop_no_back', __('A loop needs a connection returning to it from the end of its body.'), $id);

                continue;
            }

            $body = $this->reach($graph, [$bodyEdges[0]['target']], $id);

            foreach ($back as $edge) {
                if (! in_array($edge['source'], $body, true)) {
                    $report->error('loop_back_outside', __('A loop-back must start inside the loop body.'), $edge['source'], $edge['id']);
                }
            }

            foreach ($body as $inside) {
                $type = $graph->node($inside)['type'] ?? null;

                if ($type === NodeType::Loop) {
                    $report->error('nested_loop', __('Loops cannot be nested.'), $inside);
                }

                if ($type === NodeType::Parallel) {
                    $report->error('parallel_in_loop', __('A parallel step cannot be inside a loop.'), $inside);
                }
            }
        }
    }

    /**
     * Nodes reachable from the start nodes without crossing a loop-back edge or the stop node.
     *
     * @param  list<string>  $start
     * @return list<string>
     */
    private function reach(Graph $graph, array $start, ?string $stop = null): array
    {
        $seen = [];
        $queue = $start;

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($seen[$id]) || $id === $stop || $graph->node($id) === null) {
                continue;
            }

            $seen[$id] = true;

            foreach ($graph->outgoingAll($id) as $edge) {
                if (! $edge['loopBack']) {
                    $queue[] = $edge['target'];
                }
            }
        }

        return array_keys($seen);
    }

    private function reachability(Graph $graph, ValidationReport $report): void
    {
        $trigger = $graph->trigger();

        if ($trigger === null) {
            return;
        }

        $roots = [$trigger['id']];

        if (($handler = $graph->globalErrorHandler()) !== null) {
            $roots[] = $handler;
        }

        $seen = [];
        $queue = $roots;

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($seen[$id]) || $graph->node($id) === null) {
                continue;
            }

            $seen[$id] = true;

            foreach ($graph->outgoingAll($id) as $edge) {
                $queue[] = $edge['target'];
            }
        }

        foreach ($graph->nodes as $node) {
            if (! isset($seen[$node['id']])) {
                $report->error('unreachable', __('This node cannot be reached from the trigger.'), $node['id']);
            }
        }

        foreach ($graph->nodes as $node) {
            if ($node['type'] !== NodeType::End && ! in_array($node['type'], [NodeType::Condition, NodeType::Branch, NodeType::Loop], true)
                && $graph->outgoingAll($node['id']) === [] && $node['type'] !== NodeType::ErrorHandler) {
                $report->warning('no_end', __('This step has nothing after it and ends the workflow.'), $node['id']);
            }
        }
    }
}
