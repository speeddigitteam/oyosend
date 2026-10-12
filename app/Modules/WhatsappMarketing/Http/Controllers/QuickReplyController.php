<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\WhatsappMarketing\Actions\QuickReplyManager;
use App\Modules\WhatsappMarketing\Actions\VariableResolver;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\QuickReply;
use App\Modules\WhatsappMarketing\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Saved replies (quick replies). */
final class QuickReplyController
{
    public function __construct(
        private readonly QuickReplyManager $replies,
        private readonly Presenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->presenter->actor($request);

        return Inertia::render('Modules/WhatsappMarketing/Pages/QuickReplies/Index', [
            'quickReplies' => QuickReply::query()->orderBy('shortcut')->limit(500)->get()
                ->map(fn (QuickReply $reply): array => $this->presenter->quickReply($reply))->all(),
            'placeholders' => VariableResolver::FIELDS,
            'canManage' => $actor->can(Permissions::QUICK_REPLIES_MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->replies->save(null, $this->validated($request), $this->presenter->membership($actor)->id);
        Toast::success(__('Quick reply saved.'));

        return back();
    }

    public function update(Request $request, QuickReply $quickReply): RedirectResponse
    {
        $this->replies->save($quickReply, $this->validated($request));
        Toast::success(__('Quick reply saved.'));

        return back();
    }

    public function destroy(QuickReply $quickReply): RedirectResponse
    {
        $quickReply->delete();
        Toast::success(__('Quick reply deleted.'));

        return back();
    }

    /** @return array{shortcut: string, title: string, body: string, is_active?: bool} */
    private function validated(Request $request): array
    {
        /** @var array{shortcut: string, title: string, body: string, is_active?: bool} */
        return $request->validate([
            'shortcut' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
