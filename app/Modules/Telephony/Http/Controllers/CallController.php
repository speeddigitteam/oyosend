<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Modules\Telephony\Enums\CallDirection;
use App\Modules\Telephony\Enums\CallStatus;
use App\Modules\Telephony\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The call log, narrowed to the calls the member may see under `telephony.calls.view`. */
final class CallController
{
    public const string VIEW = 'telephony.calls.view';

    public function __construct(private readonly RecordAccess $records) {}

    public function index(Request $request): Response
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', Rule::enum(CallDirection::class)],
            'status' => ['nullable', Rule::enum(CallStatus::class)],
        ]);
        $search = trim((string) ($input['search'] ?? ''));

        $calls = $this->records->constrain(Call::query(), $actor, self::VIEW)
            ->with(['contact:id,first_name,last_name', 'owner.user:id,name'])
            ->when($search !== '', fn ($query) => $query->where('remote_number', 'like', Like::contains($search)))
            ->when($input['direction'] ?? null, fn ($query, string $direction) => $query->where('direction', $direction))
            ->when($input['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('started_at')->orderByDesc('id')
            ->cursorPaginate(50)->withQueryString();

        return Inertia::render('Modules/Telephony/Pages/Calls/Index', [
            'calls' => [
                'data' => $calls->getCollection()->map(fn (Call $call): array => self::present($call, withOwner: true))->all(),
                'per_page' => $calls->perPage(),
                'prev_page_url' => $calls->previousPageUrl(),
                'next_page_url' => $calls->nextPageUrl(),
            ],
            'filters' => ['search' => $search, 'direction' => $input['direction'] ?? null, 'status' => $input['status'] ?? null],
            'canCall' => $actor->can(DialerController::MAKE),
        ]);
    }

    /** @return array<string, mixed> */
    public static function present(Call $call, bool $withOwner = false): array
    {
        return [
            'id' => $call->id,
            'direction' => $call->direction->value,
            'mode' => $call->mode->value,
            'status' => $call->status->value,
            'number' => $call->remote_number,
            'contact' => $call->contact === null ? null : ['id' => $call->contact->id, 'name' => $call->contact->fullName()],
            'startedAt' => $call->started_at->toIso8601String(),
            'durationSeconds' => $call->duration_seconds,
            'confirmed' => $call->confirmed_at !== null,
            'owner' => $withOwner ? $call->owner?->user?->name : null,
        ];
    }
}
