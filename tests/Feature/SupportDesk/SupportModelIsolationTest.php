<?php

declare(strict_types=1);

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Support\Actions\ArticleManager;
use App\Modules\Support\Actions\SupportCatalogue;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\ArticleStatus;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\SurveyStatus;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\AssignmentRule;
use App\Modules\Support\Models\Attachment;
use App\Modules\Support\Models\AutomationActionReceipt;
use App\Modules\Support\Models\Calendar;
use App\Modules\Support\Models\CannedResponse;
use App\Modules\Support\Models\Category;
use App\Modules\Support\Models\CsatSurvey;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\KbArticleVersion;
use App\Modules\Support\Models\KbCategory;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\NumberSequence;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\QueueAgent;
use App\Modules\Support\Models\Setting;
use App\Modules\Support\Models\SlaPolicy;
use App\Modules\Support\Models\Tag;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use App\Modules\Support\Models\TicketTag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

$ticket = fn (): Ticket => Ticket::query()->create([
    'number' => 'TKT-'.Str::random(8),
    'subject' => 'Isolation',
    'status' => TicketStatus::New,
    'priority' => TicketPriority::Normal,
    'channel' => TicketChannel::Web,
]);
$queue = fn (): Queue => Queue::query()->create(['name' => 'Q '.Str::random(6), 'assignment_mode' => 'manual']);

it('isolates tickets, messages, attachments, events and tags between tenants', function () use ($ticket) {
    $this->assertTenantIsolated(Ticket::class, fn () => $ticket());
    $this->assertTenantIsolated(Message::class, fn () => Message::query()->create([
        'ticket_id' => $ticket()->id, 'kind' => MessageKind::InternalNote, 'body' => 'x',
    ]));
    $this->assertTenantIsolated(Attachment::class, fn () => Attachment::query()->create([
        'ticket_id' => $ticket()->id, 'original_name' => 'a.pdf', 'path' => 'support/attachments/'.Str::random(8), 'mime_type' => 'application/pdf', 'size' => 1,
    ]));
    $this->assertTenantIsolated(TicketEvent::class, fn () => TicketEvent::query()->create([
        'ticket_id' => $ticket()->id, 'kind' => TicketEventKind::Created,
    ]));
    $this->assertTenantIsolated(Tag::class, fn () => Tag::query()->create(['name' => 'tag-'.Str::random(5)]));
    $this->assertTenantIsolated(TicketTag::class, fn () => TicketTag::query()->create([
        'ticket_id' => $ticket()->id, 'tag_id' => Tag::query()->create(['name' => 'tag-'.Str::random(5)])->id,
    ]));
});

it('isolates queues, agents, categories, rules and settings between tenants', function () use ($queue) {
    $this->assertTenantIsolated(Queue::class, fn () => $queue());
    $this->assertTenantIsolated(Category::class, fn () => Category::query()->create(['name' => 'C '.Str::random(6)]));
    $this->assertTenantIsolated(AssignmentRule::class, fn () => AssignmentRule::query()->create([
        'name' => 'R', 'queue_id' => $queue()->id,
    ]));
    $this->assertTenantIsolated(Setting::class, fn () => Setting::query()->create([]));
    $this->assertTenantIsolated(NumberSequence::class, fn () => NumberSequence::query()->create(['kind' => 'ticket', 'last_number' => 9]));
});

it('isolates agents and their queue enrolment between tenants', function () use ($queue) {
    $agent = function (): Agent {
        $tenant = app(TenantContext::class)->require();
        $owner = TenantMembership::query()->where('is_owner', true)->firstOrFail();

        return Agent::query()->create(['membership_id' => $owner->id]);
    };

    $this->assertTenantIsolated(Agent::class, fn () => $agent());
    $this->assertTenantIsolated(QueueAgent::class, fn () => QueueAgent::query()->create(['queue_id' => $queue()->id, 'agent_id' => $agent()->id]));
});

it('isolates SLA policies, calendars, canned responses and surveys between tenants', function () use ($ticket) {
    $this->assertTenantIsolated(Calendar::class, fn () => Calendar::query()->create(['name' => 'Cal '.Str::random(6), 'hours' => ['1' => [['09:00', '17:00']]]]));
    $this->assertTenantIsolated(SlaPolicy::class, fn () => SlaPolicy::query()->create([
        'name' => 'P '.Str::random(6), 'targets' => ['normal' => ['first_response' => 60, 'resolution' => 120]],
    ]));
    $this->assertTenantIsolated(CannedResponse::class, fn () => CannedResponse::query()->create(['name' => 'Hi '.Str::random(6), 'body' => 'Hello']));
    $this->assertTenantIsolated(CsatSurvey::class, fn () => CsatSurvey::query()->create([
        'ticket_id' => $ticket()->id, 'status' => SurveyStatus::Pending, 'token_hash' => hash('sha256', Str::random(16)), 'expires_at' => now()->addDay(),
    ]));
});

