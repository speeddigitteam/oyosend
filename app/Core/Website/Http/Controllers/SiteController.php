<?php

declare(strict_types=1);

namespace App\Core\Website\Http\Controllers;

use App\Core\Billing\Models\Plan;
use App\Core\Website\Blog\BlogPosts;
use App\Core\Website\Enums\EnquiryStatus;
use App\Core\Website\Mail\WebsiteEnquiryMail;
use App\Core\Website\Models\WebsiteEnquiry;
use App\Core\Website\PageMeta;
use App\Core\Website\SiteApp;
use App\Core\Website\SiteApps;
use App\Core\Website\SiteCompany;
use App\Core\Website\SitePages;
use App\Core\Website\SitePrice;
use App\Core\Website\SiteSchema;
use App\Core\Website\SiteUrls;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The pages of the public website (docs/WEBSITE.md). Each is a plain server-
 * rendered page in the language of its address; the words are in
 * lang/{locale}/site*.php and the prices are read from the plans and the apps.
 */
final class SiteController
{
    /** @var list<string> */
    public const array TOPICS = ['sales', 'demo', 'support', 'partnership', 'other'];

    public function __construct(
        private readonly SiteApps $apps,
        private readonly SiteCompany $company,
        private readonly SitePages $pages,
        private readonly BlogPosts $blog,
    ) {}

    public function home(): View
    {
        $plans = $this->plans();
        $url = SiteUrls::to('home');
        $faq = $this->items('site.home.faq');

        $meta = $this->meta('home', __('site.home.meta_title'), __('site.meta.description'), fullTitle: true, schema: [
            SiteSchema::software(
                $url,
                $this->company->name(),
                __('site.meta.description'),
                app()->getLocale(),
                array_map(static fn (SiteApp $app): string => $app->text('name'), $this->apps->all()),
                SiteSchema::aggregateOffer($this->monthlyPrices($plans), SiteUrls::to('pricing')),
            ),
            SiteSchema::faq($url, $faq),
        ]);

        return $this->render('site.pages.home', $meta, [
            'groups' => $this->apps->byCategory(),
            'plans' => $plans,
            'faq' => $faq,
            'posts' => $this->blog->latest(app()->getLocale(), 3),
        ]);
    }

    public function apps(): View
    {
        $title = __('site_pages.apps.meta_title');

        $meta = $this->meta('apps', $title, __('site_pages.apps.meta_description'), trail: [[__('site.nav.apps'), SiteUrls::to('apps')]], pageType: 'CollectionPage');

        return $this->render('site.pages.apps', $meta, ['groups' => $this->apps->byCategory()]);
    }

    public function app(string $app): View
    {
        $found = $this->apps->find($app);
        abort_if($found === null, 404);

        $url = SiteUrls::to('app', null, ['app' => $found->slug]);
        $faq = $this->items("site_apps.items.{$found->module}.faq");
        $features = array_map(static fn (array $feature): string => (string) $feature['title'], $found->list('features'));
        $offer = $found->price->isPaid()
            ? SiteSchema::offer((int) $found->price->amount, (string) $found->price->currency, SiteUrls::to('pricing'))
            : null;

        $meta = $this->meta(
            'app',
            $found->text('meta_title'),
            $found->text('meta_description'),
            parameters: ['app' => $found->slug],
            trail: [[__('site.nav.apps'), SiteUrls::to('apps')], [$found->text('name'), $url]],
            schema: [
                SiteSchema::software($url, 'Oyosend '.$found->text('name'), $found->text('meta_description'), app()->getLocale(), $features, $offer),
                SiteSchema::faq($url, $faq),
            ],
        );

        $related = array_values(array_filter(
            $this->apps->all(),
            static fn (SiteApp $other): bool => $other->category === $found->category && $other->slug !== $found->slug,
        ));

        return $this->render('site.pages.app', $meta, [
            'app' => $found,
            'faq' => $faq,
            'related' => $related,
            'posts' => $this->blog->latest(app()->getLocale(), 3, $found->module),
        ]);
    }

