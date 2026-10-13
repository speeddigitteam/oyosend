<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers\Cloud;

use App\Core\Communication\Data\InboundContent;
use App\Core\Communication\Data\InboundContentKind;
use App\Core\Communication\Data\InboundMedia;

/**
 * Typed content of an inbound Cloud API message, from the webhook's `messages[]` object. Everything is customer
 * input: every string is clipped, every list is capped, and nothing here is trusted or evaluated. Money
 * (`item_price`) stays the provider's decimal string.
 *
 * Plain text answers null (the event's `text` is the content); an unknown type answers `Unsupported`.
 */
final class CloudInbound
{
    public const int MAX_FLOW_JSON = InboundContent::MAX_FLOW_JSON;

    public const int MAX_ORDER_ITEMS = InboundContent::MAX_ORDER_ITEMS;

    private const array MEDIA_TYPES = ['image', 'document', 'audio', 'video', 'sticker'];

    /**
     * @param  array<array-key, mixed>  $message
     */
    public static function content(array $message, string $type): ?InboundContent
    {
        $context = is_array($message['context'] ?? null) && is_string($message['context']['id'] ?? null)
            ? self::clip($message['context']['id'], 150)
            : null;

        if ($type === 'text') {
            return null;
        }

        if ($type === 'button' && is_array($message['button'] ?? null)) {
            return new InboundContent(
                InboundContentKind::TemplateButton,
                replyId: self::clip($message['button']['payload'] ?? null, 256),
                replyTitle: self::clip($message['button']['text'] ?? null, 200),
                contextMessageId: $context,
            );
        }

        if ($type === 'interactive' && is_array($message['interactive'] ?? null)) {
            return self::interactive($message['interactive'], $context);
        }

        if (in_array($type, self::MEDIA_TYPES, true) && is_array($message[$type] ?? null)) {
            $media = $message[$type];
            $id = self::clip($media['id'] ?? null, 100);

            if ($id === null) {
                return new InboundContent(InboundContentKind::Unsupported, contextMessageId: $context);
            }

            return new InboundContent(
                InboundContentKind::Media,
                media: new InboundMedia(
                    type: $type,
                    providerMediaId: $id,
                    mimeType: self::clip($media['mime_type'] ?? null, 100),
                    sha256: self::clip($media['sha256'] ?? null, 64),
                    caption: self::clip($media['caption'] ?? null, 1024),
                    filename: self::clip($media['filename'] ?? null, 240),
                    voice: ($media['voice'] ?? false) === true,
                ),
                contextMessageId: $context,
            );
        }

        if ($type === 'location' && is_array($message['location'] ?? null)) {
            $location = $message['location'];
            $latitude = is_numeric($location['latitude'] ?? null) ? (float) $location['latitude'] : null;
            $longitude = is_numeric($location['longitude'] ?? null) ? (float) $location['longitude'] : null;

            if ($latitude === null || $longitude === null || abs($latitude) > 90 || abs($longitude) > 180) {
                return new InboundContent(InboundContentKind::Unsupported, contextMessageId: $context);
            }

            return new InboundContent(
                InboundContentKind::Location,
                latitude: $latitude,
                longitude: $longitude,
                locationName: self::clip($location['name'] ?? null, 300),
                locationAddress: self::clip($location['address'] ?? null, 300),
                contextMessageId: $context,
            );
        }

        if ($type === 'reaction' && is_array($message['reaction'] ?? null)) {
            return new InboundContent(
                InboundContentKind::Reaction,
                reactionEmoji: self::clip($message['reaction']['emoji'] ?? '', 16) ?? '',
                reactedMessageId: self::clip($message['reaction']['message_id'] ?? null, 150),
                contextMessageId: $context,
            );
        }

        if ($type === 'order' && is_array($message['order'] ?? null)) {
            return self::order($message['order'], $context);
        }

        if ($type === 'contacts') {
            return new InboundContent(InboundContentKind::Contacts, contextMessageId: $context);
        }

        return new InboundContent(InboundContentKind::Unsupported, contextMessageId: $context);
    }

    /**
     * @param  array<array-key, mixed>  $interactive
     */
    private static function interactive(array $interactive, ?string $context): InboundContent
    {
        $reply = $interactive['button_reply'] ?? null;

        if (is_array($reply)) {
            return new InboundContent(
                InboundContentKind::ButtonReply,
                replyId: self::clip($reply['id'] ?? null, 256),
                replyTitle: self::clip($reply['title'] ?? null, 200),
                contextMessageId: $context,
            );
        }

        $reply = $interactive['list_reply'] ?? null;

        if (is_array($reply)) {
            return new InboundContent(
                InboundContentKind::ListReply,
                replyId: self::clip($reply['id'] ?? null, 200),
                replyTitle: self::clip($reply['title'] ?? null, 200),
                replyDescription: self::clip($reply['description'] ?? null, 200),
                contextMessageId: $context,
            );
        }

        $flow = $interactive['nfm_reply'] ?? null;

        if (is_array($flow)) {
            $raw = is_string($flow['response_json'] ?? null) ? $flow['response_json'] : null;
            $truncated = $raw !== null && strlen($raw) > self::MAX_FLOW_JSON;
            $json = $raw === null ? null : mb_strcut($raw, 0, self::MAX_FLOW_JSON);
            $token = null;

            if ($json !== null && ! $truncated) {
                $decoded = json_decode($json, true, 16);
                $candidate = is_array($decoded) ? ($decoded['flow_token'] ?? null) : null;
                $token = is_string($candidate) ? self::clip($candidate, 100) : null;
            }

            return new InboundContent(
                InboundContentKind::FlowResponse,
                flowName: self::clip($flow['name'] ?? null, 60),
                flowToken: $token,
                flowResponseJson: $json,
                flowResponseTruncated: $truncated,
                contextMessageId: $context,
            );
        }

        return new InboundContent(InboundContentKind::Unsupported, contextMessageId: $context);
    }

    /**
     * @param  array<array-key, mixed>  $order
     */
    private static function order(array $order, ?string $context): InboundContent
    {
        $items = [];

        foreach (array_slice(array_values((array) ($order['product_items'] ?? [])), 0, self::MAX_ORDER_ITEMS) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = self::clip($item['product_retailer_id'] ?? null, 100);

            if ($id === null) {
                continue;
            }

            $price = $item['item_price'] ?? null;
            $quantity = $item['quantity'] ?? 1;

            $items[] = [
                'product_retailer_id' => $id,
                'quantity' => is_numeric($quantity) ? max(0, min(100000, (int) $quantity)) : 0,
                // The provider's own decimal text; never a float.
                'item_price' => is_string($price) || is_int($price) || is_float($price) ? self::clip((string) $price, 32) ?? '' : '',
                'currency' => self::clip($item['currency'] ?? null, 8) ?? '',
            ];
        }

        return new InboundContent(
            InboundContentKind::Order,
            orderCatalogId: self::clip($order['catalog_id'] ?? null, 64),
            orderNote: self::clip($order['text'] ?? null, 1000),
            orderItems: $items,
            contextMessageId: $context,
        );
    }

    private static function clip(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
