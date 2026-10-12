<?php

declare(strict_types=1);

use App\Modules\Communication\Actions\TemplateRenderer;
use App\Modules\Communication\Actions\TemplateVariables;

beforeEach(function () {
    $this->variables = new TemplateVariables;
    $this->renderer = new TemplateRenderer($this->variables);
});

it('replaces only allow-listed placeholders', function () {
    $out = $this->renderer->render('Hi {{ contact.first_name }}, from {{workspace.name}}.', [
        'contact.first_name' => 'Rahim',
        'workspace.name' => 'Acme',
    ]);

    expect($out)->toBe('Hi Rahim, from Acme.');
});

it('does not evaluate code, filters or expressions', function () {
    $text = "{{ system('id') }} {{ 7*7 }} {{ contact.first_name|upper }} <?php echo 1; ?> {!! \$x !!} @php(die())";
    $out = $this->renderer->render($text, ['contact.first_name' => 'Rahim']);

    // Unknown tokens vanish, everything else is returned as the literal text it was.
    expect($out)->not->toContain('uid=')
        ->and($out)->not->toContain('49')
        ->and($out)->toContain('<?php echo 1; ?>')
        ->and($out)->toContain('@php(die())');
    expect($this->renderer->unknownTokens($text))->toBe(["system('id')", '7*7', 'contact.first_name|upper']);
});

it('never expands a placeholder that appears inside a value', function () {
    $out = $this->renderer->render('{{ contact.first_name }}', [
        'contact.first_name' => '{{ contact.email }}',
        'contact.email' => 'secret@example.test',
    ]);

    expect($out)->toBe('{{ contact.email }}');
});

it('strips control characters and caps the value length', function () {
    $out = $this->renderer->render('{{ contact.first_name }}', ['contact.first_name' => "A\x00B\x07".str_repeat('x', 900)]);

    expect($out)->not->toContain("\x00")->and(mb_strlen($out))->toBe(500);
});

it('accepts placeholders other modules register and rejects malformed names', function () {
    $this->variables->extend(['invoice.number' => 'Invoice number']);

    expect($this->renderer->unknownTokens('{{ invoice.number }}'))->toBe([])
        ->and(fn () => $this->variables->extend(['bad key' => 'x']))->toThrow(InvalidArgumentException::class);
});
