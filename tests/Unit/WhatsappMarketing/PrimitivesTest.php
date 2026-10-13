<?php

declare(strict_types=1);

use App\Modules\WhatsappMarketing\Actions\FlowDefinition;
use App\Modules\WhatsappMarketing\Actions\TemplateParameters;
use App\Modules\WhatsappMarketing\Actions\VariableResolver;
use App\Modules\WhatsappMarketing\Support\Phone;
use App\Modules\WhatsappMarketing\Support\Text;
use Illuminate\Validation\ValidationException;

it('reads a WhatsApp number in the one canonical form and guesses nothing else', function (?string $raw, ?string $expected) {
    expect(Phone::e164($raw))->toBe($expected);
})->with([
    ['+8801711000001', '+8801711000001'],
    ['+880 1711-000001', '+8801711000001'],
    ['008801711000001', '+8801711000001'],
    ['(+880) 1711 000 001', '+8801711000001'],
    ['01711000001', '+8801711000001'],
    ['1711000001', '+8801711000001'],
    ['8801711000001', '+8801711000001'],
    ['0212345678', null],
    ['4155550100', null],
    ['+0123456789', null],
    ['+12345', null],
    ['+1234567890123456', null],
    ['not a number', null],
    ['', null],
    [null, null],
]);

it('masks all but the last four digits of a number', function () {
    expect(Phone::masked('+8801711000001'))->toEndWith('0001')->and(Phone::masked('+8801711000001'))->not->toContain('1711');
});

it('cleans untrusted text: valid UTF-8, no control characters, capped', function () {
    expect(Text::clean("a\x00b\x07c", 10))->toBe('abc')
        ->and(Text::clean("line one\r\nline two\tend", 100))->toBe("line one\nline two\tend")
        ->and(Text::clean("  spaced \n out  ", 100, false))->toBe('spaced out')
        ->and(mb_strlen(Text::clean(str_repeat('বাংলা', 1000), 50)))->toBe(50)
        ->and(Text::clean("bad \xC3\x28 bytes", 100))->toBeString()
        ->and(mb_strlen(Text::preview(str_repeat('x', 300), 40)))->toBe(40);
});

it('reads positional template variables and substitutes them without evaluating anything', function () {
    expect(TemplateParameters::positions('Hi {{1}}, {{ 2 }} and {{1}} again'))->toBe([1, 2])
        ->and(TemplateParameters::isSequential([1, 2, 3]))->toBeTrue()
        ->and(TemplateParameters::isSequential([1, 3]))->toBeFalse()
        ->and(TemplateParameters::isSequential([]))->toBeTrue()
        ->and(TemplateParameters::render('Hi {{1}}, order {{2}}.', ['Rahim', '#42']))->toBe('Hi Rahim, order #42.')
        // A value that looks like a variable is not expanded a second time.
        ->and(TemplateParameters::render('Hi {{1}} {{2}}', ['{{2}}', 'x']))->toBe('Hi {{2}} x');
});

it('maps variables only from allow-listed fields or fixed text', function () {
    $variables = new VariableResolver;
    $map = $variables->normalizeMap([
        1 => ['type' => 'field', 'field' => 'contact.first_name'],
        2 => ['type' => 'text', 'value' => "  Dhaka\n"],
    ], 2);

    expect($map)->toBe([
        1 => ['type' => 'field', 'field' => 'contact.first_name'],
        2 => ['type' => 'text', 'value' => 'Dhaka'],
    ]);

    $values = ['contact.first_name' => 'Rahim'] + array_fill_keys(VariableResolver::FIELDS, '');

    expect($variables->parameters($map, $values))->toBe(['Rahim', 'Dhaka'])
        ->and(fn () => $variables->normalizeMap([1 => ['type' => 'field', 'field' => 'contact.password']], 1))->toThrow(ValidationException::class)
        ->and(fn () => $variables->normalizeMap([1 => ['type' => 'text', 'value' => '  ']], 1))->toThrow(ValidationException::class)
        ->and(fn () => $variables->normalizeMap([], 1))->toThrow(ValidationException::class);
});

it('substitutes placeholders in one pass and leaves unknown ones untouched', function () {
    $variables = new VariableResolver;
    $values = array_fill_keys(VariableResolver::FIELDS, '');
    $values['contact.first_name'] = '{{contact.last_name}}';
    $values['contact.last_name'] = 'SHOULD-NOT-APPEAR';

    expect($variables->render('Hi {{ contact.first_name }} {{user.token}}', $values))->toBe('Hi {{contact.last_name}} {{user.token}}')
        ->and($variables->unknownPlaceholders('{{contact.first_name}} {{user.token}} {{x.y}}'))->toBe(['user.token', 'x.y']);
});

it('normalises keywords and messages the same way', function () {
    expect(FlowDefinition::normalize("  PRICE\n list  "))->toBe('price list')
        ->and(FlowDefinition::normalize('বন্ধ'))->toBe('বন্ধ');

    $definition = new FlowDefinition(new VariableResolver);

    expect($definition->keywords(['Price', ' price ', 'PLANS']))->toBe(['price', 'plans'])
        ->and(fn () => $definition->keywords([]))->toThrow(ValidationException::class)
        ->and(fn () => $definition->keywords([str_repeat('a', 41)]))->toThrow(ValidationException::class)
        ->and(fn () => $definition->keywords(array_map(fn (int $i): string => "k{$i}", range(1, 11))))->toThrow(ValidationException::class);
});

it('accepts only the closed step vocabulary and bounds every list', function () {
    $definition = new FlowDefinition(new VariableResolver);

    expect($definition->actions([['type' => 'reply', 'text' => 'Hi {{contact.first_name}}'], ['type' => 'tag', 'tag' => 'Sales'], ['type' => 'handoff']]))
        ->toBe([['type' => 'reply', 'text' => 'Hi {{contact.first_name}}'], ['type' => 'tag', 'tag' => 'sales'], ['type' => 'handoff']]);

    foreach ([
        [['type' => 'http', 'url' => 'https://example.test']],
        [['type' => 'reply', 'text' => '']],
        [['type' => 'reply', 'text' => 'Hi {{user.password}}']],
        [['type' => 'tag', 'tag' => 'Has Space']],
        [['type' => 'menu', 'prompt' => 'Q', 'options' => []]],
        [['type' => 'menu', 'prompt' => 'Q', 'options' => [['label' => 'A', 'actions' => [['type' => 'handoff']]], ['label' => 'a', 'actions' => [['type' => 'handoff']]]]]],
        array_fill(0, 11, ['type' => 'handoff']),
        [],
        ['not an array'],
    ] as $steps) {
        expect(fn () => $definition->actions($steps))->toThrow(ValidationException::class);
    }
});
