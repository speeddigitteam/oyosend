<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\ChannelEventListener;
use App\Core\Communication\Contracts\ChannelNumberRegistry;
use App\Core\Communication\Contracts\MediaGateway;
use App\Core\Communication\Data\ChannelEvent;
use App\Core\Communication\Data\InboundMedia;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\Whatsapp\WhatsappMediaType;
use App\Core\Communication\NullMediaGateway;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\MediaAsset;
use App\Modules\Communication\Models\MediaUpload;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

/** Keeps the inbound events so a test can act on them like a listener would. */
final class MediaEventCollector implements ChannelEventListener
{
    /** @var list<ChannelEvent> */
    public static array $events = [];

    public function handle(ChannelEvent $event): void
    {
        self::$events[] = $event;
    }
}

const WM_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function wmPng(): string
{
    return (string) base64_decode(WM_PNG, true);
}

function wmPdf(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}

function wmMedia(): MediaGateway
{
    return app(MediaGateway::class);
}

function wmFreshHttp(): void
{
    Http::swap(new Factory);
}

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    MediaEventCollector::$events = [];
    $this->app->tag([MediaEventCollector::class], ChannelEventListener::TAG);
    $this->tenant = $this->waTenant('media-ws');
    $this->waConnectCloud($this->tenant);
});

it('stores an image after sniffing it, under a generated name in the tenant storage', function () {
    $outcome = $this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Image, wmPng(), '../../etc/passwd.png', 'image/png'));

    expect($outcome->ok)->toBeTrue()
        ->and($outcome->media->type)->toBe('image')
        ->and($outcome->media->mimeType)->toBe('image/png')
        ->and($outcome->media->sizeBytes)->toBe(strlen(wmPng()))
        ->and($outcome->media->sha256)->toBe(hash('sha256', wmPng()))
        ->and($outcome->media->direction)->toBe('outbound')
        ->and($outcome->media->filename)->toBe('passwd.png');

    $this->inTenant($this->tenant, function () use ($outcome): void {
        $asset = MediaAsset::query()->findOrFail($outcome->media->id);

        expect($asset->storage_path)->toMatch('#^communication/media/[0-9A-Za-z]{26}\.png$#')
            ->and(app(TenantStorage::class)->exists($asset->storage_path))->toBeTrue()
            ->and($asset->toArray())->not->toHaveKey('storage_path');
    });

    expect(json_encode($outcome))->not->toContain('communication/media');
});

it('refuses disguised, unsupported, empty and oversized files and stores nothing', function () {
    $html = '<html><script>alert(1)</script></html>';

    $cases = [
        'html named png' => [WhatsappMediaType::Image, $html, 'x.png', 'image/png', 'unsupported_media_type'],
        'png sent as video' => [WhatsappMediaType::Video, wmPng(), 'x.mp4', 'video/mp4', 'content_mismatch'],
        'png declared jpeg' => [WhatsappMediaType::Image, wmPng(), 'x.jpg', 'image/jpeg', 'content_mismatch'],
        'pdf as image' => [WhatsappMediaType::Image, wmPdf(), 'x.png', null, 'content_mismatch'],
        'png as document' => [WhatsappMediaType::Document, wmPng(), 'x.pdf', 'application/pdf', 'content_mismatch'],
        'executable bytes' => [WhatsappMediaType::Document, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00", 'x.pdf', 'application/pdf', 'unsupported_media_type'],
        'empty' => [WhatsappMediaType::Image, '', 'x.png', null, 'empty_file'],
    ];

    foreach ($cases as $name => [$type, $bytes, $filename, $mime, $reason]) {
        $outcome = $this->inTenant($this->tenant, fn () => wmMedia()->store($type, $bytes, $filename, $mime));
        expect($outcome->ok)->toBeFalse($name)->and($outcome->reason)->toBeIn([$reason, 'content_mismatch', 'unsupported_media_type'], $name);
    }

    config(['communication.whatsapp.media.max_bytes.image' => 50]);
    $big = $this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Image, wmPng().str_repeat('0', 100), 'big.png'));
    expect($big->reason)->toBe('too_large');

    $this->inTenant($this->tenant, fn () => expect(MediaAsset::query()->count())->toBe(0));
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('accepts a PDF and Office documents by their container only when the family agrees', function () {
    $pdf = $this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Document, wmPdf(), 'invoice.pdf'));
    expect($pdf->ok)->toBeTrue()->and($pdf->media->mimeType)->toBe('application/pdf');

    $zipPath = tempnam(sys_get_temp_dir(), 'wf');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
    $zip->addFromString('word/document.xml', '<w:document/>');
    $zip->close();
    $docx = (string) file_get_contents($zipPath);
    @unlink($zipPath);

    $ok = $this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Document, $docx, 'letter.docx'));
    expect($ok->ok)->toBeTrue()->and($ok->media->mimeType)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    // A ZIP with a document name but an image-type claim, or no Office hint at all, is not a document.
    expect($this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Document, $docx, 'archive.zip'))->ok)->toBeFalse()
        ->and($this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Document, $docx, 'letter.docx', 'application/pdf'))->ok)->toBeFalse()
        ->and($this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Image, $docx, 'letter.png'))->ok)->toBeFalse();
});

