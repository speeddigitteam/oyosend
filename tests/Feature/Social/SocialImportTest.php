<?php

declare(strict_types=1);

use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\Import;
use App\Modules\Social\Models\Post;
use Illuminate\Http\UploadedFile;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    $this->tenant = $this->socialTenant('soc-import');
    $this->fakePlatform();
    $this->account = $this->socialAccount($this->tenant, ['name' => 'Main Page', 'handle' => '@main']);
    $this->socialAccount($this->tenant, ['name' => 'Twin', 'external_id' => 'twin-1']);
    $this->socialAccount($this->tenant, ['name' => 'Twin', 'external_id' => 'twin-2']);
    $this->inTenant($this->tenant, fn () => Campaign::query()->create(['name' => 'Spring', 'status' => 'active']));
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
    $this->csv = fn (string $content, string $name = 'posts.csv') => UploadedFile::fake()->createWithContent($name, $content);
    $this->import = fn (string $content, string $mode = 'draft') => $this->post(($this->url)('/imports'), [
        'file' => ($this->csv)($content),
        'mode' => $mode,
    ]);
    $this->actingAsOwner($this->tenant);
});

it('imports valid rows as drafts with their planned time converted from the workspace timezone', function () {
    $content = "body,accounts,scheduled_at,title,campaign\n"
        ."\"Hello, world\nsecond line\",Main Page,2030-03-05 09:30,First,Spring\n"
        ."Plain post,@main;Main Page,,,\n";

    ($this->import)($content)->assertRedirect(($this->url)('/imports'));

    $this->inTenant($this->tenant, function (): void {
        $posts = Post::query()->with('campaign')->orderBy('created_at')->orderBy('body')->get();
        $import = Import::query()->sole();

        expect($posts)->toHaveCount(2)
            ->and($import->total_rows)->toBe(2)->and($import->created_rows)->toBe(2)->and($import->failed_rows)->toBe(0)
            ->and($posts->every(fn (Post $post): bool => $post->status === PostStatus::Draft && $post->import_id === $import->id))->toBeTrue();

        $first = $posts->firstWhere('title', 'First');
        expect($first->body)->toBe("Hello, world\nsecond line")
            // Dhaka UTC+6.
            ->and($first->scheduled_for?->utc()->format('Y-m-d H:i'))->toBe('2030-03-05 03:30')
            ->and($first->campaign?->name)->toBe('Spring');
        // Two labels for the same account collapse to one target.
        expect($posts->firstWhere('title', null)->targets()->count())->toBe(1);
    });
});

it('reports every bad row by line and keeps the good ones', function () {
    $content = "body,accounts,scheduled_at,campaign,link_url\n"
        ."Good,Main Page,2030-01-01 10:00,,\n"                       // line 2
        .",Main Page,,,\n"                                           // line 3 empty body
        ."No account,Nobody,,,\n"                                    // line 4
        ."Ambiguous,Twin,,,\n"                                       // line 5
        ."Bad date,Main Page,01/02/2030,,\n"                         // line 6
        ."Bad campaign,Main Page,,Winter,\n"                         // line 7
        ."Bad link,Main Page,,,javascript:alert(1)\n"                // line 8
        .'"Control '."\x07".' char",Main Page,,,'."\n";              // line 9

    ($this->import)($content);

    $import = $this->inTenant($this->tenant, fn () => Import::query()->sole());
    $lines = collect($import->errors)->pluck('line')->all();

    expect($import->created_rows)->toBe(1)->and($import->failed_rows)->toBe(7)->and($lines)->toBe([3, 4, 5, 6, 7, 8, 9]);
    $this->inTenant($this->tenant, fn () => expect(Post::query()->count())->toBe(1));
});

it('refuses a file that is not a clean UTF-8 CSV with the expected columns', function () {
    $cases = [
        'binary' => "body,accounts\nhi,Main Page\n\0\0",
        'latin1' => "body,accounts\ncaf\xE9,Main Page\n",
        'unknown column' => "body,accounts,evil\nhi,Main Page,x\n",
        'duplicate column' => "body,accounts,body\nhi,Main Page,x\n",
        'missing accounts' => "body\nhi\n",
        'empty' => '',
        'header only' => "body,accounts\n",
    ];

    foreach ($cases as $content) {
        ($this->import)($content)->assertSessionHasErrors('file');
    }

    $this->inTenant($this->tenant, fn () => expect(Post::query()->count())->toBe(0)->and(Import::query()->count())->toBe(0));
});

