<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\EmailMarketing\Actions\SequenceManager;
use App\Modules\EmailMarketing\Models\Sequence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SequenceController
{
    public function __construct(
        private readonly SequenceManager $sequences,
        private readonly EmailMarketingSupport $support,
        private readonly EmailMarketingPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/EmailMarketing/Pages/Sequences/Index', [
            'sequences' => Sequence::query()->with('list')->orderBy('name')->paginate(25)
                ->through(fn (Sequence $sequence): array => $this->presenter->sequence($sequence)),
            'canManage' => $actor->can('email_marketing.sequences.manage'),
            'canActivate' => $actor->can('email_marketing.sequences.activate'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->support->actor($request);

        return Inertia::render('Modules/EmailMarketing/Pages/Sequences/Form', [
            'sequence' => null,
            'form' => $this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->support->actor($request);
        $sequence = $this->sequences->create($this->input($request));
        Toast::success(__('Sequence saved as a draft.'));

        return redirect()->route('email_marketing.sequences.show', ['sequence' => $sequence->id]);
    }

    public function show(Request $request, string $sequence): Response
    {
        $actor = $this->support->actor($request);
        $record = Sequence::query()->with(['list', 'steps'])->findOrFail($sequence);

        return Inertia::render('Modules/EmailMarketing/Pages/Sequences/Show', [
            'sequence' => $this->presenter->sequence($record, true),
            'canManage' => $actor->can('email_marketing.sequences.manage'),
            'canActivate' => $actor->can('email_marketing.sequences.activate'),
        ]);
    }

    public function edit(Request $request, string $sequence): Response
    {
        $this->support->actor($request);
        $record = Sequence::query()->with(['list', 'steps'])->findOrFail($sequence);

        return Inertia::render('Modules/EmailMarketing/Pages/Sequences/Form', [
            'sequence' => $this->presenter->sequence($record, true),
            'form' => $this->formOptions(),
        ]);
    }

    public function update(Request $request, string $sequence): RedirectResponse
    {
        $this->support->actor($request);
        $record = Sequence::query()->findOrFail($sequence);
        $this->sequences->update($record, $this->input($request));
        Toast::success(__('Sequence saved.'));

        return redirect()->route('email_marketing.sequences.show', ['sequence' => $record->id]);
    }

    public function destroy(Request $request, string $sequence): RedirectResponse
    {
        $this->support->actor($request);
        $this->sequences->delete(Sequence::query()->findOrFail($sequence));
        Toast::success(__('Sequence deleted.'));

        return redirect()->route('email_marketing.sequences.index');
    }

    public function activate(Request $request, string $sequence): RedirectResponse
    {
        $this->support->actor($request);
        $this->sequences->activate(Sequence::query()->findOrFail($sequence));
        Toast::success(__('Sequence activated. It starts for people who become subscribers from now on.'));

        return back();
    }

    public function pause(Request $request, string $sequence): RedirectResponse
    {
        $this->support->actor($request);
        $this->sequences->pause(Sequence::query()->findOrFail($sequence));
        Toast::success(__('Sequence paused.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'list_id' => ['required', 'string', 'size:26'],
            'steps' => ['present', 'array', 'max:'.SequenceManager::MAX_STEPS],
            'steps.*' => ['array'],
            'steps.*.id' => ['nullable', 'string', 'size:26'],
            'steps.*.delay_minutes' => ['required', 'integer', 'between:0,'.SequenceManager::MAX_DELAY_MINUTES],
            'steps.*.subject' => ['required', 'string', 'max:200'],
            'steps.*.preheader' => ['nullable', 'string', 'max:200'],
            'steps.*.blocks' => ['required', 'array', 'min:1', 'max:50'],
            'steps.*.blocks.*' => ['array'],
        ]);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'lists' => $this->support->listOptions(),
            'editor' => $this->support->editorOptions(),
            'maxSteps' => SequenceManager::MAX_STEPS,
            'maxDelayMinutes' => SequenceManager::MAX_DELAY_MINUTES,
        ];
    }
}
