<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Core\Settings\PlatformSettings;
use App\Core\Website\Enums\EnquiryStatus;
use App\Core\Website\Mail\WebsiteEnquiryMail;
use App\Core\Website\Models\WebsiteEnquiry;
use App\Core\Website\Models\WebsiteText;
use App\Platform\Enums\PlatformRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * The Super Admin panel's Website pages (docs/WEBSITE.md): the contact form's messages, the company's details and
 * the website's words, and the Bengali name of a plan.
 */

beforeEach(function () {
    config(['platform.registration.mode' => 'open']);

    $this->admin = $this->createPlatformUser();
    $this->support = $this->createPlatformUser(PlatformRole::SupportAdmin);
});

function enquiry(array $attributes = []): WebsiteEnquiry
{
    return WebsiteEnquiry::query()->create([
        'name' => 'Rahim Uddin',
        'email' => 'rahim@example.com',
        'phone' => '01700000000',
        'company' => 'Rahim Traders',
        'topic' => 'demo',
        'message' => 'We want payroll in Bengali.',
        'locale' => 'bn',
        'status' => EnquiryStatus::New,
        ...$attributes,
    ]);
}

describe('who may do what', function () {
    it('lets a support admin read the messages and nothing more', function () {
        $message = enquiry();

        $this->actingAsPlatformUser($this->support)->get('http://admin.localhost/website/messages')->assertOk();

        $this->post("http://admin.localhost/website/messages/{$message->id}/handled")->assertForbidden();
        $this->delete("http://admin.localhost/website/messages/{$message->id}")->assertForbidden();
        $this->get('http://admin.localhost/website/company')->assertForbidden();
        $this->get('http://admin.localhost/website/texts')->assertForbidden();
        $this->put('http://admin.localhost/website/texts/home', ['values' => ['en' => ['site.home.title' => 'No.']]])->assertForbidden();

        expect(WebsiteText::query()->count())->toBe(0)
            ->and($message->fresh()->status)->toBe(EnquiryStatus::New);
    });

    it('keeps signed-out visitors out', function () {
        $this->get('http://admin.localhost/website/messages')->assertRedirect('http://admin.localhost/login');
    });
});

describe('the messages', function () {
    it('lists what the contact form received, newest first, new ones by default', function () {
        Mail::fake();

        $this->post('http://localhost/contact', [
            'name' => 'Karim Ahmed', 'email' => 'karim@example.com', 'topic' => 'sales', 'message' => 'How much for 30 people?',
        ])->assertSessionHas('contact', 'sent');

        enquiry(['name' => 'Old answered', 'status' => EnquiryStatus::Handled, 'handled_at' => now()]);

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/website/messages')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Website/Messages')
                ->has('enquiries.data', 1)
                ->where('enquiries.data.0.name', 'Karim Ahmed')
                ->where('enquiries.data.0.locale', 'en')
                ->where('counts', ['new' => 1, 'handled' => 1])
                ->where('website', route('central.home')));

        $this->get('http://admin.localhost/website/messages?status=all&search=karim')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('enquiries.data', 1)->where('filters.status', 'all'));
    });

    it('marks a message answered and back, with who and when, in the audit log', function () {
        $message = enquiry();

        $this->actingAsPlatformUser($this->admin)->post("http://admin.localhost/website/messages/{$message->id}/handled")->assertRedirect();

        expect($message->fresh())
            ->status->toBe(EnquiryStatus::Handled)
            ->handled_by->toBe($this->admin->id)
            ->handled_at->not->toBeNull();

        $this->delete("http://admin.localhost/website/messages/{$message->id}/handled")->assertRedirect();

        expect($message->fresh())->status->toBe(EnquiryStatus::New)->handled_at->toBeNull();
        expect(AuditLog::withoutTenancy()->whereIn('event', ['platform.website.enquiry_handled', 'platform.website.enquiry_reopened'])->count())->toBe(2);
    });

    it('deletes a message and keeps only who deleted it', function () {
        $message = enquiry();

        $this->actingAsPlatformUser($this->admin)->delete("http://admin.localhost/website/messages/{$message->id}")->assertRedirect();

        expect(WebsiteEnquiry::query()->count())->toBe(0);

        $entry = AuditLog::withoutTenancy()->where('event', 'platform.website.enquiry_deleted')->sole();

        expect($entry->subject_label)->toBe('Rahim Uddin')
            ->and(json_encode($entry->properties))->not->toContain('payroll');
    });
});

