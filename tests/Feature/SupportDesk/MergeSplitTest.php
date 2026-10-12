<?php

declare(strict_types=1);

use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Actions\TicketMerger;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\SurveyStatus;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\TicketMerged;
use App\Modules\Support\Models\Attachment;
use App\Modules\Support\Models\CsatSurvey;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\Tag;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use App\Modules\Support\Models\TicketTag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    $this->tenant = $this->supportTenant('sup-merge');
    $this->owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->supportAt('2026-03-09 10:00');
    $this->reload = fn (Ticket $ticket): Ticket => $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id));
    $this->messagesOf = fn (Ticket $ticket) => $this->inTenant($this->tenant, fn () => Message::query()->where('ticket_id', $ticket->id)->orderBy('created_at')->orderBy('id')->get());
});

it('moves every message, attachment and tag to the target and leaves a read-only source', function () {
    Event::fake([TicketMerged::class]);
    $source = $this->supportTicket($this->tenant, ['subject' => 'Duplicate', 'tags' => ['billing']]);
    $target = $this->supportTicket($this->tenant, ['subject' => 'Original', 'tags' => ['urgent']]);
    $manager = fn () => app(TicketManager::class);

    $this->inTenant($this->tenant, function () use ($source, $manager): void {
        $manager()->addMessage($source, MessageKind::PublicReply, 'Looking into it', $this->owner, [UploadedFile::fake()->create('log.pdf', 10, 'application/pdf')]);
        $manager()->addMessage($source, MessageKind::InternalNote, 'Same customer as the other ticket', $this->owner);
    });
    $beforeMessages = count(($this->messagesOf)($source)) + count(($this->messagesOf)($target));

    $this->supportAt('2026-03-09 11:00');
    $merged = $this->inTenant($this->tenant, fn () => app(TicketMerger::class)->merge($source, $target, $this->owner, 'duplicate'));

    $source = ($this->reload)($source);

    expect($merged->id)->toBe($target->id)
        ->and($source->status)->toBe(TicketStatus::Merged)
        ->and($source->merged_into_id)->toBe($target->id)
        ->and(count(($this->messagesOf)($source)))->toBe(0)
        ->and(count(($this->messagesOf)($target)))->toBe($beforeMessages)
        // The reply made on the source counts as the first response of the surviving ticket.
        ->and(($this->reload)($target)->first_responded_at)->not->toBeNull();

    $this->inTenant($this->tenant, function () use ($source, $target): void {
        expect(Attachment::query()->where('ticket_id', $target->id)->count())->toBe(1)
            ->and(Attachment::query()->where('ticket_id', $source->id)->count())->toBe(0)
            ->and(TicketTag::query()->where('ticket_id', $target->id)->count())->toBe(2)
            ->and(TicketTag::query()->where('ticket_id', $source->id)->count())->toBe(0)
            ->and(Tag::query()->count())->toBe(2)
            ->and(TicketEvent::query()->where('ticket_id', $source->id)->pluck('kind')->all())->toContain(TicketEventKind::MergedInto)
            ->and(TicketEvent::query()->where('ticket_id', $target->id)->pluck('kind')->all())->toContain(TicketEventKind::MergedFrom);

        // The file is still readable through the target: nothing was copied or lost.
        $attachment = Attachment::query()->where('ticket_id', $target->id)->firstOrFail();
        expect(app(TenantStorage::class)->exists($attachment->path))->toBeTrue();
    });

    Event::assertDispatched(TicketMerged::class, fn ($event) => $event->payload()['target_id'] === $target->id);
});

it('makes the merged ticket read-only and refuses to merge it again', function () {
    $source = $this->supportTicket($this->tenant);
    $target = $this->supportTicket($this->tenant);
    $this->inTenant($this->tenant, fn () => app(TicketMerger::class)->merge($source, $target, $this->owner));

    $this->inTenant($this->tenant, function () use ($source, $target): void {
        $manager = app(TicketManager::class);

        expect(fn () => $manager->addMessage($source, MessageKind::PublicReply, 'x', $this->owner))->toThrow(ValidationException::class)
            ->and(fn () => $manager->changeStatus($source, TicketStatus::Open, $this->owner))->toThrow(ValidationException::class)
            ->and(fn () => $manager->update($source, ['subject' => 'Renamed'], $this->owner))->toThrow(ValidationException::class)
            ->and(fn () => app(TicketMerger::class)->merge($source, $target, $this->owner))->toThrow(ValidationException::class)
            ->and(fn () => app(TicketMerger::class)->merge($target, $source, $this->owner))->toThrow(ValidationException::class);
    });
});

it('rejects a self merge, a finished target and an unrelated requester without confirmation', function () {
    $mine = $this->supportTicket($this->tenant, ['requester_email' => 'a@example.test']);
    $theirs = $this->supportTicket($this->tenant, ['requester_email' => 'b@example.test']);
    $done = $this->supportTicket($this->tenant, ['requester_email' => 'a@example.test']);
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->changeStatus($done, TicketStatus::Resolved, $this->owner));

    $this->inTenant($this->tenant, function () use ($mine, $theirs, $done): void {
        $merger = app(TicketMerger::class);

        expect(fn () => $merger->merge($mine, $mine, $this->owner))->toThrow(ValidationException::class)
            ->and(fn () => $merger->merge($mine, $done, $this->owner))->toThrow(ValidationException::class)
            ->and(fn () => $merger->merge($mine, $theirs, $this->owner))->toThrow(ValidationException::class);

        // Confirmed by a person, it goes through.
        expect($merger->merge($mine, $theirs, $this->owner, null, confirmed: true)->id)->toBe($theirs->id);
    });
});

