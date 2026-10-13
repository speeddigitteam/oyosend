<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers\Cloud;

use App\Core\Communication\Data\Template\ProviderTemplate;
use App\Core\Communication\Data\Template\ProviderTemplateStatus;
use App\Core\Communication\Data\Template\TemplateButton;
use App\Core\Communication\Data\Template\TemplateCatalogueResult;
use App\Core\Communication\Data\Template\TemplateCatalogueStatus;
use App\Core\Communication\Data\Template\TemplateCategory;
use App\Core\Communication\Data\Template\TemplateComponent;
use App\Core\Communication\Data\Template\TemplateDraft;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Message templates through the Graph API's business-management edge `/{waba_id}/message_templates`:
 *
 *  - `GET`: `fields=id,name,language,status,category,components,rejected_reason,quality_score`, `limit`, `status`,
 *    `name`, `after` (cursor); answers `{data: [...], paging: {cursors: {after}, next}}`.
 *  - `POST`: `{name, language, category, components[], allow_category_change?}` answers `{id, status, category}`.
 *  - `DELETE`: `?name=...` (every language) with optional `hsm_id=...`; answers `{success: true}`.
 *
 * Component mapping (our draft to Meta): HEADER (`format` TEXT with `text` + `example.header_text`, or a media
 * format with `example.header_handle`), BODY (`text` + `example.body_text` as one list of example values),
 * FOOTER (`text`), BUTTONS (`buttons[]` of QUICK_REPLY, URL with `example`, PHONE_NUMBER, COPY_CODE with `example`).
 */
final class CloudTemplates
{
    private const string FIELDS = 'id,name,language,status,category,components,rejected_reason,quality_score';

    public static function list(ChannelConnection $connection, ChannelCredentials $credentials, string $businessAccountId, ?ProviderTemplateStatus $status, ?string $name, int $limit, ?string $after): TemplateCatalogueResult
    {
        $query = ['fields' => self::FIELDS, 'limit' => max(1, min(100, $limit))];

        if ($status !== null && $status !== ProviderTemplateStatus::Unknown) {
            $query['status'] = strtoupper($status->value);
        }

        if ($name !== null && $name !== '') {
            $query['name'] = mb_substr($name, 0, 512);
        }

        if ($after !== null && preg_match('/^[A-Za-z0-9=_-]{1,500}$/', $after) === 1) {
            $query['after'] = $after;
        }

        try {
            $response = CloudGraph::http($credentials->secret())->get(CloudGraph::url($connection, $businessAccountId.'/message_templates'), $query);
        } catch (Throwable $exception) {
            return self::transport($exception);
        }

        if (! $response->successful()) {
            return self::failure($response);
        }

        $templates = [];

        foreach ((array) $response->json('data', []) as $row) {
            $template = is_array($row) ? self::template($row) : null;

            if ($template !== null) {
                $templates[] = $template;
            }
        }

        $next = $response->json('paging.next') !== null ? $response->json('paging.cursors.after') : null;

        return new TemplateCatalogueResult(
            TemplateCatalogueStatus::Ok,
            templates: $templates,
            nextCursor: is_string($next) && preg_match('/^[A-Za-z0-9=_-]{1,500}$/', $next) === 1 ? $next : null,
        );
    }