describe('the company details', function () {
    it('shows the defaults, and the website follows what an operator saves', function () {
        Mail::fake();

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/website/company')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Website/Company')
                ->where('company.email', 'info@oyosend.com')
                ->where('company.phone_1', '+88 0179 22 88 555'));

        $this->put('http://admin.localhost/website/company', [
            'name' => 'Oyosend',
            'email' => 'hello@oyosend.com',
            'phone_1' => '+88 0190 00 00 000',
            'phone_2' => '',
            'street' => 'House 1, Road 2',
            'locality' => 'Banani',
            'city' => 'Dhaka',
            'postal_code' => '1213',
            'address_bn' => "বাড়ি ১, রোড ২\nবনানী, ঢাকা-১২১৩",
            'map_url' => '',
            'contact_recipient' => 'sales@oyosend.com',
            'social' => ['facebook' => 'https://www.facebook.com/oyosend', 'linkedin' => ''],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $home = $this->get('http://localhost/')
            ->assertSee('hello@oyosend.com')
            ->assertSee('+88 0190 00 00 000')
            ->assertDontSee('+88 018 292 33 333')
            ->assertSee('Banani, Dhaka-1213')
            ->assertSee('https://www.facebook.com/oyosend', escape: false);

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', (string) $home->getContent(), $json);
        $organization = collect(json_decode($json[1], true)['@graph'])->firstWhere('@type', 'Organization');

        expect($organization['sameAs'])->toBe(['https://www.facebook.com/oyosend'])
            ->and($organization['telephone'])->toBe('+8801900000000');

        $this->get('http://localhost/bn/contact')->assertSee('বনানী, ঢাকা-১২১৩');

        $this->post('http://localhost/contact', ['name' => 'A visitor', 'email' => 'a@example.com', 'topic' => 'other', 'message' => 'A question about the apps.']);
        Mail::assertQueued(WebsiteEnquiryMail::class, fn (WebsiteEnquiryMail $mail): bool => $mail->hasTo('sales@oyosend.com'));

        expect(AuditLog::withoutTenancy()->where('event', 'platform.website.company_updated')->count())->toBe(1);
    });

    it('checks what was typed', function (string $field, mixed $value, ?string $error = null) {
        $this->actingAsPlatformUser($this->admin)
            ->put('http://admin.localhost/website/company', [
                'name' => 'Oyosend', 'email' => 'info@oyosend.com', 'phone_1' => '+88 0179 22 88 555', 'street' => 'House 08', 'city' => 'Dhaka',
                'contact_recipient' => 'info@oyosend.com', $field => $value,
            ])
            ->assertSessionHasErrors($error ?? $field);
    })->with([
        'a phone with letters' => ['phone_1', 'call us'],
        'a map link without https' => ['map_url', 'http://maps.example.com'],
        'a recipient that is no address' => ['contact_recipient', 'sales'],
        'a page that is no link' => ['social', ['facebook' => 'oyosend'], 'social.facebook'],
    ]);
});

