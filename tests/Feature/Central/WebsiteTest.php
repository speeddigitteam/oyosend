<?php

declare(strict_types=1);

use App\Core\Billing\Models\Plan;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\PlatformSettings;
use App\Core\Website\Mail\WebsiteEnquiryMail;
use App\Core\Website\Models\WebsiteEnquiry;
use App\Core\Website\SiteApp;
use App\Core\Website\SiteApps;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * The public website (docs/WEBSITE.md): server-rendered pages in English at the root and Bengali under /bn, each
 * telling search and answer engines what it is, plus robots.txt, sitemap.xml, llms.txt and the contact form.
 */

const WEBSITE_PAGES = ['', 'apps', 'pricing', 'security', 'about', 'faq', 'contact', 'signin', 'privacy', 'terms', 'refund-policy'];

/** @return array<string, mixed> the page's JSON-LD document */
function jsonLd(TestResponse $response): array
{
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', (string) $response->getContent(), $match);

    return json_decode($match[1] ?? 'null', true, flags: JSON_THROW_ON_ERROR) ?? [];
}

/** @return list<string> the @type of every node in the page's structured data */
function schemaTypes(TestResponse $response): array
{
    return array_column(jsonLd($response)['@graph'] ?? [], '@type');
}

/** @return array<string, mixed> the key structure of a language file, values dropped */
function shapeOf(mixed $value): mixed
{
    return is_array($value) ? array_map(shapeOf(...), $value) : null;
}

beforeEach(function () {
    config(['platform.registration.mode' => 'open']);
});

describe('every page', function () {
    it('renders in English and Bengali, says which is which and links the two', function (string $path) {
        foreach (['en' => '', 'bn' => '/bn'] as $locale => $prefix) {
            // The canonical address has no trailing slash, the home page included ("http://localhost").
            $url = 'http://localhost'.$prefix.($path === '' ? '' : '/'.$path);
            $response = $this->get($url === 'http://localhost' ? 'http://localhost/' : $url)->assertOk();
            $html = (string) $response->getContent();

            expect($html)->toContain('<html lang="'.$locale.'"')
                ->and(substr_count($html, '<h1'))->toBe(1)
                ->and($html)->toContain('<link rel="canonical" href="'.$url.'">')
                ->and($html)->toContain('hreflang="en"', 'hreflang="bn"', 'hreflang="x-default"')
                ->and($html)->toContain('<meta name="description"', 'property="og:image"', 'name="twitter:card"')
                ->and(schemaTypes($response))->toContain('Organization', 'WebSite');

            // Raw keys would mean a missing word in one of the languages.
            expect($html)->not->toMatch('/\b(site|site_pages|site_apps|site_legal)\.[a-z_]+\.[a-z_]/');
        }
    })->with(WEBSITE_PAGES);

    it('tells search engines who the company is and how to reach it', function () {
        $organization = collect(jsonLd($this->get('http://localhost/'))['@graph'])->firstWhere('@type', 'Organization');

        expect($organization)->toMatchArray([
            'name' => 'Oyosend',
            'email' => 'info@oyosend.com',
            'telephone' => '+8801792288555',
        ])
            ->and($organization['address'])->toMatchArray(['postalCode' => '1230', 'addressCountry' => 'BD'])
            ->and(array_column($organization['contactPoint'], 'telephone'))->toBe(['+8801792288555', '+8801829233333']);
    });

    it('answers its questions in the page and in FAQPage structured data', function (string $path) {
        $response = $this->get('http://localhost/'.$path);
        $faq = collect(jsonLd($response)['@graph'])->firstWhere('@type', 'FAQPage');

        expect($faq['mainEntity'])->not->toBeEmpty();

        foreach ($faq['mainEntity'] as $question) {
            $response->assertSee($question['name'])->assertSee($question['acceptedAnswer']['text']);
        }
    })->with(['', 'pricing', 'security', 'faq', 'apps/crm']);

    it('remembers the language being read, for the sign-up pages that follow', function () {
        $this->get('http://localhost/bn/pricing')->assertCookie('locale', 'bn');
        $this->withCookie('locale', 'bn')->get('http://localhost/pricing')->assertCookie('locale', 'en');
    });

    it('stays in the language of its address whatever the visitor chose before', function () {
        $this->withCookie('locale', 'bn')->get('http://localhost/about')->assertSee('<html lang="en"', escape: false);
    });

    it('exists on the central site only', function () {
        $this->createTenant('acme');

        $this->get('http://acme.localhost/pricing')->assertNotFound();
        $this->get('http://admin.localhost/pricing')->assertNotFound();
    });
});

