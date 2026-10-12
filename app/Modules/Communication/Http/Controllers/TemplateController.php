<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Communication\Actions\TemplateManager;
use App\Modules\Communication\Actions\TemplateRenderer;
use App\Modules\Communication\Actions\TemplateVariables;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Http\Requests\TemplatePreviewRequest;
use App\Modules\Communication\Http\Requests\TemplateRequest;
use App\Modules\Communication\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Message templates. Text with allow-listed placeholders; never executed. */
final class TemplateController
{
    public const string VIEW = 'communication.templates.view';

    public const string MANAGE = 'communication.templates.manage';

    public function __construct(
        private readonly TemplateManager $templates,
        private readonly TemplateRenderer $renderer,
        private readonly TemplateVariables $variables,
        private readonly CommunicationControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/Communication/Pages/Templates/Index', [
            'templates' => MessageTemplate::query()->orderBy('name')->orderBy('channel')->orderBy('locale')->get()
                ->map(fn (MessageTemplate $template): array => $this->present($template))->all(),
            'variables' => $this->variables->catalogue(),
            'channels' => Channel::values(),
            'locales' => TemplateManager::LOCALES,
            'purposes' => array_map(static fn (MessagePurpose $purpose): string => $purpose->value, MessagePurpose::cases()),
            'canManage' => $actor->can(self::MANAGE),
        ]);
    }

    public function store(TemplateRequest $request): RedirectResponse
    {
        $template = $this->templates->save(null, $request->validated());
        Toast::success(__('Template ":name" created.', ['name' => $template->name]));

        return back();
    }

    public function update(TemplateRequest $request, MessageTemplate $template): RedirectResponse
    {
        $this->templates->save($template, $request->validated());
        Toast::success(__('Template saved.'));

        return back();
    }

    public function destroy(MessageTemplate $template): RedirectResponse
    {
        $template->delete();
        Toast::success(__('Template deleted.'));

        return back();
    }

    /** Renders a draft with made-up sample values, so a template can be checked before it is saved. */
    public function preview(TemplatePreviewRequest $request): JsonResponse
    {
        $input = $request->validated();
        $sample = [
            'contact.first_name' => 'Rahim',
            'contact.last_name' => 'Uddin',
            'contact.full_name' => 'Rahim Uddin',
            'contact.email' => 'rahim@example.com',
            'contact.phone' => '+8801700000000',
            'company.name' => 'Example Ltd',
            'workspace.name' => 'Your workspace',
            'sender.name' => 'Your workspace',
        ];
        $subject = (string) ($input['subject'] ?? '');
        $unknown = array_values(array_unique([
            ...$this->renderer->unknownTokens($subject),
            ...$this->renderer->unknownTokens((string) $input['body']),
        ]));

        return response()->json([
            'subject' => $this->renderer->render($subject, $sample),
            'body' => $this->renderer->render((string) $input['body'], $sample),
            'unknown' => $unknown,
        ]);
    }

    /** @return array<string, mixed> */
    private function present(MessageTemplate $template): array
    {
        return [
            'id' => $template->id,
            'slug' => $template->slug,
            'name' => $template->name,
            'channel' => $template->channel->value,
            'locale' => $template->locale,
            'purpose' => $template->purpose->value,
            'subject' => $template->subject,
            'body' => $template->body,
            'isActive' => $template->is_active,
        ];
    }
}
