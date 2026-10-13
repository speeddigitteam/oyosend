<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Core\Audit\Models\AuditLog;
use App\Core\Automation\AutomationRegistry;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Reporting\ReportContext;
use App\Modules\Social\Comments\Actions\CommentSettings;
use App\Modules\Social\Comments\Actions\CommentSources;
use App\Modules\Social\Comments\Actions\RuleManager;
use App\Modules\Social\Comments\Automation\ReplyTemplate;
use App\Modules\Social\Comments\Automation\ReplyToCommentAction;
use App\Modules\Social\Comments\Automation\RuleEngine;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Jobs\EvaluateCommentRules;
use App\Modules\Social\Comments\Jobs\PollRecentComments;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Comments\Models\CommentRule;
use App\Modules\Social\Comments\Models\CommentRuleRun;
use App\Modules\Social\Comments\Models\CommentSource;
use App\Modules\Social\Comments\Models\PageClaim;
use App\Modules\Social\Comments\Reporting\CommentVolumeReport;
use App\Modules\Social\Comments\Support\KeywordMatcher;
use App\Modules\Social\Comments\Support\UntrustedText;
use App\Modules\Social\Comments\Widgets\SocialCommentsWidget;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\SocialPlatforms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

const FC_SECRET = 'app-secret-for-tests';