describe('the apps', function () {
    it('gives every optional module of this build its own page, and nothing else', function () {
        $modules = collect(app(ModuleRegistry::class)->all())->reject(fn ($manifest) => $manifest->core)->pluck('key')->sort()->values()->all();
        $apps = collect(app(SiteApps::class)->all())->pluck('module')->sort()->values()->all();

        expect($apps)->toBe($modules);
    });

    it('renders each app page with its words, its software and its breadcrumbs', function () {
        foreach (app(SiteApps::class)->all() as $app) {
            /** @var SiteApp $app */
            foreach (['', '/bn'] as $prefix) {
                $response = $this->get("http://localhost{$prefix}/apps/{$app->slug}")->assertOk();

                expect(schemaTypes($response))->toContain('SoftwareApplication', 'BreadcrumbList', 'FAQPage');
            }

            $this->get("http://localhost/apps/{$app->slug}")->assertSee(__("site_apps.items.{$app->module}.headline", [], 'en'));
        }
    });

    it('answers 404 for an app it does not have', function () {
        $this->get('http://localhost/apps/spaceship')->assertNotFound();
        $this->get('http://localhost/bn/apps/spaceship')->assertNotFound();
    });

    it('prices an app from its own App Store offers', function () {
        $this->get('http://localhost/pricing')->assertSee('৳1,990 a month, 14-day free trial');
        $this->get('http://localhost/bn/pricing')->assertSee('মাসে ৳১,৯৯০');
        $this->get('http://localhost/apps/ai-assistant')->assertSee('From ৳1,990 a month');
    });
});

describe('plans and prices', function () {
    beforeEach(function () {
        $this->createPlan(['key' => 'business', 'name' => 'Business', 'sort_order' => 3, 'monthly_amount' => 450000, 'yearly_amount' => 4500000]);
        $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'description' => 'For a small team.', 'sort_order' => 2, 'trial_days' => 14, 'modules' => ['crm'], 'limits' => ['core.members' => 5]]);
        $this->createFreePlan(['key' => 'free', 'name' => 'Free', 'sort_order' => 1]);
        $this->createPlan(['key' => 'annual', 'name' => 'Annual', 'sort_order' => 4, 'monthly_amount' => null, 'yearly_amount' => 990000]);

        // Never offered: assigned from the Super Admin panel only, or no longer sold.
        $this->createPlan(['key' => 'partner', 'name' => 'Partner plan', 'is_public' => false]);
        $this->createPlan(['key' => 'legacy', 'name' => 'Legacy plan', 'is_active' => false]);
    });

    it('shows the plans on offer, in the operator\'s order, each linked to sign-up', function (string $mode) {
        app(PlatformSettings::class)->put(['registration.mode' => $mode]);

        $response = $this->get('http://localhost/pricing')
            ->assertOk()
            ->assertSeeInOrder(['Free', 'Starter', 'Business', 'Annual'])
            ->assertSee('For a small team.')
            ->assertSee('৳1,500')
            ->assertSee('৳15,000 per year')
            ->assertSee('14-day free trial')
            ->assertSee('Up to 5 members')
            ->assertSee('1 app')
            ->assertSee('৳9,900')
            ->assertSee('http://localhost/register?plan=starter', escape: false)
            ->assertDontSee('Partner plan')
            ->assertDontSee('Legacy plan');

        $software = collect(jsonLd($response)['@graph'])->firstWhere('@type', 'SoftwareApplication');

        expect($software['offers'])->toMatchArray(['@type' => 'AggregateOffer', 'priceCurrency' => 'BDT', 'lowPrice' => '0.00', 'highPrice' => '4500.00', 'offerCount' => 3]);

        $this->get('http://localhost/bn/pricing')->assertSee('৳১,৫০০')->assertSee('সর্বোচ্চ ৫ জন সদস্য');
    })->with(['open', 'approval']);

    it('shows neither prices nor sign-up while registration is closed', function () {
        app(PlatformSettings::class)->put(['registration.mode' => 'closed']);

        foreach (['/', '/pricing'] as $path) {
            $response = $this->get('http://localhost'.$path)
                ->assertOk()
                ->assertDontSee('৳1,500')
                ->assertDontSee('/register', escape: false);

            expect(collect(jsonLd($response)['@graph'])->firstWhere('@type', 'SoftwareApplication'))->not->toHaveKey('offers');
        }

        $this->get('http://localhost/pricing')->assertSee(__('site_pages.pricing.closed', [], 'en'));
    });

    it('offers sign-up without prices where no plan is on offer', function () {
        Plan::query()->update(['is_active' => false]);

        $this->get('http://localhost/')->assertSee('http://localhost/register', escape: false)->assertDontSee('৳1,500');
    });
});

