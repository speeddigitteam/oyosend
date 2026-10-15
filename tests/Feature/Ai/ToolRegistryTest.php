<?php

declare(strict_types=1);

use App\Core\Ai\Contracts\AiTool;
use App\Core\Ai\Contracts\ProposalTool;
use App\Core\Ai\ToolContext;
use App\Core\Ai\ToolOutput;
use App\Modules\Ai\Actions\ToolRegistry;
use App\Modules\Ai\Tools\ContactGetTool;
use Tests\Support\InteractsWithAi;

uses(InteractsWithAi::class);

/** A tool that would touch money; the registry must never accept it. */
final class PayInvoiceToolStub implements AiTool
{
    public static function name(): string
    {
        return 'invoices_record_payment';
    }

    public static function dataScope(): string
    {
        return 'contacts';
    }

    public static function permission(): string
    {
        return 'invoicing.payments.record';
    }

    public static function description(): string
    {
        return 'Pays';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(ToolContext $context, array $input): ToolOutput
    {
        return new ToolOutput([]);
    }
}

/** A tool that would change who can do what. */
final class GrantRoleToolStub implements AiTool
{
    public static function name(): string
    {
        return 'grant_access';
    }

    public static function dataScope(): string
    {
        return 'contacts';
    }

    public static function permission(): string
    {
        return 'core.roles.manage';
    }

    public static function description(): string
    {
        return 'Grants';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(ToolContext $context, array $input): ToolOutput
    {
        return new ToolOutput([]);
    }
}

it('registers the built-in tools and exposes only proposing tools for changes', function () {
    $tools = app(ToolRegistry::class)->all();

    expect(array_keys($tools))->toBe([
        'contacts_search', 'contacts_get', 'companies_search', 'companies_get',
        'contacts_propose_create', 'contacts_propose_update',
        // WhatsApp's read-only conversation tool, behind its opt-in data scope.
        'whatsapp_conversation_get',
    ]);

    foreach ($tools as $name => $tool) {
        // Every tool that can change data is a ProposalTool and says "propose" in its name.
        expect($tool instanceof ProposalTool)->toBe(str_contains($name, 'propose'));
    }
});

it('refuses a tool that touches money', function () {
    app()->tag([PayInvoiceToolStub::class], AiTool::TAG);

    expect(fn () => app(ToolRegistry::class)->all())->toThrow(LogicException::class, 'money or access control');
});

it('refuses a tool that touches permissions', function () {
    app()->tag([GrantRoleToolStub::class], AiTool::TAG);

    expect(fn () => app(ToolRegistry::class)->all())->toThrow(LogicException::class, 'money or access control');
});

it('rejects a duplicate tool name', function () {
    app()->tag([ContactGetTool::class, ContactGetTool::class], AiTool::TAG);

    expect(fn () => app(ToolRegistry::class)->all())->toThrow(LogicException::class, 'Duplicate');
});

it('lists only the tools of a permission the member holds, in an entitled app', function () {
    $tenant = $this->aiTenant('ai-reg');
    [$viewer] = $this->aiMember($tenant, ['ai.assistant.use', 'core.contacts.view']);
    [$manager] = $this->aiMember($tenant, ['ai.assistant.use', 'core.contacts.view', 'core.contacts.manage']);

    $names = fn ($user): array => $this->inTenant($tenant, fn () => array_keys(app(ToolRegistry::class)->availableFor($user)));

    expect($names($viewer))->toBe(['contacts_search', 'contacts_get', 'companies_search', 'companies_get'])
        ->and($names($manager))->toContain('contacts_propose_create', 'contacts_propose_update');
});
