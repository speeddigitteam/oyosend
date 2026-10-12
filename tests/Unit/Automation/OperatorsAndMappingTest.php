<?php

declare(strict_types=1);

use App\Modules\Automation\Engine\ConditionEvaluator;
use App\Modules\Automation\Engine\CronSchedule;
use App\Modules\Automation\Engine\OperatorRegistry;
use App\Modules\Automation\Engine\Redactor;
use App\Modules\Automation\Engine\SchemaChecker;
use App\Modules\Automation\Engine\Transformer;
use App\Modules\Automation\Engine\VariableResolver;
use App\Modules\Automation\Graph\Graph;
use App\Modules\Automation\Graph\GraphHasher;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->ops = new OperatorRegistry;
    $this->vars = new VariableResolver;
    $this->conditions = new ConditionEvaluator($this->ops, $this->vars);
});

it('evaluates the typed operators', function (string $operator, mixed $left, mixed $right, bool $expected, mixed $upper = null) {
    expect($this->ops->evaluate($operator, $left, $right, true, $upper))->toBe($expected);
})->with([
    ['equals', 5, '5', true],
    ['equals', 'a', 'b', false],
    ['equals', true, 'yes', true],
    ['not_equals', 'a', 'b', true],
    ['gt', 10, 9, true],
    ['gt', '10', 'abc', false],
    ['gte', 10, 10, true],
    ['lt', 1, 2, true],
    ['lte', 3, 2, false],
    ['between', 5, 1, true, 10],
    ['between', '2026-03-05', '2026-03-01', true, '2026-03-31'],
    ['between', 50, 1, false, 10],
    ['contains', 'hello world', 'lo w', true],
    ['contains', ['a', 'b'], 'b', true],
    ['not_contains', 'hello', 'z', true],
    ['starts_with', 'invoice-1', 'invoice', true],
    ['ends_with', 'invoice-1', '-2', false],
    ['in', 'b', ['a', 'b'], true],
    ['not_in', 'c', ['a', 'b'], true],
    ['is_empty', '', null, true],
    ['is_empty', [], null, true],
    ['is_not_empty', 'x', null, true],
    ['exists', 0, null, true],
    ['not_exists', null, null, true],
    ['is_true', 'true', null, true],
    ['is_false', 0, null, true],
    ['before', '2026-01-01', '2026-02-01', true],
    ['after', '2026-01-01', '2026-02-01', false],
]);

it('treats a missing field differently from an empty one', function () {
    expect($this->ops->evaluate('exists', null, null, false))->toBeFalse()
        ->and($this->ops->evaluate('not_exists', null, null, false))->toBeTrue()
        ->and($this->ops->evaluate('equals', null, null, false))->toBeFalse();
});

it('checks dates relative to now', function () {
    CarbonImmutable::setTestNow('2026-06-15 12:00:00');

    expect($this->ops->evaluate('within_last_days', '2026-06-10', 7))->toBeTrue()
        ->and($this->ops->evaluate('within_last_days', '2026-05-01', 7))->toBeFalse()
        ->and($this->ops->evaluate('within_next_days', '2026-06-20', 7))->toBeTrue()
        ->and($this->ops->evaluate('within_next_days', '2026-06-10', 7))->toBeFalse();

    CarbonImmutable::setTestNow();
});

it('has no operator that evaluates code', function () {
    $keys = array_keys($this->ops->definitions());

    expect($keys)->not->toContain('eval', 'matches', 'regex', 'expression', 'js')
        ->and(fn () => $this->ops->evaluate('eval', 1, 1))->toThrow(InvalidArgumentException::class);
});

it('evaluates nested groups with all and any', function () {
    $context = ['trigger' => ['amount' => 500, 'country' => 'BD', 'tags' => ['vip']]];

    $group = [
        'match' => 'all',
        'rules' => [
            ['field' => 'trigger.amount', 'operator' => 'gte', 'value' => 100],
            ['match' => 'any', 'rules' => [
                ['field' => 'trigger.country', 'operator' => 'equals', 'value' => 'IN'],
                ['field' => 'trigger.tags', 'operator' => 'contains', 'value' => 'vip'],
            ]],
        ],
    ];

    expect($this->conditions->passes($group, $context))->toBeTrue();

    $group['rules'][1]['rules'][1]['value'] = 'basic';
    expect($this->conditions->passes($group, $context))->toBeFalse()
        ->and($this->conditions->passes(['match' => 'all', 'rules' => []], $context))->toBeTrue();
});

