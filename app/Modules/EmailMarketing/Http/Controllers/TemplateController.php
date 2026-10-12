<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\EmailMarketing\Actions\TemplateManager;
use App\Modules\EmailMarketing\Models\Template;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TemplateController
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly EmailMarketingSupport $support,
        private readonly EmailMarketingPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/EmailMarketing/Pages/Templates/Index', [
            'templates' => Template::query()->orderBy('name')->paginate(25)
                ->through(fn (Template $template): array => $this->presenter->template($template, false)),
            'canManage' => $actor->can('email_marketing.templates.manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->support->actor($request);

        return Inertia::render('Modules/EmailMarketing/Pages/Templates/Form', [
            'template' => null,
            'editor' => $this->support->editorOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->support->actor($request);
        $this->templates->create($this->input($request));
        Toast::success(__('Template saved.'));

        return redirect()->route('email_marketing.templates.index');
    }

    public function edit(Request $request, string $template): Response
    {
        $this->support->actor($request);

        return Inertia::render('Modules/EmailMarketing/Pages/Templates/Form', [
            'template' => $this->presenter->template(Template::query()->findOrFail($template)),
            'editor' => $this->support->editorOptions(),
        ]);
    }

    public function update(Request $request, string $template): RedirectResponse
    {
        $this->support->actor($request);
        $this->templates->update(Template::query()->findOrFail($template), $this->input($request));
        Toast::success(__('Template saved.'));

        return redirect()->route('email_marketing.templates.index');
    }

    public function destroy(Request $request, string $template): RedirectResponse
    {
        $this->support->actor($request);
        $this->templates->delete(Template::query()->findOrFail($template));
        Toast::success(__('Template deleted.'));

        return redirect()->route('email_marketing.templates.index');
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'blocks' => ['required', 'array', 'min:1', 'max:50'],
            'blocks.*' => ['array'],
        ]);
    }
}