describe('the website words', function () {
    it('offers every part of the website, words only', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/website/texts')
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->component('Platform/Pages/Website/Texts')->where('section', 'home')->toArray()['props'];
                $keys = array_column($props['fields'], 'key');

                expect(array_column($props['sections'], 'key'))->toContain('home', 'frame', 'pricing', 'app.crm', 'app.whatsapp-business', 'privacy', 'refund')
                    ->and($keys)->toContain('site.home.title', 'site.home.faq.0.q')
                    // Icons and anchors are the page's structure, not words.
                    ->and($keys)->not->toContain('site.home.local.0.icon');
            });

        $this->get('http://admin.localhost/website/texts?section=app.crm')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('section', 'app.crm'));

        $this->get('http://admin.localhost/website/texts?section=nowhere')->assertNotFound();
    });

    it('changes a word on the website, in one language only, and puts it back', function () {
        $this->actingAsPlatformUser($this->admin)
            ->put('http://admin.localhost/website/texts/home', ['values' => [
                'en' => ['site.home.eyebrow' => 'Your business, in one place.', 'site.home.lede' => __('site.home.lede', [], 'en')],
                'bn' => ['site.home.eyebrow' => ''],
            ]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // A word equal to the website's own, or empty, is not stored.
        expect(WebsiteText::query()->pluck('value', 'key')->all())->toBe(['site.home.eyebrow' => 'Your business, in one place.']);

        $this->get('http://localhost/')->assertSee('Your business, in one place.');
        $this->get('http://localhost/bn')->assertSee(__('site.home.eyebrow', [], 'bn'))->assertDontSee('Your business, in one place.');

        $this->delete('http://admin.localhost/website/texts/home')->assertRedirect();

        expect(WebsiteText::query()->count())->toBe(0);
        $this->get('http://localhost/')->assertDontSee('Your business, in one place.');
        expect(AuditLog::withoutTenancy()->whereIn('event', ['platform.website.texts_updated', 'platform.website.texts_reset'])->count())->toBe(2);
    });

    it('refuses a word that drops what the page fills in, and anything that is not a word', function () {
        $this->actingAsPlatformUser($this->admin)
            ->put('http://admin.localhost/website/texts/home', ['values' => ['en' => ['site.home.apps_eyebrow' => 'Lots of apps']]])
            ->assertSessionHasErrors('en:site.home.apps_eyebrow');

        $this->put('http://admin.localhost/website/texts/home', ['values' => ['en' => [
            'site.home.local.0.icon' => 'skull',
            'site_pages.pricing.title' => 'Not in this part',
            'site.home.apps_eyebrow' => ':count apps in one place',
        ]]])->assertSessionHasNoErrors();

        expect(WebsiteText::query()->pluck('value', 'key')->all())->toBe(['site.home.apps_eyebrow' => ':count apps in one place']);
        $this->get('http://localhost/')->assertSee('17 apps in one place');
    });
});

describe('a plan in Bengali', function () {
    it('shows its Bengali name and description on the Bengali website', function () {
        $plan = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'description' => 'For a small team.']);

        $this->actingAsPlatformUser($this->admin)
            ->put("http://admin.localhost/plans/{$plan->id}", [
                'key' => 'starter', 'name' => 'Starter', 'name_bn' => 'স্টার্টার', 'description' => 'For a small team.', 'description_bn' => 'ছোট টিমের জন্য।',
                'currency' => 'BDT', 'monthly_amount' => 150000, 'yearly_amount' => null, 'trial_days' => 0,
                'modules' => [], 'limits' => [], 'is_public' => true, 'is_active' => true, 'sort_order' => 1,
            ])
            ->assertSessionHasNoErrors();

        expect($plan->fresh())->name_bn->toBe('স্টার্টার')->description_bn->toBe('ছোট টিমের জন্য।');

        $this->get('http://localhost/bn/pricing')->assertSee('স্টার্টার')->assertSee('ছোট টিমের জন্য।');
        $this->get('http://localhost/pricing')->assertSee('For a small team.')->assertDontSee('স্টার্টার');

        $this->get("http://admin.localhost/plans/{$plan->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('plan.nameBn', 'স্টার্টার'));
    });
});