    public static function create(ChannelConnection $connection, ChannelCredentials $credentials, string $businessAccountId, TemplateDraft $draft): TemplateCatalogueResult
    {
        $body = [
            'name' => $draft->name,
            'language' => $draft->language,
            'category' => strtoupper($draft->category->value),
            'components' => array_map(self::component(...), $draft->ordered()),
        ];

        if ($draft->allowCategoryChange) {
            $body['allow_category_change'] = true;
        }

        try {
            $response = CloudGraph::http($credentials->secret(), 30)
                ->asJson()
                ->post(CloudGraph::url($connection, $businessAccountId.'/message_templates'), $body);
        } catch (Throwable $exception) {
            return self::transport($exception);
        }

        if (! $response->successful()) {
            return self::failure($response);
        }

        $id = $response->json('id');

        if (! is_string($id) && ! is_int($id)) {
            return new TemplateCatalogueResult(TemplateCatalogueStatus::Failed, 'provider_malformed_response');
        }

        $category = TemplateCategory::tryFrom(strtolower((string) $response->json('category', $draft->category->value))) ?? $draft->category;

        return new TemplateCatalogueResult(
            TemplateCatalogueStatus::Ok,
            template: new ProviderTemplate(
                (string) $id,
                $draft->name,
                $draft->language,
                $category,
                ProviderTemplateStatus::fromProvider((string) $response->json('status', 'PENDING')),
                $draft->ordered(),
            ),
        );
    }

    public static function delete(ChannelConnection $connection, ChannelCredentials $credentials, string $businessAccountId, string $name, ?string $providerTemplateId): TemplateCatalogueResult
    {
        $query = ['name' => $name];

        if ($providerTemplateId !== null && preg_match('/^\d{1,40}$/', $providerTemplateId) === 1) {
            $query['hsm_id'] = $providerTemplateId;
        }

        try {
            $response = CloudGraph::http($credentials->secret())
                ->delete(CloudGraph::url($connection, $businessAccountId.'/message_templates').'?'.http_build_query($query));
        } catch (Throwable $exception) {
            return self::transport($exception);
        }

        if (! $response->successful()) {
            return self::failure($response);
        }

        return $response->json('success') === true
            ? new TemplateCatalogueResult(TemplateCatalogueStatus::Ok)
            : new TemplateCatalogueResult(TemplateCatalogueStatus::Failed, 'provider_rejected', self::detail($response));
    }

    /** @return array<string, mixed> */
    private static function component(TemplateComponent $component): array
    {
        return match ($component->type) {
            TemplateComponent::HEADER => $component->format === 'text'
                ? [
                    'type' => 'HEADER', 'format' => 'TEXT', 'text' => (string) $component->text,
                    ...($component->examples === [] ? [] : ['example' => ['header_text' => $component->examples]]),
                ]
                : [
                    'type' => 'HEADER', 'format' => strtoupper((string) $component->format),
                    ...($component->headerHandle === null ? [] : ['example' => ['header_handle' => [$component->headerHandle]]]),
                ],
            TemplateComponent::BODY => [
                'type' => 'BODY', 'text' => (string) $component->text,
                ...($component->examples === [] ? [] : ['example' => ['body_text' => [$component->examples]]]),
            ],
            TemplateComponent::FOOTER => ['type' => 'FOOTER', 'text' => (string) $component->text],
            default => ['type' => 'BUTTONS', 'buttons' => array_map(static fn (TemplateButton $button): array => match ($button->type) {
                TemplateButton::URL => [
                    'type' => 'URL', 'text' => $button->text, 'url' => (string) $button->url,
                    ...($button->example === null ? [] : ['example' => [$button->example]]),
                ],
                TemplateButton::PHONE_NUMBER => ['type' => 'PHONE_NUMBER', 'text' => $button->text, 'phone_number' => (string) $button->phoneNumber],
                TemplateButton::COPY_CODE => ['type' => 'COPY_CODE', 'example' => (string) $button->example],
                default => ['type' => 'QUICK_REPLY', 'text' => $button->text],
            }, $component->buttons)],
        };
    }