beforeEach(function () {
    config([
        'social.platforms.facebook.client_id' => 'app-id',
        'social.platforms.facebook.client_secret' => FC_SECRET,
        'social_comments.enabled' => true,
        'social_comments.facebook.verify_token' => 'verify-me',
    ]);
    $this->tenant = $this->socialTenant('fc-one');
    $this->account = $this->socialAccount($this->tenant, ['platform' => 'facebook', 'external_id' => '1000', 'name' => 'Brand Page', 'token_expires_at' => null]);
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
    $this->enable = fn (?SocialAccount $account = null) => $this->inTenant($this->tenant, function () use ($account) {
        Http::fake(['graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true])]);

        return app(CommentSources::class)->enable($account ?? $this->account);
    });
    $this->deliver = function (array $changes, ?string $secret = FC_SECRET, ?string $url = null, string $page = '1000') {
        $body = json_encode(['object' => 'page', 'entry' => [['id' => $page, 'time' => 1, 'changes' => $changes]]], JSON_THROW_ON_ERROR);
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', $url ?? ($this->url)('/webhooks/facebook'), [], [], [], $server, $body);
    };
    $this->change = fn (string $id, string $message = 'Price please', string $verb = 'add', string $from = '777', string $parent = '1000_post1') => [
        'field' => 'feed',
        'value' => ['item' => 'comment', 'verb' => $verb, 'comment_id' => $id, 'post_id' => '1000_post1', 'parent_id' => $parent,
            'from' => ['id' => $from, 'name' => 'Rahim Uddin'], 'message' => $message, 'created_time' => time()],
    ];
    // A saved, enabled rule owned by the workspace owner.
    $this->fcRule = fn (array $overrides = []): CommentRule => $this->inTenant($this->tenant, function () use ($overrides) {
        $owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
        $rule = app(RuleManager::class)->save(null, [
            'name' => 'Price questions', 'match_mode' => 'any', 'match_type' => 'word', 'keywords' => ['price', 'দাম'],
            'actions' => [['type' => 'reply']], 'reply_templates' => ['Hi {first_name}, see our price list!'],
            'hourly_cap' => 20, 'cooldown_minutes' => 60,
            ...$overrides,
        ], $owner);

        return app(RuleManager::class)->setEnabled($rule, true, $owner);
    });
    $this->fcRun = fn (string $externalId): mixed => $this->inTenant($this->tenant, function () use ($externalId) {
        $comment = AudienceComment::query()->where('external_id', $externalId)->firstOrFail();

        return app(RuleEngine::class)->process($comment);
    });
    $this->comments = fn () => $this->inTenant($this->tenant, fn () => AudienceComment::query()->orderBy('external_id')->get());
});

// ---- text and matching -----------------------------------------------------------------------------------------

it('cleans untrusted text of control and bidirectional characters and caps it', function () {
    $dirty = "Hi\x00 there\x07\u{202E}evil\u{200D}\r\n\r\n\r\n\r\n\r\nbye";

    expect(UntrustedText::clean($dirty, 100))->toBe("Hi thereevil\u{200D}\n\n\nbye")
        ->and(mb_strlen(UntrustedText::clean(str_repeat('অ', 50), 10)))->toBe(10);
});

it('matches keywords case-insensitively, by whole word or substring, in Bengali too', function () {
    $m = fn (string $text, array $words, string $mode = 'any', string $type = 'word') => KeywordMatcher::evaluate($text, $words, $mode, $type)['matched'];

    expect($m('What is the PRICE?', ['price']))->toBeTrue()
        ->and($m('priceless', ['price']))->toBeFalse()
        ->and($m('priceless', ['price'], 'any', 'contains'))->toBeTrue()
        ->and($m('দাম কত ভাই?', ['দাম']))->toBeTrue()
        ->and($m('দামি জিনিস', ['দাম']))->toBeFalse()
        ->and($m('দামি জিনিস', ['দাম'], 'any', 'contains'))->toBeTrue()
        ->and($m('how much is shipping cost', ['shipping cost']))->toBeTrue()
        ->and($m('shipping is the cost', ['shipping cost']))->toBeFalse()
        ->and($m('price and delivery', ['price', 'delivery'], 'all'))->toBeTrue()
        ->and($m('price only', ['price', 'delivery'], 'all'))->toBeFalse()
        ->and($m('anything', []))->toBeFalse();
});

it('treats regex-looking keywords as plain text and stays fast on hostile input', function () {
    $hostile = str_repeat('a', 7000).'!';
    $started = microtime(true);
    $result = KeywordMatcher::evaluate($hostile, ['(a+)+$', '.*', '[a-', '\\'], 'any', 'contains');

    expect($result['matched'])->toBeFalse()
        ->and(KeywordMatcher::evaluate('cost is (a+)+$ ok', ['(a+)+$'], 'any', 'contains')['matched'])->toBeTrue()
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});

it('only allows the listed template variables and never expands a commenter-supplied one', function () {
    expect(ReplyTemplate::unknownVariables('Hi {first_name} from {page_name} {evil}'))->toBe(['evil'])
        ->and(ReplyTemplate::render('Hi {first_name}, thanks!', '{page_name} Rahim', 'Shop'))->toBe('Hi page_name, thanks!')
        ->and(ReplyTemplate::render('Hi {first_name}, thanks!', null, 'Shop'))->toBe('Hi, thanks!');
});

// ---- webhook ---------------------------------------------------------------------------------------------------

it('verifies the webhook subscription handshake with the configured token only', function () {
    $this->get(($this->url)('/webhooks/facebook?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345'))->assertOk()->assertSee('12345');
    $this->get(($this->url)('/webhooks/facebook?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345'))->assertForbidden();
});

it('rejects an unsigned or wrongly signed delivery and stores nothing', function () {
    ($this->enable)();

    ($this->deliver)([($this->change)('1000_c1')], null)->assertStatus(401);
    ($this->deliver)([($this->change)('1000_c1')], 'another-secret')->assertStatus(401);

    expect(($this->comments)())->toHaveCount(0);
});

it('answers 404 when the app secret is not configured', function () {
    config(['social.platforms.facebook.client_secret' => null]);

    ($this->deliver)([($this->change)('1000_c1')])->assertNotFound();
});

it('stores a signed comment once, however often it is delivered', function () {
    ($this->enable)();

    ($this->deliver)([($this->change)('1000_c1', "Price please\x00 <script>alert(1)</script>")])->assertOk();
    ($this->deliver)([($this->change)('1000_c1', "Price please\x00 <script>alert(1)</script>")])->assertOk();

    $rows = ($this->comments)();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->message)->toBe('Price please <script>alert(1)</script>')
        ->and($rows[0]->author_name)->toBe('Rahim Uddin')
        ->and($rows[0]->account_id)->toBe($this->account->id)
        ->and($rows[0]->remote_state)->toBe(RemoteState::Visible)
        ->and($rows[0]->owner_id)->toBeNull();
});

it('applies edits, hides and removals', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1', 'first')]);
    ($this->deliver)([($this->change)('1000_c1', 'second', 'edited')]);
    expect(($this->comments)()[0]->message)->toBe('second');

    ($this->deliver)([($this->change)('1000_c1', '', 'hide')]);
    expect(($this->comments)()[0]->remote_state)->toBe(RemoteState::Hidden);

    ($this->deliver)([($this->change)('1000_c1', '', 'remove')]);
    expect(($this->comments)()[0]->remote_state)->toBe(RemoteState::Deleted);
});