it('registers a file already in the tenant storage and refuses paths that leave the workspace', function () {
    $this->inTenant($this->tenant, function (): void {
        app(TenantStorage::class)->put('docs/receipt.pdf', wmPdf());

        $ok = wmMedia()->storeFromTenantFile(WhatsappMediaType::Document, 'docs/receipt.pdf');
        expect($ok->ok)->toBeTrue()->and($ok->media->filename)->toBe('receipt.pdf');

        expect(wmMedia()->storeFromTenantFile(WhatsappMediaType::Document, 'docs/missing.pdf')->reason)->toBe('not_found')
            ->and(wmMedia()->storeFromTenantFile(WhatsappMediaType::Document, '../other-tenant/secret.pdf')->reason)->toBe('not_found');
    });
});

it('gives the bytes back with headers that cannot run anything, and never to another workspace', function () {
    $stored = $this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Document, wmPdf(), 'My report (final).pdf'));

    $contents = $this->inTenant($this->tenant, fn () => wmMedia()->read($stored->media->id));

    expect($contents->bytes)->toBe(wmPdf())
        ->and($contents->headers())->toMatchArray([
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ])
        ->and($contents->headers()['Content-Disposition'])->toBe('attachment; filename="My_report_final_.pdf"');

    $other = $this->waTenant('media-other');
    expect($this->inTenant($other, fn () => wmMedia()->read($stored->media->id)))->toBeNull();
    $this->assertTenantIsolated(MediaAsset::class, fn () => MediaAsset::query()->create([
        'direction' => 'outbound', 'type' => 'image', 'mime' => 'image/png', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'storage_path' => 'communication/media/x.png',
    ]));
});

it('uploads a stored file for a number once and reuses the provider id', function () {
    $stored = $this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Image, wmPng(), 'logo.png'));
    wmFreshHttp();
    Http::fake(['graph.facebook.com/*/media' => Http::response(['id' => 'PROVIDER-MEDIA-1'], 200)]);

    $first = $this->inTenant($this->tenant, fn () => wmMedia()->upload($stored->media->id));
    $second = $this->inTenant($this->tenant, fn () => wmMedia()->upload($stored->media->id));

    expect($first->ok)->toBeTrue()->and($first->media->providerMediaId)->toBe('PROVIDER-MEDIA-1')->and($second->media->providerMediaId)->toBe('PROVIDER-MEDIA-1');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://graph.facebook.com/v21.0/109876543210/media'
        && $request->hasHeader('Authorization', 'Bearer EAAtest-access-token')
        && $request->isMultipart());
});

it('uploads for the named number and keeps a provider id per number', function () {
    $second = $this->inTenant($this->tenant, fn () => app(ChannelNumberRegistry::class)->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'))->number;
    $stored = $this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Image, wmPng(), 'logo.png'));
    wmFreshHttp();
    Http::fake(['graph.facebook.com/*/media' => Http::sequence()->push(['id' => 'FOR-MAIN'])->push(['id' => 'FOR-SUPPORT'])]);

    $main = $this->inTenant($this->tenant, fn () => wmMedia()->upload($stored->media->id));
    $support = $this->inTenant($this->tenant, fn () => wmMedia()->upload($stored->media->id, $second->id));

    expect($main->media->providerMediaId)->toBe('FOR-MAIN')->and($support->media->providerMediaId)->toBe('FOR-SUPPORT');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/209876543210/media'));
    $this->inTenant($this->tenant, fn () => expect(MediaUpload::query()->count())->toBe(2));

    expect($this->inTenant($this->tenant, fn () => wmMedia()->upload($stored->media->id, '01HZ0000000000000000000099'))->reason)->toBe('number_unavailable')
        ->and($this->inTenant($this->tenant, fn () => wmMedia()->upload('01HZ0000000000000000000098'))->reason)->toBe('not_found');
});