describe('the logo and favicon', function () {
    beforeEach(function () {
        Storage::fake((string) config('site.brand.disk'));
    });

    it('uses an uploaded logo on the website and across the app, served from the central site', function () {
        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/website/brand/logo', ['file' => UploadedFile::fake()->image('logo.png', 400, 100)])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $file = app(PlatformSettings::class)->get('website.logo');
        $url = "http://localhost/brand/{$file}";

        expect($file)->toMatch('/^logo-[a-f0-9]{16}\.png$/');
        Storage::disk((string) config('site.brand.disk'))->assertExists("platform/brand/{$file}");

        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $home = $this->get('http://localhost/')->assertSee('src="'.$url.'"', escape: false);
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', (string) $home->getContent(), $json);
        expect(collect(json_decode($json[1], true)['@graph'])->firstWhere('@type', 'Organization')['logo']['url'])->toBe($url);

        $this->get('http://admin.localhost/website/company')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('app.brand.logo', $url)->where('brand.logo', $url));
    });

    it('uses an uploaded favicon on every host, and refuses one that is not square', function () {
        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/website/brand/favicon', ['file' => UploadedFile::fake()->image('wide.png', 64, 32)])
            ->assertSessionHasErrors('file');

        $this->post('http://admin.localhost/website/brand/favicon', ['file' => UploadedFile::fake()->image('icon.png', 512, 512)])
            ->assertSessionHasNoErrors();

        $url = 'http://localhost/brand/'.app(PlatformSettings::class)->get('website.favicon');

        $this->get('http://localhost/')->assertSee('<link rel="icon" type="image/png" href="'.$url.'">', escape: false);
        $this->get('http://admin.localhost/website/messages')->assertSee('<link rel="icon" type="image/png" href="'.$url.'">', escape: false);
    });

    it('takes raster images only', function (string $asset, UploadedFile $file) {
        $this->actingAsPlatformUser($this->admin)
            ->post("http://admin.localhost/website/brand/{$asset}", ['file' => $file])
            ->assertSessionHasErrors('file');

        expect(app(PlatformSettings::class)->get("website.{$asset}"))->toBeNull();
    })->with([
        'an SVG logo' => fn () => ['logo', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
        'a JPG favicon' => fn () => ['favicon', UploadedFile::fake()->image('icon.jpg', 64, 64)],
        'a logo too large' => fn () => ['logo', UploadedFile::fake()->image('logo.png', 400, 100)->size(2048)],
    ]);

    it('brings back the built-in image and lets the old file go', function () {
        $this->actingAsPlatformUser($this->admin)->post('http://admin.localhost/website/brand/logo', ['file' => UploadedFile::fake()->image('logo.png', 400, 100)]);
        $file = app(PlatformSettings::class)->get('website.logo');

        $this->delete('http://admin.localhost/website/brand/logo')->assertRedirect();

        expect(app(PlatformSettings::class)->get('website.logo'))->toBeNull();
        Storage::disk((string) config('site.brand.disk'))->assertMissing("platform/brand/{$file}");
        $this->get("http://localhost/brand/{$file}")->assertNotFound();
        $this->get('http://localhost/')->assertDontSee('/brand/logo-', escape: false)->assertSee('>oyosend</span>', escape: false);
        expect(AuditLog::withoutTenancy()->whereIn('event', ['platform.website.brand_updated', 'platform.website.brand_removed'])->count())->toBe(2);
    });

    it('lets only those who manage the website change it, and serves nothing it did not write', function () {
        $this->actingAsPlatformUser($this->support)
            ->post('http://admin.localhost/website/brand/logo', ['file' => UploadedFile::fake()->image('logo.png', 400, 100)])
            ->assertForbidden();

        $this->get('http://localhost/brand/logo-0123456789abcdef.png')->assertNotFound();
        $this->get('http://localhost/brand/..%2F.env')->assertNotFound();
        $this->actingAsPlatformUser($this->admin)->post('http://admin.localhost/website/brand/banner', ['file' => UploadedFile::fake()->image('b.png', 400, 100)])->assertNotFound();
    });
});