it('ignores events for a Page nobody claimed or whose comments are switched off', function () {
    ($this->deliver)([($this->change)('1000_c1')])->assertOk();
    expect(($this->comments)())->toHaveCount(0);

    ($this->enable)();
    $this->inTenant($this->tenant, fn () => app(CommentSources::class)->disable($this->account));
    ($this->deliver)([($this->change)('1000_c2')])->assertOk();
    expect(($this->comments)())->toHaveCount(0);
});

it('lets one Page feed only one workspace', function () {
    ($this->enable)();
    $other = $this->socialTenant('fc-two');
    $theirs = $this->socialAccount($other, ['platform' => 'facebook', 'external_id' => '1000', 'name' => 'Same Page']);

    $this->inTenant($other, function () use ($theirs) {
        Http::fake();
        expect(fn () => app(CommentSources::class)->enable($theirs))->toThrow(ValidationException::class);
    });

    // The second workspace's host never receives the first one's events.
    ($this->deliver)([($this->change)('1000_c1')], FC_SECRET, $this->socialUrl($other, '/webhooks/facebook'))->assertOk();
    $this->inTenant($other, fn () => expect(AudienceComment::query()->count())->toBe(0));
    expect(($this->comments)())->toHaveCount(0);
    expect(PageClaim::query()->where('external_id', '1000')->count())->toBe(1);
});

it('lets a Page be taken over once its claiming account was disconnected', function () {
    ($this->enable)();
    $this->inTenant($this->tenant, fn () => $this->account->forceFill(['status' => AccountStatus::Disconnected])->save());
    $other = $this->socialTenant('fc-three');
    $theirs = $this->socialAccount($other, ['platform' => 'facebook', 'external_id' => '1000']);

    $this->inTenant($other, function () use ($theirs) {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        app(CommentSources::class)->enable($theirs);
    });

    expect(PageClaim::query()->where('external_id', '1000')->sole()->tenant_id)->toBe($other->id);
});

it('routes a central delivery to the workspace that claimed the Page', function () {
    ($this->enable)();

    ($this->deliver)([($this->change)('1000_c9')], FC_SECRET, 'http://'.config('platform.domain').'/social/webhooks/facebook')->assertOk();
    ($this->deliver)([($this->change)('1000_c8')], FC_SECRET, 'http://'.config('platform.domain').'/social/webhooks/facebook', '999')->assertOk();

    expect(($this->comments)()->pluck('external_id')->all())->toBe(['1000_c9']);
});

it('records a comment the Page itself wrote as answered, not as work', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1')]);
    ($this->deliver)([($this->change)('1000_c2', 'Thanks!', 'add', '1000', '1000_c1')]);

    $rows = ($this->comments)()->keyBy('external_id');
    expect($rows['1000_c2']->is_from_page)->toBeTrue()->and($rows['1000_c2']->handled_at)->not->toBeNull()
        ->and($rows['1000_c1']->replied_at)->not->toBeNull()->and($rows['1000_c1']->reply_count)->toBe(1);
});

it('gives up on a poison event after the failure cap and answers 503 before that', function () {
    ($this->enable)();
    config(['social_comments.webhook.max_failures' => 2]);
    $payload = [($this->change)('1000_c1')];
    config(['fc_poison' => true]);
    AudienceComment::creating(static function (): void {
        if (config('fc_poison') === true) {
            throw new RuntimeException('boom');
        }
    });

    ($this->deliver)($payload)->assertStatus(503)->assertHeader('Retry-After', '60');
    ($this->deliver)($payload)->assertOk();
    config(['fc_poison' => false]);
});

// ---- inbox and permissions -------------------------------------------------------------------------------------

it('shows the inbox to members with the view permission and hides it from others', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1')]);
    [$viewer] = $this->socialMember($this->tenant, ['social.comments.view']);
    [$stranger] = $this->socialMember($this->tenant, ['social.posts.view']);

    $this->actingAsMember($this->tenant, $stranger);
    $this->get(($this->url)('/comments'))->assertForbidden();

    $this->startNewBrowserSession();
    $this->actingAsMember($this->tenant, $viewer);
    $this->get(($this->url)('/comments'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Modules/Social/Pages/Comments/Index')
        ->has('comments.data', 1)
        ->where('comments.data.0.message', 'Price please')
        ->where('can.reply', false));
    $this->get(($this->url)('/comments/rules'))->assertForbidden();
});

