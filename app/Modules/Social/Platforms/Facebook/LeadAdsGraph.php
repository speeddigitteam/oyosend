<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The documented Graph API calls Facebook Lead Ads needs, with the Page access token in the Authorization header
 * only (never in a URL, a message or a log line):
 *
 *  - `GET /{page-id}/leadgen_forms`            the Page's lead forms and their questions
 *  - `GET /{leadgen-id}`                       one lead: `field_data`, `ad_id`, `form_id`, `created_time`, ...
 *  - `GET /{form-id}/leads`                    a form's leads (with a `time_created` filter), for missed webhooks
 *  - `GET|POST|DELETE /{page-id}/subscribed_apps`  add or remove the Page's `leadgen` webhook field, keeping the others
 *  - `GET /debug_token`                        the permissions the Page token holds (app access token)
 *
 * Needs `leads_retrieval`, `pages_manage_ads`, `pages_show_list` and, to subscribe, `pages_manage_metadata`,
 * approved through Meta app review, and the person who connected the Page needs leads access on it.
 * NOT yet exercised against Meta's live API in this repository.
 */
final class LeadAdsGraph
{
    private const array RATE_LIMIT_CODES = [4, 17, 32, 613, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    private const array TOKEN_CODES = [102, 190, 459, 464, 467];

    private const array PERMISSION_CODES = [3, 10];

    /** Lead fields requested on both the single and the list call. */
    public const string LEAD_FIELDS = 'id,created_time,ad_id,adset_id,campaign_id,form_id,field_data,is_organic';

    public function __construct(private readonly Factory $http) {}

    /**
     * @return list<array{id: string, name: string, status: string|null, locale: string|null, questions: list<array{key: string, label: string, type: string}>}>
     *
     * @throws LeadAdsGraphException
     */
    public function forms(string $pageId, string $token, int $max = 100): array
    {
        $forms = [];
        $url = $this->url($pageId.'/leadgen_forms');
        $query = ['fields' => 'id,name,status,locale,questions', 'limit' => 50];

        for ($page = 0; $page < 4 && $url !== null && count($forms) < $max; $page++) {
            $response = $this->get($token, $url, $query);
            $query = [];

            foreach ((array) $response->json('data', []) as $form) {
                if (! is_array($form) || ! is_string($form['id'] ?? null) || preg_match('/^\d{1,40}$/', $form['id']) !== 1) {
                    continue;
                }

                $forms[] = [
                    'id' => $form['id'],
                    'name' => $this->text($form['name'] ?? null, 200) ?? $form['id'],
                    'status' => $this->text($form['status'] ?? null, 24),
                    'locale' => $this->text($form['locale'] ?? null, 16),
                    'questions' => $this->questions($form['questions'] ?? []),
                ];
            }

            $next = $response->json('paging.next');
            $url = is_string($next) && str_starts_with($next, 'https://graph.facebook.com/') ? $next : null;
        }

        return array_slice($forms, 0, $max);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LeadAdsGraphException
     */
    public function lead(string $leadgenId, string $token): array
    {
        $response = $this->get($token, $this->url($leadgenId), ['fields' => self::LEAD_FIELDS]);
        $lead = $response->json();

        if (! is_array($lead) || ! is_array($lead['field_data'] ?? null)) {
            throw new LeadAdsGraphException(LeadAdsGraphException::REJECTED, __('Facebook returned the lead without its answers.'));
        }

        return $lead;
    }

    /**
     * Leads of one form created after `$since`, newest first, at most `$limit`.
     *
     * @return list<array<string, mixed>>
     *
     * @throws LeadAdsGraphException
     */
    public function formLeads(string $formId, string $token, CarbonImmutable $since, int $limit = 100): array
    {
        $leads = [];
        $url = $this->url($formId.'/leads');
        $query = [
            'fields' => self::LEAD_FIELDS,
            'limit' => min(50, $limit),
            'filtering' => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $since->getTimestamp()]]),
        ];

        for ($page = 0; $page < 5 && $url !== null && count($leads) < $limit; $page++) {
            $response = $this->get($token, $url, $query);
            $query = [];

            foreach ((array) $response->json('data', []) as $lead) {
                if (is_array($lead) && is_string($lead['id'] ?? null)) {
                    $leads[] = $lead;
                }
            }

            $next = $response->json('paging.next');
            $url = is_string($next) && str_starts_with($next, 'https://graph.facebook.com/') ? $next : null;
        }

