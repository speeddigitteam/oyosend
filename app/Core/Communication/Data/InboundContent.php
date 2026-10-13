<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/**
 * The typed content of an inbound customer message (`ChannelEvent::$content`), beyond its plain text.
 *
 * Everything in it is untrusted customer or provider input, size-capped by the platform. Only the fields of
 * its `kind` are set; the rest are null/empty. Money values (`orderItems[].itemPrice`) are kept as the provider's
 * decimal string, never as a float.
 *
 * @phpstan-type OrderItem array{product_retailer_id: string, quantity: int, item_price: string, currency: string}
 */
final readonly class InboundContent
{
    public const int MAX_FLOW_JSON = 8000;

    public const int MAX_ORDER_ITEMS = 60;

    /**
     * @param  string|null  $replyId  `button_reply` / `list_reply` / template quick-reply: the id of the chosen option
     *                                (the template payload for a template button).
     * @param  string|null  $replyTitle  The title the customer saw and chose, up to 200 characters.
     * @param  string|null  $replyDescription  A list row's description.
     * @param  InboundMedia|null  $media  An image, document, audio, video or sticker.
     * @param  float|null  $latitude  Location pin.
     * @param  string|null  $reactionEmoji  Empty string when the customer removed the reaction.
     * @param  string|null  $reactedMessageId  The provider id of the message reacted to.
     * @param  list<array{product_retailer_id: string, quantity: int, item_price: string, currency: string}>  $orderItems
     * @param  string|null  $flowResponseJson  The Flow response (`nfm_reply.response_json`): UNTRUSTED JSON text,
     *                                         capped at 8000 characters (`flowResponseTruncated` says when it was cut,
     *                                         and then it is not valid JSON). Validate every key you read.
     * @param  string|null  $contextMessageId  The provider id of the message the customer replied to or quoted.
     */
    public function __construct(
        public InboundContentKind $kind,
        public ?string $replyId = null,
        public ?string $replyTitle = null,
        public ?string $replyDescription = null,
        public ?InboundMedia $media = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $locationName = null,
        public ?string $locationAddress = null,
        public ?string $reactionEmoji = null,
        public ?string $reactedMessageId = null,
        public ?string $orderCatalogId = null,
        public ?string $orderNote = null,
        public array $orderItems = [],
        public ?string $flowName = null,
        public ?string $flowToken = null,
        public ?string $flowResponseJson = null,
        public bool $flowResponseTruncated = false,
        public ?string $contextMessageId = null,
    ) {}

    /**
     * The Flow response decoded, or null when absent, truncated or not a JSON object. Still untrusted.
     *
     * @return array<string, mixed>|null
     */
    public function flowResponse(): ?array
    {
        if ($this->flowResponseJson === null || $this->flowResponseTruncated) {
            return null;
        }

        $decoded = json_decode($this->flowResponseJson, true, 16);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