it('lets a viewer without reply or moderate rights do neither', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1')]);
    [$viewer] = $this->socialMember($this->tenant, ['social.comments.view']);
    $id = ($this->comments)()[0]->id;
    $this->actingAsMember($this->tenant, $viewer);
    Http::fake();

    $this->post(($this->url)("/comments/{$id}/reply"), ['message' => 'Hi'])->assertForbidden();
    $this->put(($this->url)("/comments/{$id}/hidden"), ['hidden' => true])->assertForbidden();
    $this->delete(($this->url)("/comments/{$id}"))->assertForbidden();
    $this->put(($this->url)("/comments/{$id}/assignee"), ['membership_id' => null])->assertForbidden();
    Http::assertNothingSent();
});

it('narrows the inbox and actions to the viewer scope', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1'), ($this->change)('1000_c2')]);
    [$agent, $membership] = $this->socialMember($this->tenant, ['social.comments.view', 'social.comments.reply'], ['social.comments.view' => 'own', 'social.comments.reply' => 'own']);
    [$first, $second] = ($this->comments)();
    $this->inTenant($this->tenant, fn () => $first->assignOwner($membership)->save());
    $this->actingAsMember($this->tenant, $agent);
    Http::fake();

    $this->get(($this->url)('/comments?status=open'))->assertInertia(fn (AssertableInertia $page) => $page->has('comments.data', 1)->where('comments.data.0.id', $first->id));
    $this->post(($this->url)("/comments/{$second->id}/reply"), ['message' => 'Hi'])->assertNotFound();
    $this->post(($this->url)("/comments/{$second->id}/read"))->assertNotFound();
});

it('answers 404 for a comment of another workspace', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1')]);
    $id = ($this->comments)()[0]->id;
    $other = $this->socialTenant('fc-iso');
    $this->actingAsOwner($other);

    $this->post($this->socialUrl($other, "/comments/{$id}/reply"), ['message' => 'Hi'])->assertNotFound();
    $this->post($this->socialUrl($other, "/comments/{$id}/read"))->assertNotFound();
    $this->put($this->socialUrl($other, "/comments/{$id}/hidden"), ['hidden' => true])->assertNotFound();
    $this->delete($this->socialUrl($other, "/comments/{$id}"))->assertNotFound();
    $this->inTenant($other, fn () => expect(AudienceComment::query()->count())->toBe(0));
});

it('replies through the Graph API, stores the reply in the thread and audits it', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1')]);
    $comment = ($this->comments)()[0];
    $this->actingAsOwner($this->tenant);
    Http::fake(['graph.facebook.com/*/1000_c1/comments' => Http::response(['id' => '1000_r1'])]);

    $this->post(($this->url)("/comments/{$comment->id}/reply"), ['message' => 'Thanks, 500 BDT.'])->assertRedirect();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/1000_c1/comments')
        && $request['message'] === 'Thanks, 500 BDT.'
        && $request->hasHeader('Authorization')
        && ! str_contains($request->url(), 'access_token'));
    $rows = ($this->comments)()->keyBy('external_id');
    expect($rows['1000_r1']->is_from_page)->toBeTrue()->and($rows['1000_r1']->parent_id)->toBe($comment->id)
        ->and($rows['1000_c1']->replied_at)->not->toBeNull()->and($rows['1000_c1']->handled_at)->not->toBeNull();
    $this->inTenant($this->tenant, fn () => expect(AuditLog::query()->where('event', 'social.comment.replied')->count())->toBe(1));

    // The platform later reports the same reply: still one row.
    ($this->deliver)([($this->change)('1000_r1', 'Thanks, 500 BDT.', 'add', '1000', '1000_c1')]);
    expect(($this->comments)())->toHaveCount(2);
});

it('moves the account to needs-reauthorization when the platform rejects the token', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1')]);
    $comment = ($this->comments)()[0];
    $this->actingAsOwner($this->tenant);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token: token tok-secret']], 400)]);

    $this->post(($this->url)("/comments/{$comment->id}/reply"), ['message' => 'Hi'])->assertRedirect();

    $account = $this->inTenant($this->tenant, fn () => SocialAccount::query()->findOrFail($this->account->id));
    expect($account->status)->toBe(AccountStatus::NeedsReauthorization)
        ->and($account->access_token)->toBeNull()
        ->and((string) $account->status_reason)->not->toContain('tok-');
});

