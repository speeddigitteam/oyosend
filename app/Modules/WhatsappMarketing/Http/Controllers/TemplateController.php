<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\WhatsappMarketing\Actions\TemplateManager;
use App\Modules\WhatsappMarketing\Enums\TemplateCategory;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Approved-template management. Templates live on Meta's side; these rows mirror them. */
final class TemplateController
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly Presenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->presenter->actor($request);

        return Inertia::render('Modules/WhatsappMarketing/Pages/Templates/Index', [
            'templates' => Template::query()->orderBy('name')->orderBy('language')->limit(500)->get()
                ->map(fn (Template $template): array => $this->presenter->template($template))->all(),
            'categories' => TemplateCategory::values(),
            'statuses' => TemplateStatus::values(),
            'canManage' => $actor->can(Permissions::TEMPLATES_MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $template = $this->templates->save(null, $this->validated($request, creating: true), $this->presenter->membership($actor)->id);
        Toast::success(__('Template :name saved.', ['name' => $template->name]));

        return redirect()->route('whatsapp_marketing.templates.index');
    }

    public function update(Request $request, Template $template): RedirectResponse
    {
        $this->templates->save($template, $this->validated($request, creating: false));
        Toast::success(__('Template :name saved.', ['name' => $template->name]));

        return redirect()->route('whatsapp_marketing.templates.index');
    }

    public function status(Request $request, Template $template): RedirectResponse
    {
        $input = $request->validate([
            'status' => ['required', Rule::enum(TemplateStatus::class)],
            'status_reason' => ['nullable', 'string', 'max:300'],
        ]);

        $this->templates->recordStatus($template, TemplateStatus::from($input['status']), $input['status_reason'] ?? null);
        Toast::success(__('Status recorded as :status by an administrator.', ['status' => $input['status']]));

        return back();
    }

    public function destroy(Template $template): RedirectResponse
    {
        $this->templates->delete($template);
        Toast::success(__('Template deleted.'));

        return redirect()->route('whatsapp_marketing.templates.index');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'prohibited', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
            'language' => [$creating ? 'required' : 'prohibited', 'string', 'regex:/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/'],
            'category' => ['required', Rule::enum(TemplateCategory::class)],
            'header' => ['nullable', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:1024'],
            'footer' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:3'],
            'buttons.*.type' => ['required', Rule::in(['quick_reply', 'url', 'phone_number'])],
            'buttons.*.text' => ['required', 'string', 'max:25'],
            'buttons.*.value' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::enum(TemplateStatus::class)],
            'status_reason' => ['nullable', 'string', 'max:300'],
        ]);
    }
}