it('isolates knowledge base records and action receipts between tenants', function () {
    $article = fn (): KbArticle => KbArticle::query()->create([
        'title' => 'A', 'slug' => 'a-'.Str::random(6), 'body' => 'b', 'status' => ArticleStatus::Draft,
    ]);

    $this->assertTenantIsolated(KbCategory::class, fn () => KbCategory::query()->create(['name' => 'K '.Str::random(6), 'slug' => 'k-'.Str::random(6)]));
    $this->assertTenantIsolated(KbArticle::class, fn () => $article());
    $this->assertTenantIsolated(KbArticleVersion::class, fn () => KbArticleVersion::query()->create([
        'article_id' => $article()->id, 'version' => 1, 'title' => 'A', 'body' => 'b',
    ]));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'support.ticket.create', 'idempotency_key' => Str::random(12), 'output' => [],
    ]));
});

it('answers 404 for every id route when the record belongs to another workspace', function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    $home = $this->supportTenant('sup-iso-home');
    $intruder = $this->supportTenant('sup-iso-intruder');
    $owner = $this->membershipOf($home, $this->ownerOf($home));
    $agent = $this->supportAgent($home, $owner);
    $queue = $this->supportQueue($home);
    $ticket = $this->supportTicket($home);
    $second = $this->supportTicket($home, ['subject' => 'Second']);
    $message = $this->inTenant($home, fn () => app(TicketManager::class)->addMessage($ticket, MessageKind::InternalNote, 'file', $owner, [UploadedFile::fake()->create('a.pdf', 3, 'application/pdf')]));
    $attachment = $this->inTenant($home, fn () => Attachment::query()->where('message_id', $message->id)->sole());
    $canned = $this->inTenant($home, fn () => app(SupportCatalogue::class)->saveCanned(null, ['name' => 'Hi', 'body' => 'Hello'], $owner));
    $category = $this->inTenant($home, fn () => app(SupportCatalogue::class)->saveCategory(null, ['name' => 'Cat']));
    $rule = $this->inTenant($home, fn () => app(SupportCatalogue::class)->saveRule(null, ['name' => 'Rule', 'queue_id' => $queue->id]));
    $policy = $this->supportPolicy($home);
    $calendar = $this->supportCalendar($home);
    $article = $this->inTenant($home, fn () => app(ArticleManager::class)->create(['title' => 'Art', 'body' => 'Body'], $owner));
    $kbCategory = $this->inTenant($home, fn () => app(ArticleManager::class)->saveCategory(null, ['name' => 'KbCat']));

    $reads = [
        "/support/tickets/{$ticket->id}",
        "/support/tickets/{$ticket->id}/attachments/{$attachment->id}",
        "/support/tickets/{$ticket->id}/canned/{$canned->id}",
        "/support/kb/articles/{$article->id}",
    ];

    foreach ($reads as $path) {
        $this->assertNotFoundFromOtherTenant('GET', $path, $home, intruder: $intruder);
    }

    $writes = [
        ['PUT', "/support/tickets/{$ticket->id}", ['subject' => 'Changed']],
        ['POST', "/support/tickets/{$ticket->id}/messages", ['kind' => 'internal_note', 'body' => 'hi']],
        ['POST', "/support/tickets/{$ticket->id}/status", ['status' => 'on_hold']],
        ['POST', "/support/tickets/{$ticket->id}/assign", ['assignee_id' => $owner->id]],
        ['POST', "/support/tickets/{$ticket->id}/claim", []],
        ['POST', "/support/tickets/{$ticket->id}/merge", ['target_number' => $second->number, 'confirm' => true]],
        ['POST', "/support/tickets/{$second->id}/split", ['subject' => 'Split', 'message_ids' => [$message->id]]],
        ['POST', "/support/tickets/{$second->id}/survey", []],
        ['PUT', "/support/canned/{$canned->id}", ['name' => 'Hi', 'body' => 'Hello again']],
        ['PUT', "/support/queues/{$queue->id}", ['name' => 'Renamed', 'assignment_mode' => 'manual']],
        ['PUT', "/support/agents/{$agent->id}", ['is_available' => false]],
        ['PUT', "/support/categories/{$category->id}", ['name' => 'Cat 2']],
        ['PUT', "/support/rules/{$rule->id}", ['name' => 'Rule 2', 'queue_id' => $queue->id]],
        ['PUT', "/support/sla/policies/{$policy->id}", ['name' => 'Policy 2', 'targets' => ['normal' => ['first_response' => 10, 'resolution' => 20]]]],
        ['PUT', "/support/sla/calendars/{$calendar->id}", ['name' => 'Calendar 2', 'hours' => ['1' => [['09:00', '17:00']]]]],
        ['PUT', "/support/kb/articles/{$article->id}", ['title' => 'Art 2', 'body' => 'Body 2']],
        ['POST', "/support/kb/articles/{$article->id}/publish", []],
        ['POST', "/support/kb/articles/{$article->id}/versions/1/restore", []],
        ['PUT', "/support/kb/categories/{$kbCategory->id}", ['name' => 'KbCat 2']],
        // Destructive verbs last: the control request really performs them.
        ['DELETE', "/support/rules/{$rule->id}", []],
        ['DELETE', "/support/canned/{$canned->id}", []],
        ['DELETE', "/support/kb/categories/{$kbCategory->id}", []],
    ];

    foreach ($writes as [$method, $path, $data]) {
        $this->assertNotFoundFromOtherTenant($method, $path, $home, $data, $intruder);
    }
});