it('hides, unhides, likes and deletes through the documented calls', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1')]);
    $id = ($this->comments)()[0]->id;
    $this->actingAsOwner($this->tenant);
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $this->put(($this->url)("/comments/{$id}/hidden"), ['hidden' => true])->assertRedirect();
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/1000_c1') && $r['is_hidden'] === 'true');
    expect(($this->comments)()[0]->remote_state)->toBe(RemoteState::Hidden);

    $this->put(($this->url)("/comments/{$id}/hidden"), ['hidden' => false])->assertRedirect();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/1000_c1') && $r['is_hidden'] === 'false');

    $this->put(($this->url)("/comments/{$id}/like"), ['liked' => true])->assertRedirect();
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/1000_c1/likes'));

    $this->delete(($this->url)("/comments/{$id}"))->assertRedirect();
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/1000_c1'));
    expect(($this->comments)()[0]->remote_state)->toBe(RemoteState::Deleted);
});

it('handles, assigns, notes, tags and bulk-updates locally', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1'), ($this->change)('1000_c2')]);
    [$a, $b] = ($this->comments)();
    $this->actingAsOwner($this->tenant);
    $membership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));

    $this->put(($this->url)("/comments/{$a->id}/handled"), ['handled' => true])->assertRedirect();
    $this->put(($this->url)("/comments/{$a->id}/assignee"), ['membership_id' => $membership->id])->assertRedirect();
    $this->post(($this->url)("/comments/{$a->id}/notes"), ['body' => "Check stock\x00 first"])->assertRedirect();
    $this->post(($this->url)("/comments/{$a->id}/tags"), ['tag' => 'vip'])->assertRedirect();

    $fresh = $this->inTenant($this->tenant, fn () => AudienceComment::query()->with('notes')->findOrFail($a->id));
    expect($fresh->handled_at)->not->toBeNull()->and($fresh->owner_id)->toBe($membership->id)
        ->and($fresh->tags)->toBe(['vip'])->and($fresh->notes->sole()->body)->toBe('Check stock first');

    $this->post(($this->url)('/comments/bulk'), ['operation' => 'handled', 'ids' => [$a->id, $b->id]])->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect(AudienceComment::query()->whereNull('handled_at')->count())->toBe(0));
    $this->post(($this->url)('/comments/bulk'), ['operation' => 'bogus', 'ids' => [$a->id]])->assertSessionHasErrors('operation');
});

// ---- polling ---------------------------------------------------------------------------------------------------

it('polls recent comments as a fallback and deduplicates against the webhook', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1')]);
    Http::fake([
        'graph.facebook.com/*/1000/posts*' => Http::response(['data' => [['id' => '1000_post1']]]),
        'graph.facebook.com/*/1000_post1/comments*' => Http::response(['data' => [
            ['id' => '1000_c1', 'message' => 'Price please', 'created_time' => now()->toIso8601String(), 'from' => ['id' => '777', 'name' => 'Rahim Uddin']],
            ['id' => '1000_c3', 'message' => 'Missed one', 'created_time' => now()->toIso8601String(), 'from' => ['id' => '778', 'name' => 'Karim']],
        ]]),
    ]);

    $this->inTenant($this->tenant, fn () => app()->call([new PollRecentComments, 'handle']));

    expect(($this->comments)()->pluck('external_id')->all())->toBe(['1000_c1', '1000_c3']);
    $source = $this->inTenant($this->tenant, fn () => CommentSource::query()->sole());
    expect($source->last_polled_at)->not->toBeNull()->and($source->consecutive_failures)->toBe(0);

    // Not due again straight away.
    Http::fake();
    $this->inTenant($this->tenant, fn () => app()->call([new PollRecentComments, 'handle']));
    Http::assertNothingSent();
});

it('backs off a throttled Page and marks a rejected token', function () {
    ($this->enable)();
    $mode = 'rate';
    Http::fake(function ($request) use (&$mode) {
        if ($mode === 'token') {
            return Http::response(['error' => ['code' => 190, 'message' => 'bad']], 401);
        }

        return str_contains($request->url(), '/comments')
            ? Http::response(['error' => ['code' => 4, 'message' => 'limit']], 429)
            : Http::response(['data' => [['id' => '1000_post1']]]);
    });
    $this->inTenant($this->tenant, fn () => app()->call([new PollRecentComments, 'handle']));
    $source = $this->inTenant($this->tenant, fn () => CommentSource::query()->sole());
    expect($source->consecutive_failures)->toBe(1)->and($source->last_error_code)->toBe('rate_limited');

    $mode = 'token';
    $this->inTenant($this->tenant, fn () => $source->forceFill(['last_polled_at' => now()->subDay()])->save());
    $this->inTenant($this->tenant, fn () => app()->call([new PollRecentComments, 'handle']));
    expect($this->inTenant($this->tenant, fn () => SocialAccount::query()->findOrFail($this->account->id)->status))->toBe(AccountStatus::NeedsReauthorization);
});