    public function pricing(): View
    {
        $plans = $this->plans();
        $url = SiteUrls::to('pricing');
        $faq = $this->items('site_pages.pricing.faq');

        $meta = $this->meta('pricing', __('site_pages.pricing.meta_title'), __('site_pages.pricing.meta_description'), trail: [[__('site.nav.pricing'), $url]], schema: [
            SiteSchema::software(
                SiteUrls::to('home'),
                $this->company->name(),
                __('site.meta.description'),
                app()->getLocale(),
                offers: SiteSchema::aggregateOffer($this->monthlyPrices($plans), $url),
            ),
            SiteSchema::faq($url, $faq),
        ]);

        return $this->render('site.pages.pricing', $meta, [
            'plans' => $plans,
            'apps' => $this->apps->all(),
            'faq' => $faq,
        ]);
    }

    public function security(): View
    {
        $url = SiteUrls::to('security');
        $faq = $this->items('site_pages.security.faq');

        $meta = $this->meta('security', __('site_pages.security.meta_title'), __('site_pages.security.meta_description'), trail: [[__('site.nav.security'), $url]], schema: [
            SiteSchema::faq($url, $faq),
        ]);

        return $this->render('site.pages.security', $meta, ['faq' => $faq]);
    }

    public function about(): View
    {
        $meta = $this->meta('about', __('site_pages.about.meta_title'), __('site_pages.about.meta_description'), trail: [[__('site.nav.about'), SiteUrls::to('about')]], pageType: 'AboutPage');

        return $this->render('site.pages.about', $meta);
    }

    public function faq(): View
    {
        $url = SiteUrls::to('faq');
        $groups = __('site_pages.faq.groups');
        $groups = is_array($groups) ? array_values($groups) : [];
        $questions = [];

        foreach ($groups as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $questions[] = ['q' => (string) $item['q'], 'a' => (string) $item['a']];
            }
        }

        $meta = $this->meta('faq', __('site_pages.faq.meta_title'), __('site_pages.faq.meta_description'), trail: [[__('site.nav.faq'), $url]], schema: [
            SiteSchema::faq($url, $questions),
        ]);

