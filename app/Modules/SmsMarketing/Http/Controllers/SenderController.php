<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\SmsMarketing\Actions\SenderManager;
use App\Modules\SmsMarketing\Enums\RegistrationStatus;
use App\Modules\SmsMarketing\Enums\SenderIdType;
use App\Modules\SmsMarketing\Http\Requests\SenderRequest;
use App\Modules\SmsMarketing\Models\SenderId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SenderController
{
    public function __construct(
        private readonly SenderManager $senders,
        private readonly SmsSupport $support,
        private readonly SmsPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/SmsMarketing/Pages/Senders/Index', [
            'senders' => SenderId::query()->orderBy('label')->get()->map(fn (SenderId $sender): array => $this->presenter->sender($sender))->all(),
            'types' => SenderIdType::values(),
            'statuses' => RegistrationStatus::values(),
            'canManage' => $actor->can('sms_marketing.senders.manage'),
        ]);
    }

    public function store(SenderRequest $request): RedirectResponse
    {
        $this->support->actor($request);
        $this->senders->create($request->validated());
        Toast::success(__('Sender id saved.'));

        return back();
    }

    public function update(SenderRequest $request, string $sender): RedirectResponse
    {
        $this->support->actor($request);
        $this->senders->update(SenderId::query()->findOrFail($sender), $request->validated());
        Toast::success(__('Sender id saved.'));

        return back();
    }

    public function destroy(Request $request, string $sender): RedirectResponse
    {
        $this->support->actor($request);
        $this->senders->delete(SenderId::query()->findOrFail($sender));
        Toast::success(__('Sender id deleted.'));

        return back();
    }
}