// ---- rules -----------------------------------------------------------------------------------------------------

it('replies once to a matching comment, with the commenter first name, and never twice', function () {
    ($this->enable)();
    $rule = ($this->fcRule)();
    Http::fake(['graph.facebook.com/*/comments' => Http::response(['id' => '1000_r1'])]);
    Bus::fake([EvaluateCommentRules::class]);
    ($this->deliver)([($this->change)('1000_c1', 'দাম কত?')]);
    Bus::assertDispatched(EvaluateCommentRules::class);

    ($this->fcRun)('1000_c1');
    ($this->fcRun)('1000_c1');

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['message'] === 'Hi Rahim, see our price list!');
    $this->inTenant($this->tenant, function () use ($rule) {
        expect(CommentRuleRun::query()->where('action', 'reply')->where('outcome', 'done')->count())->toBe(1)
            ->and($rule->fresh()->matched_count)->toBe(1)
            ->and(AuditLog::query()->where('event', 'social.comment.replied')->count())->toBe(1);
    });
});

it('does not act on non-matching, the Page own, hidden or old comments or while paused', function () {
    ($this->enable)();
    ($this->fcRule)();
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'x'])]);
    Bus::fake([EvaluateCommentRules::class]);
    ($this->deliver)([
        ($this->change)('1000_a', 'nice picture'),
        ($this->change)('1000_b', 'price?', 'add', '1000'),
        ($this->change)('1000_c', 'price?'),
    ]);

    ($this->fcRun)('1000_a');
    ($this->fcRun)('1000_b');
    Http::assertNothingSent();

    $this->inTenant($this->tenant, fn () => app(CommentSettings::class)->setPaused(true, null));
    ($this->fcRun)('1000_c');
    Http::assertNothingSent();

    $this->inTenant($this->tenant, fn () => app(CommentSettings::class)->setPaused(false, null));
    $this->inTenant($this->tenant, fn () => AudienceComment::query()->where('external_id', '1000_c')->update(['commented_at' => now()->subDays(5)]));
    ($this->fcRun)('1000_c');
    Http::assertNothingSent();
});

it('applies the per-author cooldown and the rule and Page hourly caps', function () {
    ($this->enable)();
    ($this->fcRule)(['cooldown_minutes' => 60, 'hourly_cap' => 1]);
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1000_r'])]);
    Bus::fake([EvaluateCommentRules::class]);
    ($this->deliver)([
        ($this->change)('1000_c1', 'price?', 'add', '777'),
        ($this->change)('1000_c2', 'price again?', 'add', '777'),
        ($this->change)('1000_c3', 'price?', 'add', '888'),
    ]);

    ($this->fcRun)('1000_c1');
    // Same author inside the cooldown: skipped for good (the rule cap is also reached, but the cooldown is terminal).
    ($this->fcRun)('1000_c2');
    // A different author but the rule hourly cap of 1 is used: held back, not dropped.
    $held = ($this->fcRun)('1000_c3');

    Http::assertSentCount(1);
    expect($held)->not->toBeNull()->and($held->reason)->toBe('rule_cap');
    $this->inTenant($this->tenant, fn () => expect(CommentRuleRun::query()->where('comment_id', AudienceComment::query()->where('external_id', '1000_c3')->value('id'))->where('action', 'reply')->count())->toBe(0));

    $this->travel(61)->minutes();
    $this->inTenant($this->tenant, fn () => AudienceComment::query()->update(['commented_at' => now()]));
    ($this->fcRun)('1000_c3');
    Http::assertSentCount(2);
});

it('holds replies during quiet hours without recording them', function () {
    ($this->enable)();
    $local = now('Asia/Dhaka');
    ($this->fcRule)(['quiet_start' => $local->copy()->subHour()->format('H:i'), 'quiet_end' => $local->copy()->addHour()->format('H:i')]);
    Http::fake();
    Bus::fake([EvaluateCommentRules::class]);
    ($this->deliver)([($this->change)('1000_c1', 'price?')]);

    $held = ($this->fcRun)('1000_c1');

    Http::assertNothingSent();
    expect($held->reason)->toBe('quiet_hours')->and($held->counts)->toBeFalse();
});