        return $this->render('site.pages.faq', $meta, ['groups' => $groups]);
    }

    public function contact(): View
    {
        $meta = $this->meta('contact', __('site_pages.contact.meta_title'), __('site_pages.contact.meta_description'), trail: [[__('site.nav.contact'), SiteUrls::to('contact')]], pageType: 'ContactPage');

        return $this->render('site.pages.contact', $meta, ['topics' => self::TOPICS]);
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        $back = redirect()->to(SiteUrls::to('contact').'#message');

        // A field people never see: only a bot fills it in, and it is told its message went.
        if (filled($request->input('website'))) {
            return $back->with('contact', 'sent');
        }

        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['nullable', 'string', 'max:160'],
            'topic' => ['required', 'string', Rule::in(self::TOPICS)],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [], array_map(
            static fn (string $field): string => mb_strtolower(__("site_pages.contact.{$field}")),
            ['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'company' => 'company', 'topic' => 'topic', 'message' => 'message'],
        ));

        $enquiry = [
            'name' => trim($input['name']),
            'email' => trim($input['email']),
            'phone' => filled($input['phone'] ?? null) ? trim((string) $input['phone']) : null,
            'company' => filled($input['company'] ?? null) ? trim((string) $input['company']) : null,
            'topic' => $input['topic'],
            'message' => trim($input['message']),
            'locale' => app()->getLocale(),
        ];

        // Kept first, so a message is never lost to a mail problem: the panel lists it either way.
        try {
            WebsiteEnquiry::query()->create([...$enquiry, 'status' => EnquiryStatus::New]);
        } catch (Throwable $exception) {
            Log::error('The website contact form could not store its message.', ['exception' => $exception::class]);

            return $back->withInput()->with('contact', 'failed');
        }

        try {
            Mail::to($this->company->contactRecipient())->send(new WebsiteEnquiryMail($enquiry));
        } catch (Throwable $exception) {
            Log::error('The website contact form could not queue its mail.', ['exception' => $exception::class]);
        }

        return $back->with('contact', 'sent');
    }

    public function signin(): View
    {
        $meta = $this->meta('signin', __('site.signin.meta_title'), __('site.signin.meta_description'), trail: [[__('site.nav.signin'), SiteUrls::to('signin')]]);

        return $this->render('site.pages.signin', $meta);
    }

    public function legal(string $document): View
    {
        abort_unless(in_array($document, ['privacy', 'terms', 'refund'], true), 404);

        $url = SiteUrls::to($document);
        $title = __("site_legal.{$document}.title");

        $meta = $this->meta($document, __("site_legal.{$document}.meta_title"), __("site_legal.{$document}.meta_description"), trail: [[$title, $url]]);

        $sections = __("site_legal.{$document}.sections");

        return $this->render('site.pages.legal', $meta, [
            'document' => $document,
            'sections' => is_array($sections) ? array_values($sections) : [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, PageMeta $meta, array $data = []): View
    {
        return $this->pages->render($view, $meta, $data);
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  list<array{0: string, 1: string}>  $trail  from the first page under home to this one
     * @param  list<array<string, mixed>>  $schema
     */
    private function meta(
        string $page,
        string $title,
        string $description,
        array $parameters = [],
        array $trail = [],
        array $schema = [],
        bool $fullTitle = false,
        string $pageType = 'WebPage',
    ): PageMeta {
        return $this->pages->meta($page, $title, $description, $parameters, $trail, $schema, $fullTitle, $pageType);
    }

    /**
     * A list of questions and answers from the copy.
     *
     * @return list<array{q: string, a: string}>
     */
    private function items(string $key): array
    {
        $items = __($key);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(static fn (array $item): array => ['q' => (string) $item['q'], 'a' => (string) $item['a']], $items));
    }

    private function registrationOpen(): bool
    {
        return $this->pages->registrationOpen();
    }

    /**
     * The plans on sale, written for the website. Prices are only shown while someone can act on them.
     *
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        if (! $this->registrationOpen()) {
            return [];
        }

        return Plan::query()->offered()->get()->map(function (Plan $plan): array {
            $apps = array_values(array_filter(array_map(fn (string $module): ?SiteApp => $this->apps->forModule($module), array_values($plan->modules))));
            // No entry means no limit (LimitDefinition).
            $members = $plan->limit('core.members');

            return [
                'key' => $plan->key,
                'name' => $plan->nameIn(app()->getLocale()),
                'description' => $plan->descriptionIn(app()->getLocale()),
                'free' => $plan->isFree(),
                'currency' => $plan->currency,
                'monthlyAmount' => $plan->monthly_amount,
                'monthly' => $plan->monthly_amount === null ? null : SitePrice::format($plan->monthly_amount, $plan->currency),
                'yearly' => $plan->yearly_amount === null ? null : SitePrice::format($plan->yearly_amount, $plan->currency),
                'trialDays' => $plan->trial_days,
                'members' => $members === null ? __('site.pricing.members_unlimited') : trans_choice('site.pricing.members', $members, ['count' => SitePrice::number($members)]),
                'apps' => $apps,
                'url' => route('central.register', ['plan' => $plan->key]),
            ];
        })->all();
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @return list<array{amount: int, currency: string}>
     */
    private function monthlyPrices(array $plans): array
    {
        $prices = [];

        foreach ($plans as $plan) {
            if (is_int($plan['monthlyAmount'])) {
                $prices[] = ['amount' => $plan['monthlyAmount'], 'currency' => (string) $plan['currency']];
            }
        }

        return $prices;
    }
}
