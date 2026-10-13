<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers\Cloud;

use App\Core\Communication\Data\Whatsapp\MediaReference;
use App\Core\Communication\Data\Whatsapp\WhatsappContent;
use App\Core\Communication\Data\Whatsapp\WhatsappContentKind;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\SendResult;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Maps a {@see WhatsappContent} to the Cloud API's message object (the JSON body of
 * `POST /{version}/{phone_number_id}/messages`). Documented field by field in docs/COMMUNICATION.md section 14a.
 *
 * The content was validated when it was built and is re-validated here (it is read back from the outbox).
 * Media must already be a provider media id: the dispatcher uploads stored files before the adapter runs.
 */
final class CloudMessageMapper
{
    /**
     * @return array<string, mixed>|SendResult|null Null when the payload carries no rich content.
     */
    public static function fromPayload(OutboundPayload $payload, string $to): array|SendResult|null
    {
        $json = $payload->meta['whatsapp_content'] ?? null;

        if (! is_string($json) || $json === '') {
            return null;
        }

        try {
            $content = WhatsappContent::fromJson($json);
        } catch (InvalidArgumentException) {
            return SendResult::rejected('invalid_content', 'The WhatsApp message content is not valid.');
        }

        // Everything but a template is a free-form message: only inside the open customer-service window.
        if ($content->kind->requiresOpenWindow()) {
            $until = $payload->meta['whatsapp_window_until'] ?? null;

            try {
                $open = is_string($until) && CarbonImmutable::parse($until)->isFuture();
            } catch (Throwable) {
                $open = false;
            }

            if (! $open) {
                return SendResult::rejected('outside_customer_service_window', 'The 24-hour customer-service window is not open; only a template can be sent.');
            }
        }

        foreach ($content->mediaReferences() as $reference) {
            if ($reference->providerMediaId === null) {
                return SendResult::rejected('media_not_uploaded', 'The media file was not uploaded to WhatsApp.');
            }
        }

        $text = trim($payload->body);

        if ($content->kind !== WhatsappContentKind::Media && $content->kind !== WhatsappContentKind::Template && ($text === '' || mb_strlen($text) > WhatsappContent::MAX_BODY)) {
            return SendResult::rejected('invalid_content', 'The message text must be 1 to 1024 characters.');
        }

        $envelope = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to];