it('resolves dynamic variables inside rule values', function () {
    $context = ['trigger' => ['amount' => 500], 'vars' => ['limit' => 300]];

    expect($this->conditions->passes(['rules' => [['field' => 'trigger.amount', 'operator' => 'gt', 'value' => '{{ vars.limit }}']]], $context))->toBeTrue();
});

it('fills templates from the context and keeps types for whole-value templates', function () {
    $context = ['trigger' => ['amount' => 500, 'name' => 'Rima', 'tags' => ['a'], 'ok' => true]];

    expect($this->vars->resolve('{{ trigger.amount }}', $context))->toBe(500)
        ->and($this->vars->resolve('Hello {{trigger.name}}!', $context))->toBe('Hello Rima!')
        ->and($this->vars->resolve('{{ trigger.tags }}', $context))->toBe(['a'])
        ->and($this->vars->resolve('Is {{ trigger.ok }}', $context))->toBe('Is true')
        ->and($this->vars->resolve('{{ trigger.missing }}', $context))->toBeNull()
        ->and($this->vars->resolve(['x' => ['y' => '{{ trigger.name }}']], $context))->toBe(['x' => ['y' => 'Rima']])
        ->and($this->vars->references(['a' => '{{ trigger.name }} {{ steps.n1.id }}']))->toBe(['trigger.name', 'steps.n1.id']);
});

it('does not evaluate anything inside a template', function () {
    $context = ['trigger' => ['a' => 2]];

    // Not a path: left exactly as written.
    expect($this->vars->resolve('{{ 1 + 1 }}', $context))->toBe('{{ 1 + 1 }}')
        ->and($this->vars->resolve('{{ system("id") }}', $context))->toBe('{{ system("id") }}')
        ->and($this->vars->resolve('{{ trigger.a * 2 }}', $context))->toBe('{{ trigger.a * 2 }}');
});

it('transforms with the fixed mapping operations only', function () {
    $transformer = new Transformer($this->vars);
    $context = ['trigger' => ['name' => '  Rima ', 'a' => 40, 'b' => 2, 'list' => [7, 8], 'blank' => '']];

    $out = $transformer->apply([
        ['target' => 'trimmed', 'operation' => 'trim', 'source' => 'trigger.name'],
        ['target' => 'shout', 'operation' => 'uppercase', 'source' => 'vars.trimmed'],
        ['target' => 'sum', 'operation' => 'add', 'values' => ['trigger.a', 'trigger.b']],
        ['target' => 'diff', 'operation' => 'subtract', 'values' => ['trigger.a', 'trigger.b']],
        ['target' => 'prod', 'operation' => 'multiply', 'values' => ['trigger.a', 3]],
        ['target' => 'first', 'operation' => 'first', 'source' => 'trigger.list'],
        ['target' => 'count', 'operation' => 'length', 'source' => 'trigger.list'],
        ['target' => 'fallback', 'operation' => 'default', 'source' => 'trigger.blank', 'value' => 'n/a'],
        ['target' => 'label', 'operation' => 'concat', 'values' => ['A-', 'trigger.a']],
        ['target' => 'fixed', 'operation' => 'literal', 'value' => 'const'],
    ], $context);

    expect($out)->toBe([
        'trimmed' => 'Rima', 'shout' => 'RIMA', 'sum' => 42, 'diff' => 38, 'prod' => 120, 'first' => 7,
        'count' => 2, 'fallback' => 'n/a', 'label' => 'A-40', 'fixed' => 'const',
    ]);
});