it('stops a rule whose member lost the permission', function () {
    ($this->enable)();
    [$user, $membership] = $this->socialMember($this->tenant, ['social.comments.view', 'social.comments.reply', 'social.comments.manage_rules']);
    $rule = $this->inTenant($this->tenant, function () use ($membership) {
        $rule = app(RuleManager::class)->save(null, [
            'name' => 'R', 'match_mode' => 'any', 'match_type' => 'word', 'keywords' => ['price'],
            'actions' => [['type' => 'reply']], 'reply_templates' => ['Hello'], 'hourly_cap' => 5, 'cooldown_minutes' => 0,
        ], $membership);

        return app(RuleManager::class)->setEnabled($rule, true, $membership);
    });
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'r'])]);
    Bus::fake([EvaluateCommentRules::class]);
    ($this->deliver)([($this->change)('1000_c1', 'price?')]);

    $this->inTenant($this->tenant, function () use ($membership) {
        $membership->forceFill(['status' => MembershipStatus::Suspended])->save();
    });
    ($this->fcRun)('1000_c1');

    Http::assertNothingSent();
    $this->inTenant($this->tenant, fn () => expect(CommentRuleRun::query()->where('action', 'reply')->sole()->reason)->toBe('actor_inactive'));
});

it('refuses to save a rule more powerful than its author and rejects bad templates', function () {
    [, $membership] = $this->socialMember($this->tenant, ['social.comments.view', 'social.comments.manage_rules']);

    $this->inTenant($this->tenant, function () use ($membership) {
        $base = ['name' => 'R', 'keywords' => ['price'], 'match_mode' => 'any', 'match_type' => 'word', 'reply_templates' => ['Hi'], 'hourly_cap' => 5, 'cooldown_minutes' => 0];

        expect(fn () => app(RuleManager::class)->save(null, [...$base, 'actions' => [['type' => 'reply']]], $membership))->toThrow(ValidationException::class);
        expect(fn () => app(RuleManager::class)->save(null, [...$base, 'actions' => [['type' => 'hide']]], $membership))->toThrow(ValidationException::class);
    });

    $owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->inTenant($this->tenant, function () use ($owner) {
        $base = ['name' => 'R2', 'keywords' => ['price'], 'match_mode' => 'any', 'match_type' => 'word', 'hourly_cap' => 5, 'cooldown_minutes' => 0];

        expect(fn () => app(RuleManager::class)->save(null, [...$base, 'actions' => [['type' => 'reply']], 'reply_templates' => ['Hi {evil}']], $owner))->toThrow(ValidationException::class);
        expect(fn () => app(RuleManager::class)->save(null, [...$base, 'actions' => [['type' => 'reply']], 'reply_templates' => []], $owner))->toThrow(ValidationException::class);
        expect(fn () => app(RuleManager::class)->save(null, [...$base, 'actions' => [['type' => 'run_code']], 'reply_templates' => ['x']], $owner))->toThrow(ValidationException::class);
        expect(fn () => app(RuleManager::class)->save(null, [...$base, 'keywords' => [], 'actions' => [['type' => 'hide']]], $owner))->toThrow(ValidationException::class);
    });
});

it('runs hide, tag, assign and review actions and notes a failed reply without retrying a rejection', function () {
    ($this->enable)();
    $owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    ($this->fcRule)(['actions' => [['type' => 'hide'], ['type' => 'tag', 'tag' => 'price'], ['type' => 'assign', 'membership_id' => $owner->id], ['type' => 'review'], ['type' => 'reply']]]);
    Http::fake([
        'graph.facebook.com/*/comments' => Http::response(['error' => ['code' => 100, 'message' => 'rejected']], 400),
        'graph.facebook.com/*' => Http::response(['success' => true]),
    ]);
    Bus::fake([EvaluateCommentRules::class]);
    ($this->deliver)([($this->change)('1000_c1', 'price?')]);

    ($this->fcRun)('1000_c1');
    ($this->fcRun)('1000_c1');

    $comment = ($this->comments)()[0];
    expect($comment->remote_state)->toBe(RemoteState::Hidden)->and($comment->tags)->toBe(['price'])
        ->and($comment->owner_id)->toBe($owner->id)->and($comment->needs_attention)->toBeTrue();
    $this->inTenant($this->tenant, fn () => expect(CommentRuleRun::query()->where('action', 'reply')->sole()->outcome)->toBe('failed'));
});