it('caps the number of rows and the size of a file', function () {
    config(['social.import.max_rows' => 2]);
    ($this->import)("body,accounts\na,Main Page\nb,Main Page\nc,Main Page\n")->assertSessionHasErrors('file');

    config(['social.import.max_rows' => 500, 'social.import.max_bytes' => 100]);
    ($this->import)("body,accounts\n".str_repeat("x,Main Page\n", 50))->assertSessionHasErrors('file');

    $this->inTenant($this->tenant, fn () => expect(Post::query()->count())->toBe(0));
});

it('does not import the same file twice', function () {
    $content = "body,accounts\nOnce only,Main Page\n";

    ($this->import)($content)->assertRedirect();
    ($this->import)($content)->assertSessionHasErrors('file');

    $this->inTenant($this->tenant, fn () => expect(Post::query()->count())->toBe(1));
});

it('stores formula-looking text as plain text and neutralises it in every CSV it hands out', function () {
    ($this->import)("body,accounts,title\n=HYPERLINK(\"http://evil.test\"),Main Page,@SUM(1+1)\n");

    $post = $this->inTenant($this->tenant, fn () => Post::query()->sole());
    expect($post->body)->toBe('=HYPERLINK("http://evil.test")')->and($post->title)->toBe('@SUM(1+1)');

    $export = $this->get(($this->url)('/posts/export'))->assertOk()->streamedContent();
    expect($export)->toContain("'@SUM(1+1)")->and($export)->toContain("'=HYPERLINK(")
        ->and($export)->not->toMatch('/(^|,)=HYPERLINK/m');
});

it('serves a template with only the supported columns', function () {
    $content = $this->get(($this->url)('/imports/template'))->assertOk()->streamedContent();

    expect(ltrim(explode("\n", $content)[0], "\xEF\xBB\xBF"))->toBe('body,accounts,scheduled_at,title,link_url,campaign,category,hashtag_set,media');
});

it('schedules rows when asked to, and sends them for approval when the workspace requires it', function () {
    // The queue only accepts a time within a year; "10:00" in Dhaka is 04:00 UTC.
    $when = fn (int $days): string => now('Asia/Dhaka')->addDays($days)->format('Y-m-d').' 10:00';
    $content = "body,accounts,scheduled_at\nGo live,Main Page,".$when(30)."\n";

    ($this->import)($content, 'schedule');

    $this->inTenant($this->tenant, function (): void {
        $post = Post::query()->sole();
        expect($post->status)->toBe(PostStatus::Scheduled)->and($post->scheduled_for?->utc()->format('H:i'))->toBe('04:00');
    });

    $this->inTenant($this->tenant, fn () => app(SocialSettings::class)->update(['require_approval' => true]));
    ($this->import)("body,accounts,scheduled_at\nNeeds review,Main Page,".$when(31)."\n", 'schedule');

    $this->inTenant($this->tenant, fn () => expect(Post::query()->where('body', 'Needs review')->sole()->status)->toBe(PostStatus::PendingApproval));

    // A row with no time cannot be scheduled.
    ($this->import)("body,accounts\nNo time,Main Page\n", 'schedule');
    $this->inTenant($this->tenant, fn () => expect(Post::query()->where('body', 'No time')->exists())->toBeFalse()
        ->and(Import::query()->orderByDesc('id')->first()->failed_rows)->toBe(1));
});

it('lets a member without the schedule permission import drafts only', function () {
    [$member] = $this->socialMember($this->tenant, ['social.imports.create', 'social.posts.create']);
    $this->actingAsMember($this->tenant, $member);
    $content = "body,accounts,scheduled_at\nTry,Main Page,".now('Asia/Dhaka')->addDays(30)->format('Y-m-d')." 10:00\n";

    ($this->import)($content, 'schedule')->assertSessionHasErrors('mode');
    ($this->import)($content, 'draft')->assertRedirect();

    $this->inTenant($this->tenant, fn () => expect(Post::query()->sole()->status)->toBe(PostStatus::Draft));
});