        return $envelope + match ($content->kind) {
            WhatsappContentKind::Template => self::template($content),
            WhatsappContentKind::Media => self::media($content),
            default => ['type' => 'interactive', 'interactive' => self::interactive($content, $text)],
        };
    }

    /** @return array<string, mixed> */
    private static function template(WhatsappContent $content): array
    {
        $spec = $content->spec;
        $components = [];
        $header = $spec['header'] ?? null;

        if (is_array($header) && ($header['type'] ?? null) === 'text') {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => (string) $header['text']]]];
        } elseif (is_array($header) && ($header['type'] ?? null) === 'media') {
            $components[] = ['type' => 'header', 'parameters' => [self::mediaParameter(MediaReference::fromArray((array) $header['media']))]];
        }

        $parameters = array_map(
            static fn (mixed $value): array => ['type' => 'text', 'text' => (string) $value],
            array_values((array) ($spec['body_parameters'] ?? [])),
        );

        if ($parameters !== []) {
            $components[] = ['type' => 'body', 'parameters' => $parameters];
        }

        foreach ((array) ($spec['buttons'] ?? []) as $button) {
            $value = (string) ($button['value'] ?? '');
            $components[] = [
                'type' => 'button',
                'sub_type' => (string) $button['sub_type'],
                'index' => (string) (int) $button['index'],
                'parameters' => [match ($button['sub_type']) {
                    'quick_reply' => ['type' => 'payload', 'payload' => $value],
                    'copy_code' => ['type' => 'coupon_code', 'coupon_code' => $value],
                    default => ['type' => 'text', 'text' => $value],
                }],
            ];
        }

        return [
            'type' => 'template',
            'template' => [
                'name' => (string) $spec['name'],
                'language' => ['code' => (string) $spec['language']],
                ...($components === [] ? [] : ['components' => $components]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function media(WhatsappContent $content): array
    {
        $reference = MediaReference::fromArray((array) $content->spec['media']);
        $type = $reference->type->value;
        $object = ['id' => (string) $reference->providerMediaId];
        $caption = $content->spec['caption'] ?? null;

        if (is_string($caption) && $caption !== '') {
            $object['caption'] = $caption;
        }

        if ($type === 'document' && $reference->filename !== null) {
            $object['filename'] = $reference->filename;
        }

        return ['type' => $type, $type => $object];
    }

    /** @return array<string, mixed> */
    private static function interactive(WhatsappContent $content, string $text): array
    {
        $spec = $content->spec;
        $interactive = [];
        $header = self::header($spec['header'] ?? null);

        if ($header !== null) {
            $interactive['header'] = $header;
        }

        $interactive['body'] = ['text' => $text];
        $footer = $spec['footer'] ?? null;

        if (is_string($footer) && $footer !== '') {
            $interactive['footer'] = ['text' => $footer];
        }

        $action = match ($content->kind) {
            WhatsappContentKind::Buttons => [
                'type' => 'button',
                'action' => ['buttons' => array_map(
                    static fn (array $button): array => ['type' => 'reply', 'reply' => ['id' => (string) $button['id'], 'title' => (string) $button['title']]],
                    array_values((array) $spec['buttons']),
                )],
            ],
            WhatsappContentKind::List => [
                'type' => 'list',
                'action' => [
                    'button' => (string) $spec['button'],
                    'sections' => array_map(static function (array $section): array {
                        return [
                            ...(isset($section['title']) ? ['title' => (string) $section['title']] : []),
                            'rows' => array_map(static fn (array $row): array => [
                                'id' => (string) $row['id'],
                                'title' => (string) $row['title'],
                                ...(isset($row['description']) ? ['description' => (string) $row['description']] : []),
                            ], array_values((array) $section['rows'])),
                        ];
                    }, array_values((array) $spec['sections'])),
                ],
            ],
            WhatsappContentKind::CtaUrl => [
                'type' => 'cta_url',
                'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => (string) $spec['label'], 'url' => (string) $spec['url']]],
            ],
            WhatsappContentKind::Product => [
                'type' => 'product',
                'action' => ['catalog_id' => (string) $spec['catalog_id'], 'product_retailer_id' => (string) $spec['product_retailer_id']],
            ],
            WhatsappContentKind::ProductList => [
                'type' => 'product_list',
                'action' => [
                    'catalog_id' => (string) $spec['catalog_id'],
                    'sections' => array_map(static fn (array $section): array => [
                        'title' => (string) $section['title'],
                        'product_items' => array_map(
                            static fn (mixed $id): array => ['product_retailer_id' => (string) $id],
                            array_values((array) $section['product_retailer_ids']),
                        ),
                    ], array_values((array) $spec['sections'])),
                ],
            ],
            default => self::flow($spec),
        };

        $type = $action['type'];
        unset($action['type']);

        return ['type' => $type, ...$interactive, ...$action];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{type: string, action: array<string, mixed>}
     */
    private static function flow(array $spec): array
    {
        $parameters = [
            'flow_message_version' => '3',
            'flow_token' => (string) $spec['flow_token'],
            'flow_id' => (string) $spec['flow_id'],
            'flow_cta' => (string) $spec['cta'],
            'mode' => ($spec['published'] ?? true) ? 'published' : 'draft',
        ];

        if (is_string($spec['screen'] ?? null)) {
            $parameters['flow_action'] = 'navigate';
            $parameters['flow_action_payload'] = [
                'screen' => $spec['screen'],
                ...(((array) ($spec['data'] ?? [])) === [] ? [] : ['data' => (array) $spec['data']]),
            ];
        }

        return ['type' => 'flow', 'action' => ['name' => 'flow', 'parameters' => $parameters]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function header(mixed $header): ?array
    {
        if (! is_array($header)) {
            return null;
        }

        if (($header['type'] ?? null) === 'text') {
            return ['type' => 'text', 'text' => (string) $header['text']];
        }

        if (($header['type'] ?? null) === 'media' && is_array($header['media'] ?? null)) {
            return self::mediaParameter(MediaReference::fromArray($header['media']));
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function mediaParameter(MediaReference $reference): array
    {
        $type = $reference->type->value;
        $object = ['id' => (string) $reference->providerMediaId];

        if ($type === 'document' && $reference->filename !== null) {
            $object['filename'] = $reference->filename;
        }

        return ['type' => $type, $type => $object];
    }
}