it('repoints earlier merges so there are never chains', function () {
    $a = $this->supportTicket($this->tenant);
    $b = $this->supportTicket($this->tenant);
    $c = $this->supportTicket($this->tenant);

    $this->inTenant($this->tenant, function () use ($a, $b, $c): void {
        $merger = app(TicketMerger::class);
        $merger->merge($a, $b, $this->owner);
        $merger->merge($b, $c, $this->owner);
    });

    expect(($this->reload)($a)->merged_into_id)->toBe($c->id)
        ->and(($this->reload)($b)->merged_into_id)->toBe($c->id);
});

it('expires pending surveys of the merged ticket', function () {
    $source = $this->supportTicket($this->tenant);
    $target = $this->supportTicket($this->tenant);
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->changeStatus($source, TicketStatus::Resolved, $this->owner));
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->changeStatus($source, TicketStatus::Open, $this->owner));
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->changeStatus($source, TicketStatus::Resolved, $this->owner));

    expect($this->inTenant($this->tenant, fn () => CsatSurvey::query()->where('ticket_id', $source->id)->where('status', SurveyStatus::Pending)->count()))->toBe(1);

    // A resolved ticket cannot be merged away until it is reopened; reopen, then merge.
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->changeStatus($source, TicketStatus::Open, $this->owner));
    $this->inTenant($this->tenant, fn () => app(TicketMerger::class)->merge($source, $target, $this->owner, null, true));

    expect($this->inTenant($this->tenant, fn () => CsatSurvey::query()->where('status', SurveyStatus::Pending)->count()))->toBe(0);
});

it('rolls the whole merge back when something fails midway', function () {
    $source = $this->supportTicket($this->tenant);
    $target = $this->supportTicket($this->tenant);

    // A listener that fails while the merge's own domain event is being dispatched inside the transaction is
    // not possible (events dispatch after commit), so fail the transaction from the outside.
    try {
        $this->inTenant($this->tenant, function () use ($source, $target): void {
            DB::transaction(function () use ($source, $target): void {
                app(TicketMerger::class)->merge($source, $target, $this->owner);

                throw new RuntimeException('late failure');
            });
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(($this->reload)($source)->status)->not->toBe(TicketStatus::Merged)
        ->and(count(($this->messagesOf)($source)))->toBe(1)
        ->and(count(($this->messagesOf)($target)))->toBe(1);
});

it('splits chosen messages into a new, linked ticket and keeps the original number', function () {
    $ticket = $this->supportTicket($this->tenant, ['subject' => 'Two problems', 'tags' => ['mixed']]);
    $manager = fn () => app(TicketManager::class);

    $this->inTenant($this->tenant, function () use ($ticket, $manager): void {
        $manager()->addMessage($ticket, MessageKind::PublicReply, 'Which one first?', $this->owner);
        $manager()->addMessage($ticket, MessageKind::CustomerReply, 'The printer is also broken.', null, [UploadedFile::fake()->create('photo.png', 5, 'image/png')]);
    });
    $messages = ($this->messagesOf)($ticket);
    $moving = $messages->last();

    $new = $this->inTenant($this->tenant, fn () => app(TicketMerger::class)->split($ticket, [$moving->id], 'Printer broken', $this->owner));

    expect($new->number)->toBe('TKT-00002')
        ->and($new->split_from_id)->toBe($ticket->id)
        ->and($new->subject)->toBe('Printer broken')
        ->and($new->requester_email)->toBe('nadia@example.test')
        ->and(count(($this->messagesOf)($ticket)))->toBe(2)
        ->and(count(($this->messagesOf)($new)))->toBe(1)
        ->and(($this->reload)($ticket)->number)->toBe('TKT-00001');

    $this->inTenant($this->tenant, function () use ($ticket, $new): void {
        expect(Attachment::query()->where('ticket_id', $new->id)->count())->toBe(1)
            ->and(Attachment::query()->where('ticket_id', $ticket->id)->count())->toBe(0)
            ->and(TicketTag::query()->where('ticket_id', $new->id)->count())->toBe(1)
            ->and(TicketEvent::query()->where('ticket_id', $ticket->id)->pluck('kind')->all())->toContain(TicketEventKind::SplitTo)
            ->and(TicketEvent::query()->where('ticket_id', $new->id)->pluck('kind')->all())->toContain(TicketEventKind::SplitFrom);
    });
});

it('refuses a split that would empty the ticket or names a foreign message', function () {
    $ticket = $this->supportTicket($this->tenant);
    $other = $this->supportTicket($this->tenant);
    $only = ($this->messagesOf)($ticket)->first();
    $foreign = ($this->messagesOf)($other)->first();

    $this->inTenant($this->tenant, function () use ($ticket, $only, $foreign): void {
        $merger = app(TicketMerger::class);

        expect(fn () => $merger->split($ticket, [$only->id], 'All of it', $this->owner))->toThrow(ValidationException::class)
            ->and(fn () => $merger->split($ticket, [$foreign->id], 'Not mine', $this->owner))->toThrow(ValidationException::class)
            ->and(fn () => $merger->split($ticket, [], 'Nothing', $this->owner))->toThrow(ValidationException::class);
    });

    // Nothing was created by the refused attempts.
    expect($this->inTenant($this->tenant, fn () => Ticket::query()->count()))->toBe(2);
});
