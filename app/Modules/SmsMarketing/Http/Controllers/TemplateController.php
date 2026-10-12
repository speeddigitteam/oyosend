<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\SmsMarketing\Actions\MessageComposer;
use App\Modules\SmsMarketing\Actions\TemplateManager;
use App\Modules\SmsMarketing\Enums\TemplatePurpose;
use App\Modules\SmsMarketing\Http\Requests\PreviewRequest;
use App\Modules\SmsMarketing\Http\Requests\TemplateRequest;
use App\Modules\SmsMarketing\Models\Template;
use App\Modules\SmsMarketing\Support\TemplateVariables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TemplateController
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly MessageComposer $composer,
        private readonly SmsSupport $support,
        private readonly SmsPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/SmsMarketing/Pages/Templates/Index', [
            'templates' => Template::query()->orderBy('name')->get()->map(fn (Template $template): array => $this->presenter->template($template))->all(),
            'variables' => [
                'marketing' => TemplateVariables::allowed(TemplatePurpose::Marketing),
                'transactional' => TemplateVariables::allowed(TemplatePurpose::Transactional),
            ],
            'purposes' => TemplatePurpose::values(),
            'maxBody' => (int) config('sms_marketing.max_body_length', 1000),
            'canManage' => $actor->can('sms_marketing.templates.manage'),
        ]);
    }

    public function store(TemplateRequest $request): RedirectResponse
    {
        $this->support->actor($request);
        $this->templates->create($request->validated());
        Toast::success(__('Template saved.'));

        return back();
    }

    public function update(TemplateRequest $request, string $template): RedirectResponse
    {
        $this->support->actor($request);
        $this->templates->update(Template::query()->findOrFail($template), $request->validated());
        Toast::success(__('Template saved.'));

        return back();
    }

    public function destroy(Request $request, string $template): RedirectResponse
    {
        $this->support->actor($request);
        $this->templates->delete(Template::query()->findOrFail($template));
        Toast::success(__('Template deleted.'));

        return back();
    }

    /** The text rendered with sample values and its segment count. Nothing is saved. */
    public function preview(PreviewRequest $request): JsonResponse
    {
        $this->support->actor($request);
        $purpose = TemplatePurpose::from((string) $request->validated('purpose'));
        $body = trim((string) $request->validated('body'));
        $count = $this->composer->assertValid($body, $purpose);

        return response()->json([
            'text' => TemplateVariables::render($body, TemplateVariables::sampleValues()),
            'withOptOut' => $purpose === TemplatePurpose::Marketing,
            'segments' => $count->toArray(),
        ]);
    }
}