    /** @param array<array-key, mixed> $row */
    private static function template(array $row): ?ProviderTemplate
    {
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        $language = $row['language'] ?? null;

        if ((! is_string($id) && ! is_int($id)) || ! is_string($name) || ! is_string($language)) {
            return null;
        }

        $components = [];

        foreach ((array) ($row['components'] ?? []) as $component) {
            $parsed = is_array($component) ? self::parseComponent($component) : null;

            if ($parsed !== null) {
                $components[] = $parsed;
            }
        }

        $score = $row['quality_score']['score'] ?? null;

        return new ProviderTemplate(
            (string) $id,
            mb_substr($name, 0, 512),
            mb_substr($language, 0, 20),
            TemplateCategory::tryFrom(strtolower((string) ($row['category'] ?? ''))),
            ProviderTemplateStatus::fromProvider((string) ($row['status'] ?? '')),
            $components,
            CloudGraph::clip($row['rejected_reason'] ?? null, 200) === 'NONE' ? null : CloudGraph::clip($row['rejected_reason'] ?? null, 200),
            is_string($score) ? strtolower(mb_substr($score, 0, 10)) : null,
        );
    }

    /** @param array<array-key, mixed> $component */
    private static function parseComponent(array $component): ?TemplateComponent
    {
        $type = strtolower((string) ($component['type'] ?? ''));

        if ($type === 'buttons') {
            $buttons = [];

            foreach ((array) ($component['buttons'] ?? []) as $button) {
                if (! is_array($button)) {
                    continue;
                }

                $kind = strtolower((string) ($button['type'] ?? ''));

                if (! in_array($kind, [TemplateButton::QUICK_REPLY, TemplateButton::URL, TemplateButton::PHONE_NUMBER, TemplateButton::COPY_CODE], true)) {
                    continue;
                }

                $example = $button['example'] ?? null;
                $buttons[] = TemplateButton::fromProvider(
                    $kind,
                    mb_substr((string) ($button['text'] ?? ''), 0, 25),
                    CloudGraph::clip($button['url'] ?? null, 2000),
                    CloudGraph::clip($button['phone_number'] ?? null, 32),
                    is_array($example) ? CloudGraph::clip($example[0] ?? null, 2000) : CloudGraph::clip($example, 2000),
                );
            }

            return TemplateComponent::fromProvider('buttons', null, null, [], $buttons);
        }

        if (! in_array($type, ['header', 'body', 'footer'], true)) {
            return null;
        }

        $example = is_array($component['example'] ?? null) ? $component['example'] : [];
        $values = $type === 'header' ? (array) ($example['header_text'] ?? []) : (array) (((array) ($example['body_text'] ?? []))[0] ?? []);

        return TemplateComponent::fromProvider(
            $type,
            isset($component['format']) ? strtolower((string) $component['format']) : null,
            CloudGraph::clip($component['text'] ?? null, 1100),
            array_values(array_map(static fn (mixed $value): string => mb_substr((string) $value, 0, 1024), array_filter($values, 'is_scalar'))),
        );
    }

    private static function transport(Throwable $exception): TemplateCatalogueResult
    {
        return new TemplateCatalogueResult(
            TemplateCatalogueStatus::Failed,
            $exception instanceof ConnectionException ? 'provider_unreachable' : 'provider_error',
        );
    }

    private static function failure(Response $response): TemplateCatalogueResult
    {
        $status = $response->status();
        $code = $response->json('error.code');
        $detail = self::detail($response);

        return match (true) {
            $status === 401 || $code === 190 => new TemplateCatalogueResult(TemplateCatalogueStatus::Failed, 'provider_authentication_failed', 'The WhatsApp access token was refused.'),
            $status === 404 => new TemplateCatalogueResult(TemplateCatalogueStatus::NotFound, 'provider_not_found', $detail),
            $status === 429 || in_array($code, [4, 17, 80008, 130429], true) => new TemplateCatalogueResult(TemplateCatalogueStatus::Failed, 'provider_rate_limited', $detail),
            $status >= 500 => new TemplateCatalogueResult(TemplateCatalogueStatus::Failed, 'provider_unavailable', $detail),
            default => new TemplateCatalogueResult(TemplateCatalogueStatus::Rejected, 'provider_rejected', $detail),
        };
    }

    private static function detail(Response $response): ?string
    {
        return CloudGraph::clip($response->json('error.error_user_msg') ?? $response->json('error.error_data.details') ?? $response->json('error.message'));
    }
}