it('re-uploads once the provider id is stale and reports provider failures honestly', function () {
    $stored = $this->inTenant($this->tenant, fn () => wmMedia()->store(WhatsappMediaType::Image, wmPng(), 'logo.png'));
    wmFreshHttp();
    Http::fake(['graph.facebook.com/*/media' => Http::sequence()->push(['id' => 'OLD'])->push(['id' => 'NEW'])->push(['error' => ['code' => 100, 'message' => 'bad']], 400)->push('x', 503)]);

    $this->inTenant($this->tenant, fn () => wmMedia()->upload($stored->media->id));
    $this->inTenant($this->tenant, fn () => MediaUpload::query()->update(['expires_at' => now()->subMinute()]));

    expect($this->inTenant($this->tenant, fn () => wmMedia()->upload($stored->media->id))->media->providerMediaId)->toBe('NEW');

    $this->inTenant($this->tenant, fn () => MediaUpload::query()->delete());
    expect($this->inTenant($this->tenant, fn () => wmMedia()->upload($stored->media->id))->reason)->toBe('provider_rejected');
    expect($this->inTenant($this->tenant, fn () => wmMedia()->upload($stored->media->id))->reason)->toBe('provider_unavailable');
});

it('says provider_not_configured without a usable connection and module_unavailable without entitlement', function () {
    expect($this->inTenant($this->tenant, fn () => wmMedia()->availability())->isAvailable())->toBeTrue();

    $this->inTenant($this->tenant, fn () => ChannelConnection::query()->update(['credential_ref' => null]));
    expect($this->inTenant($this->tenant, fn () => wmMedia()->availability())->isAvailable())->toBeFalse();

    $bare = $this->waTenant('media-bare');
    $stored = $this->inTenant($bare, fn () => wmMedia()->store(WhatsappMediaType::Image, wmPng()));

    expect($stored->ok)->toBeTrue()
        ->and($this->inTenant($bare, fn () => wmMedia()->upload($stored->media->id))->reason)->toBe('provider_not_configured')
        ->and($this->inTenant($bare, fn () => wmMedia()->availability())->reason)->toBe('provider_not_configured')
        ->and($this->inTenant($bare, fn () => wmMedia()->download(new InboundMedia('image', 'M1', 'image/png')))->reason)->toBe('provider_not_configured');

    $plain = $this->createTenant('media-plain');
    expect($this->inTenant($plain, fn () => wmMedia()->store(WhatsappMediaType::Image, wmPng()))->reason)->toBe('module_unavailable')
        ->and($this->inTenant($plain, fn () => wmMedia()->availability())->reason)->toBe('module_unavailable')
        ->and($this->inTenant($plain, fn () => wmMedia()->read('x')))->toBeNull();

});

it('downloads an inbound image into the tenant storage, checks it and does it only once', function () {
    wmFreshHttp();
    Http::fake([
        'graph.facebook.com/v21.0/MEDIA1*' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=MEDIA1', 'mime_type' => 'image/png', 'sha256' => hash('sha256', wmPng()), 'file_size' => strlen(wmPng()), 'id' => 'MEDIA1'], 200),
        'lookaside.fbsbx.com/*' => Http::response(wmPng(), 200, ['Content-Type' => 'image/png']),
    ]);

    $first = $this->inTenant($this->tenant, fn () => wmMedia()->download(new InboundMedia('image', 'MEDIA1', 'image/png', hash('sha256', wmPng()), 'Receipt')));
    $second = $this->inTenant($this->tenant, fn () => wmMedia()->download(new InboundMedia('image', 'MEDIA1', 'image/png')));

    expect($first->ok)->toBeTrue()
        ->and($first->media->direction)->toBe('inbound')
        ->and($first->media->providerMediaId)->toBe('MEDIA1')
        ->and($first->media->mimeType)->toBe('image/png')
        ->and($second->media->id)->toBe($first->media->id);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://graph.facebook.com/v21.0/MEDIA1') && $request['phone_number_id'] === '109876543210');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=MEDIA1' && $request->hasHeader('Authorization', 'Bearer EAAtest-access-token'));

    expect($this->inTenant($this->tenant, fn () => wmMedia()->read($first->media->id))->bytes)->toBe(wmPng());
});

it('never follows a download URL outside the provider hosts', function () {
    foreach (['https://evil.example/a.png', 'http://lookaside.fbsbx.com/a.png', 'https://169.254.169.254/latest/meta-data', 'https://lookaside.fbsbx.com:8443/a.png', 'https://localhost/a.png', 'https://fbsbx.com.evil.example/a.png', 'file:///etc/passwd'] as $index => $url) {
        wmFreshHttp();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => $url, 'mime_type' => 'image/png', 'file_size' => 10, 'id' => 'M'], 200),
            '*' => Http::response(wmPng(), 200),
        ]);

        $outcome = $this->inTenant($this->tenant, fn () => wmMedia()->download(new InboundMedia('image', 'M'.$index, 'image/png')));

        expect($outcome->ok)->toBeFalse()->and($outcome->reason)->toBe('provider_rejected');
        Http::assertSentCount(1);
    }
});