        return array_slice($leads, 0, $limit);
    }

    /**
     * Add `leadgen` to the Page's webhook fields. The call sets the whole list, so the fields other features
     * subscribed (comments' `feed`, direct messages) are read first and kept ({@see FacebookPageSubscriptions}).
     *
     * @throws LeadAdsGraphException
     */
    public function subscribe(string $pageId, string $token): void
    {
        $current = [];

        try {
            $current = FacebookPageSubscriptions::fieldsOf($this->get($token, $this->url($pageId.'/subscribed_apps')));
        } catch (LeadAdsGraphException $exception) {
            if (in_array($exception->kind, [LeadAdsGraphException::TOKEN, LeadAdsGraphException::PERMISSION], true)) {
                throw $exception;
            }
        }

        $response = $this->send(fn (): Response => $this->client($token)->asForm()->post($this->url($pageId.'/subscribed_apps'), [
            'subscribed_fields' => implode(',', FacebookPageSubscriptions::merge($current, ['leadgen'])),
        ]));

        $this->ensure($response);

        if ($response->json('success') === false) {
            throw new LeadAdsGraphException(LeadAdsGraphException::REJECTED, __('Facebook did not accept the subscription.'));
        }
    }

    /**
     * Remove `leadgen` from the Page's webhook fields, keeping every other feature's; the app leaves the Page only
     * when nothing else is subscribed. Without the current list nothing is changed (never a blind removal).
     *
     * @throws LeadAdsGraphException
     */
    public function unsubscribe(string $pageId, string $token): void
    {
        $remaining = FacebookPageSubscriptions::merge(
            FacebookPageSubscriptions::fieldsOf($this->get($token, $this->url($pageId.'/subscribed_apps'))),
            [],
            ['leadgen'],
        );

        if ($remaining === []) {
            $this->ensure($this->send(fn (): Response => $this->client($token)->delete($this->url($pageId.'/subscribed_apps'))));

            return;
        }

        $this->ensure($this->send(fn (): Response => $this->client($token)->asForm()->post($this->url($pageId.'/subscribed_apps'), [
            'subscribed_fields' => implode(',', $remaining),
        ])));
    }

    /**
     * The permissions the Page token holds, or null when the platform did not say.
     *
     * @return list<string>|null
     *
     * @throws LeadAdsGraphException
     */
    public function grantedScopes(string $token): ?array
    {
        $id = config('social.platforms.facebook.client_id');
        $secret = config('social.platforms.facebook.client_secret');

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            return null;
        }

        $app = $id.'|'.$secret;
        $response = $this->get($app, $this->url('debug_token'), ['input_token' => $token]);

        if ($response->json('data.is_valid') === false) {
            throw new LeadAdsGraphException(LeadAdsGraphException::TOKEN, $this->tokenMessage());
        }

        $scopes = $response->json('data.scopes');

        return is_array($scopes)
            ? array_values(array_filter(array_map(static fn ($scope): string => is_string($scope) ? $scope : '', $scopes), static fn (string $scope): bool => $scope !== ''))
            : null;
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws LeadAdsGraphException
     */
    private function get(string $token, string $url, array $query = []): Response
    {
        return $this->ensure($this->send(fn (): Response => $this->client($token)->get($url, $query)));
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws LeadAdsGraphException
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new LeadAdsGraphException(LeadAdsGraphException::TRANSIENT, __('Facebook could not be reached.'));
        }
    }

    /** @throws LeadAdsGraphException */
    private function ensure(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $code = (int) $response->json('error.code', 0);
        $subcode = (int) $response->json('error.error_subcode', 0);

        if (in_array($code, self::TOKEN_CODES, true) || $response->status() === 401) {
            throw new LeadAdsGraphException(LeadAdsGraphException::TOKEN, $this->tokenMessage());
        }

        if (in_array($code, self::PERMISSION_CODES, true) || ($code >= 200 && $code <= 299)) {
            throw new LeadAdsGraphException(
                LeadAdsGraphException::PERMISSION,
                __('Facebook says this connection cannot read leads. Connect the Page again, approve every permission it asks for, and make sure the person who connected it has leads access on the Page.'),
            );
        }

        if (in_array($code, self::RATE_LIMIT_CODES, true) || $response->status() === 429) {
            $header = $response->header('Retry-After');

            throw new LeadAdsGraphException(
                LeadAdsGraphException::RATE_LIMIT,
                __('Facebook is limiting requests. The lead will be fetched again shortly.'),
                is_numeric($header) ? max(1, min(3600, (int) $header)) : null,
            );
        }

        if ($response->status() === 404 || ($code === 100 && in_array($subcode, [33, 1357045], true))) {
            throw new LeadAdsGraphException(LeadAdsGraphException::NOT_FOUND, __('Facebook no longer has this lead or form.'));
        }

        if ($response->serverError() || in_array($code, [1, 2], true)) {
            throw new LeadAdsGraphException(LeadAdsGraphException::TRANSIENT, __('Facebook had a problem answering. It will be tried again.'));
        }

        throw new LeadAdsGraphException(LeadAdsGraphException::REJECTED, __('Facebook rejected the request.'));
    }

    private function tokenMessage(): string
    {
        return __('Facebook no longer accepts this Page connection. The access was removed or has expired. Connect the Page again to continue.');
    }

    private function client(string $token): PendingRequest
    {
        return $this->http->timeout(20)->connectTimeout(10)->acceptJson()->withUserAgent('Oyosend-Social/1.0')->withToken($token);
    }

    private function url(string $path): string
    {
        $version = config('social.platforms.facebook.api_version');

        return 'https://graph.facebook.com/'.(is_string($version) && $version !== '' ? $version : 'v23.0').'/'.ltrim($path, '/');
    }

    /** @return list<array{key: string, label: string, type: string}> */
    private function questions(mixed $raw): array
    {
        $questions = [];

        foreach (is_array($raw) ? $raw : [] as $question) {
            if (count($questions) >= 60) {
                break;
            }

            if (! is_array($question) || ! is_string($question['key'] ?? null)) {
                continue;
            }

            $key = $this->text($question['key'], 64);

            if ($key === null) {
                continue;
            }

            $questions[] = [
                'key' => $key,
                'label' => $this->text($question['label'] ?? null, 200) ?? $key,
                'type' => $this->text($question['type'] ?? null, 40) ?? 'CUSTOM',
            ];
        }

        return $questions;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(strip_tags(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