describe('the files crawlers read', function () {
    it('allows the website, keeps one-time links out and points at the sitemap', function () {
        $robots = $this->get('http://localhost/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        expect($robots)->toContain("User-agent: *\nAllow: /", 'Disallow: /register/confirm/', 'User-agent: GPTBot', 'User-agent: ClaudeBot', 'Sitemap: http://localhost/sitemap.xml');
    });

    it('lists every page in both languages, each with its other language', function () {
        $xml = simplexml_load_string((string) $this->get('http://localhost/sitemap.xml')->assertOk()->getContent());
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->registerXPathNamespace('x', 'http://www.w3.org/1999/xhtml');

        $urls = array_map('strval', $xml->xpath('//s:url/s:loc'));
        $apps = count(app(SiteApps::class)->all());

        expect($urls)->toHaveCount((count(WEBSITE_PAGES) + $apps) * 2)
            ->and($urls)->toContain('http://localhost', 'http://localhost/bn', 'http://localhost/apps/crm', 'http://localhost/bn/apps/crm', 'http://localhost/bn/refund-policy')
            ->and($xml->xpath('//x:link'))->toHaveCount(count($urls) * 3);
    });

    it('summarises the website for AI assistants', function () {
        $llms = $this->get('http://localhost/llms.txt')->assertOk()->getContent();

        expect($llms)->toStartWith("# Oyosend\n")
            ->toContain('http://localhost/pricing', 'http://localhost/apps/whatsapp-business', 'http://localhost/bn', 'info@oyosend.com', '+88 0179 22 88 555');
    });

    it('keeps workspaces and the admin panel out of search results', function () {
        $this->createTenant('acme');

        $this->get('http://acme.localhost/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('http://admin.localhost/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('http://localhost/')->assertHeaderMissing('X-Robots-Tag');
    });
});

describe('signing in from the website', function () {
    it('lets a plain form reach a workspace\'s own sign-in page', function () {
        $this->createTenant('acme');

        $this->get('http://localhost/signin')->assertSee('action="http://localhost/workspace"', escape: false);
        $this->post('http://localhost/workspace', ['workspace' => 'acme'])->assertRedirect('http://acme.localhost/login');

        // The browser's policy has to allow the form to land on the workspace's address.
        expect($this->get('http://localhost/signin')->headers->get('Content-Security-Policy-Report-Only'))->toContain("form-action 'self' http://*.localhost");
    });

    it('says so on the page when the address is unknown', function () {
        $this->from('http://localhost/signin')->post('http://localhost/workspace', ['workspace' => 'nobody'])->assertRedirect('http://localhost/signin');

        $this->get('http://localhost/signin')->assertSee('We could not find a workspace with that address.');
    });
});

describe('the contact form', function () {
    beforeEach(function () {
        Mail::fake();

        $this->message = [
            'name' => 'Rahim Uddin',
            'email' => 'rahim@example.com',
            'phone' => '01700000000',
            'company' => 'Rahim Traders',
            'topic' => 'demo',
            'message' => 'We have 40 staff and want payroll in Bengali.',
        ];
    });

    it('sends the message to the company inbox, with the visitor to reply to', function () {
        $this->post('http://localhost/bn/contact', $this->message)
            ->assertRedirect('http://localhost/bn/contact#message')
            ->assertSessionHas('contact', 'sent');

        expect(WebsiteEnquiry::query()->sole())->toMatchArray(['name' => 'Rahim Uddin', 'topic' => 'demo', 'locale' => 'bn'])
            ->status->value->toBe('new');

        Mail::assertQueued(WebsiteEnquiryMail::class, fn (WebsiteEnquiryMail $mail): bool => $mail->hasTo('info@oyosend.com')
            && $mail->hasReplyTo('rahim@example.com')
            && $mail->enquiry['locale'] === 'bn'
            && $mail->enquiry['topic'] === 'demo');
    });

    it('checks what was typed', function (array $change, string $field) {
        $this->post('http://localhost/contact', [...$this->message, ...$change])->assertSessionHasErrors($field);

        Mail::assertNothingQueued();
    })->with([
        'no name' => [['name' => ''], 'name'],
        'an invalid email' => [['email' => 'rahim'], 'email'],
        'an unknown topic' => [['topic' => 'jobs'], 'topic'],
        'a message too short to answer' => [['message' => 'Hi'], 'message'],
    ]);

    it('names the field in the page\'s language', function () {
        $this->from('http://localhost/bn/contact')
            ->followingRedirects()
            ->post('http://localhost/bn/contact', [...$this->message, 'name' => ''])
            ->assertSee(__('validation.required', ['attribute' => __('site_pages.contact.name', [], 'bn')], 'bn'));
    });

    it('thanks a bot that filled in the hidden field, and sends nothing', function () {
        $this->post('http://localhost/contact', [...$this->message, 'website' => 'http://spam.example'])
            ->assertSessionHas('contact', 'sent');

        Mail::assertNothingQueued();
    });

    it('limits how often it can be used', function () {
        foreach (range(1, 5) as $attempt) {
            $this->post('http://localhost/contact', $this->message)->assertRedirect();
        }

        $this->post('http://localhost/contact', $this->message)->assertTooManyRequests();
    });
});

it('has the same words in English and Bengali', function (string $file) {
    $english = require lang_path("en/{$file}.php");
    $bengali = require lang_path("bn/{$file}.php");

    expect(shapeOf($bengali))->toBe(shapeOf($english));
})->with(['site', 'site_pages', 'site_apps', 'site_legal']);

describe('starting a workspace from the home page', function () {
    it('carries the company and email to the sign-up form, never in the address', function () {
        $this->get('http://localhost/')->assertSee('action="http://localhost/register/start"', escape: false);

        $this->post('http://localhost/register/start', ['company' => 'Meghna Traders', 'email' => 'nusrat@meghna.test'])
            ->assertRedirect('http://localhost/register');

        $this->get('http://localhost/register')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Central/Register')
                ->where('prefill', ['company' => 'Meghna Traders', 'email' => 'nusrat@meghna.test']));

        // Carried once: a later visit starts empty.
        $this->get('http://localhost/register')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', ['company' => '', 'email' => '']));
    });

    it('drops an email that is not one, and keeps the rest', function () {
        $this->post('http://localhost/register/start', ['company' => 'Padma Foods', 'email' => 'not-an-email']);

        $this->get('http://localhost/register')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', ['company' => 'Padma Foods', 'email' => '']));
    });

    it('offers a conversation instead while sign-up is closed', function () {
        app(PlatformSettings::class)->put(['registration.mode' => 'closed']);

        $this->get('http://localhost/')
            ->assertSee(__('site.home.start.closed_title', [], 'en'))
            ->assertDontSee('register/start');

        $this->post('http://localhost/register/start', ['company' => 'Meghna Traders'])->assertNotFound();
    });

    it('underlines the highlighted words of the headline and keeps the rest of it', function () {
        $html = (string) $this->get('http://localhost/')->getContent();

        preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $h1);

        expect(trim(preg_replace('/\s+/', ' ', strip_tags($h1[1]))))->toBe('Run your whole company from one workspace.');
    });
});