it('refuses unknown operations, float arithmetic and overflow', function () {
    $transformer = new Transformer($this->vars);

    expect(fn () => $transformer->apply([['target' => 'x', 'operation' => 'eval', 'value' => '1+1']], []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $transformer->apply([['target' => 'x', 'operation' => 'add', 'values' => [1.5, 2]]], []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $transformer->apply([['target' => 'x', 'operation' => 'multiply', 'values' => [PHP_INT_MAX, 2]]], []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $transformer->apply([['target' => 'bad name', 'operation' => 'copy', 'source' => 'trigger.a']], []))->toThrow(InvalidArgumentException::class);
});

it('checks input against the contract subset of JSON Schema', function () {
    $schema = ['type' => 'object', 'required' => ['title'], 'properties' => [
        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5],
        'priority' => ['type' => 'string', 'enum' => ['low', 'high']],
        'minutes' => ['type' => 'integer', 'minimum' => 0],
        'due' => ['type' => ['string', 'null'], 'format' => 'date-time'],
    ], 'additionalProperties' => false];
    $checker = new SchemaChecker;

    expect($checker->check($schema, ['title' => 'ok', 'priority' => 'low', 'minutes' => 5, 'due' => null]))->toBe([])
        ->and($checker->check($schema, []))->toContain('title is required')
        ->and($checker->check($schema, ['title' => 'toolong']))->not->toBe([])
        ->and($checker->check($schema, ['title' => 'a', 'priority' => 'mid']))->not->toBe([])
        ->and($checker->check($schema, ['title' => 'a', 'minutes' => -1]))->not->toBe([])
        ->and($checker->check($schema, ['title' => 'a', 'extra' => 1]))->toContain('extra is not allowed')
        ->and($checker->check($schema, ['title' => 'a', 'due' => 'nope']))->not->toBe([])
        ->and($checker->check($schema, ['title' => 1]))->not->toBe([]);
});

it('computes the next cron time in a timezone as a UTC instant', function () {
    $after = CarbonImmutable::parse('2026-03-10 20:00:00', 'UTC'); // 02:00 on the 11th in Dhaka

    expect(CronSchedule::next('0 9 * * *', 'Asia/Dhaka', $after)?->format('Y-m-d H:i'))->toBe('2026-03-11 03:00')
        ->and(CronSchedule::next('*/15 * * * *', 'UTC', CarbonImmutable::parse('2026-03-10 10:07:00'))?->format('H:i'))->toBe('10:15')
        ->and(CronSchedule::next('not cron', 'UTC', $after))->toBeNull()
        ->and(CronSchedule::isValid('0 9 * * 1-5'))->toBeTrue()
        ->and(CronSchedule::isValid('99 * * * *'))->toBeFalse();
});

it('hashes a graph independently of key order and sensitive to content', function () {
    $a = ['nodes' => [['id' => 'n1', 'type' => 'end', 'data' => ['label' => '', 'config' => ['b' => 1, 'a' => 2]]]], 'edges' => []];
    $b = ['edges' => [], 'nodes' => [['data' => ['config' => ['a' => 2, 'b' => 1], 'label' => ''], 'type' => 'end', 'id' => 'n1']]];
    $c = $a;
    $c['nodes'][0]['data']['config']['a'] = 3;

    expect(GraphHasher::hash($a))->toBe(GraphHasher::hash($b))->not->toBe(GraphHasher::hash($c))->toHaveLength(64);
});

it('parses the wire format, dropping unknown keys and reporting malformed parts', function () {
    [$graph, $problems] = Graph::parse([
        'nodes' => [
            ['id' => 'n1', 'type' => 'trigger', 'position' => ['x' => '5', 'y' => 7], 'data' => ['label' => 'Start', 'config' => ['x' => 1], 'extra' => 'dropped'], 'evil' => 'dropped'],
            ['id' => 'n1', 'type' => 'end'],
            ['id' => 'bad id!', 'type' => 'end'],
            ['id' => 'n3', 'type' => 'run_php'],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 'n1', 'target' => 'n2', 'sourceHandle' => '', 'data' => ['loopBack' => true, 'x' => 1]],
            ['id' => 'e2', 'source' => 'n1'],
        ],
    ]);

    expect($problems)->toHaveCount(4)
        ->and($graph->nodes)->toHaveCount(1)
        ->and($graph->toArray()['nodes'][0])->toBe(['id' => 'n1', 'type' => 'trigger', 'position' => ['x' => 5, 'y' => 7], 'data' => ['label' => 'Start', 'config' => ['x' => 1]]])
        ->and($graph->edges[0]['handle'])->toBeNull()->and($graph->edges[0]['loopBack'])->toBeTrue();
});

it('masks secret-looking keys and cuts long strings', function () {
    $clean = (new Redactor)->redact([
        'name' => 'Rima', 'Password' => 'x', 'client_secret' => 'y', 'headers' => ['Authorization' => 'Bearer z'],
        'long' => str_repeat('é', 5000), 'object' => new stdClass,
    ]);

    expect($clean['name'])->toBe('Rima')
        ->and($clean['Password'])->toBe('[redacted]')
        ->and($clean['client_secret'])->toBe('[redacted]')
        ->and($clean['headers']['Authorization'])->toBe('[redacted]')
        ->and(mb_strlen($clean['long']))->toBeLessThanOrEqual(2003)
        ->and($clean['object'])->toBe('[unsupported]');
});