it('previews a draft rule without saving or sending anything', function () {
    $this->actingAsOwner($this->tenant);
    Http::fake();

    $this->postJson(($this->url)('/comments/rules/preview'), [
        'text' => 'DAM koto? দাম কত', 'author_name' => 'Rahim Uddin', 'keywords' => ['দাম'], 'match_type' => 'word',
        'actions' => [['type' => 'reply'], ['type' => 'tag', 'tag' => 'price']], 'reply_templates' => ['Hi {first_name}!'],
    ])->assertOk()->assertJsonPath('matched', true)->assertJsonPath('actions.0.detail', 'Hi Rahim!')->assertJsonPath('actions.1.detail', 'price');

    Http::assertNothingSent();
    $this->inTenant($this->tenant, fn () => expect(CommentRule::query()->count())->toBe(0));
});

it('manages rules over HTTP: validation, enable, kill switch and tenant isolation', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/comments/rules'), ['name' => ''])->assertSessionHasErrors();
    $this->post(($this->url)('/comments/rules'), [
        'name' => 'Greeting', 'match_mode' => 'any', 'match_type' => 'contains', 'keywords' => ['hello'],
        'actions' => [['type' => 'reply']], 'reply_templates' => ['Hello {first_name}'], 'hourly_cap' => 10, 'cooldown_minutes' => 30,
    ])->assertRedirect();
    $rule = $this->inTenant($this->tenant, fn () => CommentRule::query()->sole());
    expect($rule->is_enabled)->toBeFalse();

    $this->put(($this->url)("/comments/rules/{$rule->id}/enabled"), ['enabled' => true])->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => $rule->fresh()->is_enabled))->toBeTrue();

    $this->put(($this->url)('/comments/automation'), ['paused' => true])->assertRedirect();
    $this->get(($this->url)('/comments/rules'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Modules/Social/Pages/Comments/Rules')->where('paused', true)->has('rules', 1));

    $other = $this->socialTenant('fc-rules-iso');
    $this->actingAsOwner($other);
    $this->put($this->socialUrl($other, "/comments/rules/{$rule->id}/enabled"), ['enabled' => false])->assertNotFound();
    $this->delete($this->socialUrl($other, "/comments/rules/{$rule->id}"))->assertNotFound();
});

it('exposes the comments capability honestly and lists the new automation entries', function () {
    expect(app(SocialPlatforms::class)->get('facebook')->capabilities()->comments)->toBeTrue();
    config(['social_comments.enabled' => false]);
    expect(app(SocialPlatforms::class)->get('facebook')->capabilities()->comments)->toBeFalse();

    $registry = app(AutomationRegistry::class);
    expect($registry->trigger('social.comment.received'))->not->toBeNull()
        ->and($registry->action('social.comment.reply'))->not->toBeNull()
        ->and($registry->action('social.comment.hide'))->not->toBeNull();
});

it('keeps the automation actions idempotent per key', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1')]);
    $id = ($this->comments)()[0]->id;
    Http::fake(['graph.facebook.com/*/comments' => Http::response(['id' => '1000_r1'])]);

    $this->inTenant($this->tenant, function () use ($id) {
        $action = app(ReplyToCommentAction::class);
        $first = $action->execute(['comment_id' => $id, 'message' => 'Hello'], 'wf:1:a');
        $again = $action->execute(['comment_id' => $id, 'message' => 'Hello'], 'wf:1:a');

        expect($again)->toBe($first);
    });
    Http::assertSentCount(1);
});

it('reports comment volume and counts unhandled comments for the widget', function () {
    ($this->enable)();
    ($this->deliver)([($this->change)('1000_c1'), ($this->change)('1000_c2')]);
    $owner = $this->ownerOf($this->tenant);

    $this->inTenant($this->tenant, function () use ($owner) {
        AudienceComment::query()->where('external_id', '1000_c1')->update(['commented_at' => now()->subMinutes(30), 'replied_at' => now()->subMinutes(10), 'handled_at' => now()]);
        $widget = app(SocialCommentsWidget::class)($owner);
        expect($widget['unhandled'])->toBe(1);

        $context = new ReportContext(
            $owner, AccessScope::All, now()->subDay()->toDateString(), now()->addDay()->toDateString(),
            CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay(), 'UTC', [], 100,
        );
        $rows = app(CommentVolumeReport::class)->run($context)->rows;
        expect($rows)->toHaveCount(1)->and($rows[0]['received'])->toBe(2)->and($rows[0]['replied'])->toBe(1)
            ->and($rows[0]['response_minutes'])->toBe(20)->and($rows[0]['waiting'])->toBe(1);
    });
});