it('refuses an oversized, mismatching or disguised download and keeps nothing', function () {
    $serve = function (string $bytes, array $meta): void {
        wmFreshHttp();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/png', 'id' => 'M', ...$meta], 200),
            'lookaside.fbsbx.com/*' => Http::response($bytes, 200),
        ]);
    };

    $serve(wmPng(), ['file_size' => 99999999]);
    expect($this->inTenant($this->tenant, fn () => wmMedia()->download(new InboundMedia('image', 'M1', 'image/png')))->reason)->toBe('too_large');

    $serve(wmPng().str_repeat('0', 6 * 1024 * 1024), []);
    expect($this->inTenant($this->tenant, fn () => wmMedia()->download(new InboundMedia('image', 'M2', 'image/png')))->reason)->toBe('too_large');

    $serve(wmPng(), ['sha256' => str_repeat('b', 64), 'file_size' => strlen(wmPng())]);
    expect($this->inTenant($this->tenant, fn () => wmMedia()->download(new InboundMedia('image', 'M3', 'image/png')))->reason)->toBe('content_mismatch');

    $serve('<html><script>alert(1)</script></html>', ['file_size' => 40]);
    expect($this->inTenant($this->tenant, fn () => wmMedia()->download(new InboundMedia('image', 'M4', 'image/png')))->ok)->toBeFalse();

    $serve(wmPdf(), ['mime_type' => 'image/png', 'file_size' => 80]);
    expect($this->inTenant($this->tenant, fn () => wmMedia()->download(new InboundMedia('image', 'M5', 'image/png')))->ok)->toBeFalse();

    expect($this->inTenant($this->tenant, fn () => wmMedia()->download(new InboundMedia('contact', 'M6', 'text/x-vcard')))->reason)->toBe('unsupported_media_type');

    $this->inTenant($this->tenant, fn () => expect(MediaAsset::query()->count())->toBe(0));
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('maps provider download failures', function () {
    foreach ([[401, 'provider_authentication_failed'], [503, 'provider_unavailable'], [404, 'provider_rejected']] as $index => [$status, $reason]) {
        wmFreshHttp();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => $status === 401 ? 190 : 100, 'message' => 'x']], $status)]);

        expect($this->inTenant($this->tenant, fn () => wmMedia()->download(new InboundMedia('image', 'F'.$index)))->reason)->toBe($reason);
    }
});

it('lets a listener download the media of an inbound message end to end', function () {
    wmFreshHttp();
    Http::fake([
        'graph.facebook.com/v21.0/WAMEDIA9*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/jpeg', 'file_size' => 24, 'id' => 'WAMEDIA9'], 200),
        'lookaside.fbsbx.com/*' => Http::response("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9", 200),
    ]);

    $this->waWebhook($this->tenant, $this->waMessagePayload('109876543210', [[
        'from' => '8801711000001', 'id' => 'wamid.IN-IMG', 'timestamp' => (string) now()->timestamp, 'type' => 'image',
        'image' => ['id' => 'WAMEDIA9', 'mime_type' => 'image/jpeg', 'caption' => 'Look'],
    ]], '8801711000001'))->assertOk();

    $event = MediaEventCollector::$events[0];
    expect($event->content->media->providerMediaId)->toBe('WAMEDIA9')
        ->and($event->content->media->mimeType)->toBe('image/jpeg')
        ->and($event->text)->toBe('Look')
        ->and($event->numberId)->not->toBeNull();

    $stored = $this->inTenant($this->tenant, fn () => wmMedia()->download($event->content->media, $event->numberId));
    expect($stored->ok)->toBeTrue()->and($stored->media->mimeType)->toBe('image/jpeg')->and($stored->media->numberId)->toBe($event->numberId);
});

it('has an honest null implementation', function () {
    $null = new NullMediaGateway;

    expect($null->availability()->isAvailable())->toBeFalse()
        ->and($null->store(WhatsappMediaType::Image, wmPng())->reason)->toBe('module_unavailable')
        ->and($null->upload('x')->ok)->toBeFalse()
        ->and($null->download(new InboundMedia('image', 'x'))->ok)->toBeFalse()
        ->and($null->read('x'))->toBeNull();
});
