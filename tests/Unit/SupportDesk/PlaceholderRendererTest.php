<?php

declare(strict_types=1);

use App\Modules\Support\Support\PlaceholderRenderer;

it('fills only the allow-listed placeholders', function () {
    $text = 'Hi {{ requester.name }}, ticket {{ticket.number}} ({{ ticket.subject }}) - {{agent.name}} at {{workspace.name}}';

    expect(PlaceholderRenderer::render($text, [
        'requester.name' => 'Nadia',
        'ticket.number' => 'TKT-00007',
        'ticket.subject' => 'Login',
        'agent.name' => 'Rafi',
        'workspace.name' => 'Acme',
    ]))->toBe('Hi Nadia, ticket TKT-00007 (Login) - Rafi at Acme');
});

it('never evaluates anything and does not expand values again', function () {
    $out = PlaceholderRenderer::render('{{ requester.name }} / {{ system.env }} / {{ 7*7 }}', [
        'requester.name' => '{{ agent.name }}',
        'agent.name' => 'SECRET',
        'system.env' => 'leak',
    ]);

    expect($out)->toBe('{{ agent.name }} / {{ system.env }} / {{ 7*7 }}');
});

it('lists unknown and malformed placeholders so a response cannot be saved with them', function () {
    expect(PlaceholderRenderer::unknown('{{ ticket.number }} {{ order.total }} {{ }} {{user.password}}'))
        ->toBe(['order.total', '', 'user.password'])
        ->and(PlaceholderRenderer::hasMalformed('Hello {{ ticket.number'))->toBeTrue()
        ->and(PlaceholderRenderer::hasMalformed('Hello {{ ticket.number }}'))->toBeFalse();
});

it('renders a missing value as an empty string', function () {
    expect(PlaceholderRenderer::render('Dear {{ requester.name }},', ['requester.name' => null]))->toBe('Dear ,');
});
