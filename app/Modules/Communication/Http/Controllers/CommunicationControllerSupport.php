<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Models\Message;
use Illuminate\Http\Request;

final class CommunicationControllerSupport
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
    ) {}

    public function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    public function membership(User $actor): TenantMembership
    {
        return $this->access->membership($actor) ?? abort(403);
    }

    /**
     * Contacts the actor may see, for pickers. Narrowed by the actor's `core.contacts.view` scope.
     *
     * @return list<array{id: string, name: string, email: string|null, phone: string|null}>
     */
    public function contactOptions(User $actor, int $limit = 500): array
    {
        return $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
            ->orderBy('first_name')->orderBy('last_name')->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone'])
            ->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'name' => $contact->fullName(),
                'email' => $contact->email,
                'phone' => $contact->phone,
            ])->all();
    }

    /**
     * A row of the message log as a screen shows it.
     *
     * @return array<string, mixed>
     */
    public function message(Message $message, User $actor): array
    {
        return [
            'id' => $message->id,
            'channel' => $message->channel->value,
            'purpose' => $message->purpose->value,
            'status' => $message->status->value,
            'recipient' => $message->recipient,
            'subject' => $message->subject,
            'source' => $message->source,
            'contact' => $message->contact === null ? null : ['id' => $message->contact->id, 'name' => $message->contact->fullName()],
            'company' => $message->company === null ? null : ['id' => $message->company->id, 'name' => $message->company->name],
            'attempts' => $message->attempts,
            'failureCode' => $message->failure_code,
            'queuedAt' => $message->queued_at?->toIso8601String(),
            'nextAttemptAt' => $message->next_attempt_at?->toIso8601String(),
            'sentAt' => $message->sent_at?->toIso8601String(),
            'deliveredAt' => $message->delivered_at?->toIso8601String(),
            'failedAt' => $message->failed_at?->toIso8601String(),
            'createdAt' => $message->created_at->toIso8601String(),
            'retryable' => $message->status === MessageStatus::Failed
                && $this->records->allows($actor, MessageController::SEND, $message),
            'cancellable' => $message->status === MessageStatus::Queued
                && $this->records->allows($actor, MessageController::SEND, $message),
        ];
    }
}
