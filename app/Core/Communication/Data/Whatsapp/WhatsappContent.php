<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Whatsapp;

use InvalidArgumentException;
use JsonException;

/**
 * The rich content of one WhatsApp message, validated against the WhatsApp Business Platform's own limits before
 * anything is queued. Build it with a named constructor, hand it to `MarketingMessage` (`whatsapp:`) and the
 * platform does the rest: window rule, consent, media upload, sender number, delivery.
 *
 * The text of the message (the interactive/product/flow body, or the template's rendered text for the log) is
 * `MarketingMessage::$body`; `assertBody()` checks it against the kind's limit.
 *
 * It holds no secret and no path: media is a {@see MediaReference}. It serializes to JSON of at most
 * {@see self::MAX_JSON} bytes (the outbox stores it in the message's meta).
 *
 * The Graph API mapping (message object of `POST /{version}/{phone_number_id}/messages`) is documented in
 * docs/COMMUNICATION.md section 14a.
 */
final readonly class WhatsappContent
{
    public const int MAX_JSON = 16000;

    public const int MAX_BODY = 1024;

    public const int MAX_BUTTONS = 3;

    public const int MAX_ROWS = 10;

    public const int MAX_SECTIONS = 10;

    public const int MAX_PRODUCTS = 30;

    public const int MAX_TEMPLATE_PARAMETERS = 20;

    public const int MAX_FLOW_DATA_BYTES = 4000;

    /** @param array<string, mixed> $spec Normalized and validated by the named constructors. */
    private function __construct(public WhatsappContentKind $kind, public array $spec) {}

    /**
     * @param  list<string>  $bodyParameters  Values of {{1}}, {{2}}, ... (up to 20, each up to 1024 characters).
     * @param  list<array<string, mixed>>  $buttons  Button parameters, each `sub_type`, `index`, `value`: `url` (the
     *                                               dynamic suffix, up to 2000), `quick_reply` (the payload, up to 256) or `copy_code`
     *                                               (the code, up to 15). `index` is the button's position, 0 to 9.
     */
    public static function template(
        string $name,
        string $language,
        array $bodyParameters = [],
        ?string $headerText = null,
        ?MediaReference $headerMedia = null,
        array $buttons = [],
    ): self {
        if (preg_match('/^[a-z0-9_]{1,512}$/', $name) !== 1) {
            throw new InvalidArgumentException('A template name is lower-case letters, digits and underscores.');
        }

        if (preg_match('/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $language) !== 1) {
            throw new InvalidArgumentException('A template language is a code such as en or en_US.');
        }

        if ($headerText !== null && $headerMedia !== null) {
            throw new InvalidArgumentException('A template header is text or media, not both.');
        }

        if (count($bodyParameters) > self::MAX_TEMPLATE_PARAMETERS) {
            throw new InvalidArgumentException('At most '.self::MAX_TEMPLATE_PARAMETERS.' template parameters.');
        }

        $parameters = [];

        foreach ($bodyParameters as $value) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

            if (mb_strlen($text) > 1024) {
                throw new InvalidArgumentException('A template parameter has up to 1024 characters.');
            }

            // Meta rejects an empty parameter.
            $parameters[] = $text === '' ? '-' : $text;
        }

        $cleanButtons = [];

        foreach ($buttons as $button) {
            $subType = (string) ($button['sub_type'] ?? '');
            $index = $button['index'] ?? null;
            $value = (string) ($button['value'] ?? '');
            $limit = match ($subType) {
                'url' => 2000,
                'quick_reply' => 256,
                'copy_code' => 15,
                default => throw new InvalidArgumentException('A template button parameter is url, quick_reply or copy_code.'),
            };

            if (! is_int($index) || $index < 0 || $index > 9 || $value === '' || mb_strlen($value) > $limit) {
                throw new InvalidArgumentException('A template button parameter needs an index of 0 to 9 and a value within its limit.');
            }

            $cleanButtons[] = ['sub_type' => $subType, 'index' => $index, 'value' => $value];
        }

        if (count($cleanButtons) > 10) {
            throw new InvalidArgumentException('At most 10 template button parameters.');
        }

        return self::make(WhatsappContentKind::Template, [
            'name' => $name,
            'language' => $language,
            'body_parameters' => $parameters,
            'header' => self::header($headerText, $headerMedia),
            'buttons' => $cleanButtons,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $buttons  1 to 3 reply buttons, each `id` and `title`: id up to 256, title up to 20
     *                                               characters, ids and titles unique.
     */
    public static function buttons(array $buttons, ?string $headerText = null, ?MediaReference $headerMedia = null, ?string $footer = null): self
    {
        if ($buttons === [] || count($buttons) > self::MAX_BUTTONS) {
            throw new InvalidArgumentException('A reply-button message has 1 to 3 buttons.');
        }

        $clean = [];
        $ids = [];
        $titles = [];

        foreach ($buttons as $button) {
            $id = trim((string) ($button['id'] ?? ''));
            $title = trim((string) ($button['title'] ?? ''));

            if ($id === '' || mb_strlen($id) > 256 || $title === '' || mb_strlen($title) > 20) {
                throw new InvalidArgumentException('A reply button has an id of up to 256 and a title of up to 20 characters.');
            }

            $ids[] = $id;
            $titles[] = mb_strtolower($title);
            $clean[] = ['id' => $id, 'title' => $title];
        }

        if (count(array_unique($ids)) !== count($ids) || count(array_unique($titles)) !== count($titles)) {
            throw new InvalidArgumentException('Reply button ids and titles must be unique.');
        }

        return self::make(WhatsappContentKind::Buttons, [
            'buttons' => $clean,
            'header' => self::header($headerText, $headerMedia),
            'footer' => self::footer($footer),
        ]);
    }

    /**
     * @param  string  $buttonLabel  The label of the button that opens the list, up to 20 characters.
     * @param  list<array<string, mixed>>  $sections  Each `title?` and `rows` (`id`, `title`, `description?`):
     *                                                Up to 10 sections and 10 rows in total; row id up to 200, title up to 24, description up to 72; a section
     *                                                title (up to 24) is required when there is more than one section.
     */
    public static function list(string $buttonLabel, array $sections, ?string $headerText = null, ?string $footer = null): self
    {
        $label = trim($buttonLabel);

        if ($label === '' || mb_strlen($label) > 20) {
            throw new InvalidArgumentException('A list button label has 1 to 20 characters.');
        }

        if ($sections === [] || count($sections) > self::MAX_SECTIONS) {
            throw new InvalidArgumentException('A list message has 1 to 10 sections.');
        }

        $clean = [];
        $rows = 0;
        $ids = [];

        foreach ($sections as $section) {
            $title = isset($section['title']) ? trim((string) $section['title']) : '';

            if (count($sections) > 1 && $title === '') {
                throw new InvalidArgumentException('Every section of a multi-section list needs a title.');
            }

            if (mb_strlen($title) > 24) {
                throw new InvalidArgumentException('A section title has up to 24 characters.');
            }

            $sectionRows = [];

            foreach ((array) ($section['rows'] ?? []) as $row) {
                $id = trim((string) ($row['id'] ?? ''));
                $rowTitle = trim((string) ($row['title'] ?? ''));
                $description = isset($row['description']) ? trim((string) $row['description']) : '';

                if ($id === '' || mb_strlen($id) > 200 || $rowTitle === '' || mb_strlen($rowTitle) > 24 || mb_strlen($description) > 72) {
                    throw new InvalidArgumentException('A list row has an id of up to 200, a title of up to 24 and a description of up to 72 characters.');
                }

                $ids[] = $id;
                $rows++;
                $sectionRows[] = ['id' => $id, 'title' => $rowTitle] + ($description === '' ? [] : ['description' => $description]);
            }

            if ($sectionRows === []) {
                throw new InvalidArgumentException('A list section needs at least one row.');
            }

            $clean[] = ($title === '' ? [] : ['title' => $title]) + ['rows' => $sectionRows];
        }

        if ($rows > self::MAX_ROWS) {
            throw new InvalidArgumentException('A list message has at most 10 rows in total.');
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('List row ids must be unique.');
        }

        return self::make(WhatsappContentKind::List, [
            'button' => $label,
            'sections' => $clean,
            'header' => self::header($headerText, null),
            'footer' => self::footer($footer),
        ]);
    }

    /** A call-to-action URL button (`cta_url`). The URL must be absolute http(s). */
    public static function ctaUrl(string $label, string $url, ?string $headerText = null, ?MediaReference $headerMedia = null, ?string $footer = null): self
    {
        $label = trim($label);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($label === '' || mb_strlen($label) > 20) {
            throw new InvalidArgumentException('A call-to-action label has 1 to 20 characters.');
        }

        if (! in_array($scheme, ['http', 'https'], true) || mb_strlen($url) > 2000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('A call-to-action URL is an absolute http(s) address of up to 2000 characters.');
        }

        return self::make(WhatsappContentKind::CtaUrl, [
            'label' => $label,
            'url' => $url,
            'header' => self::header($headerText, $headerMedia),
            'footer' => self::footer($footer),
        ]);
    }

    /** A media message. `caption` (up to 1024) is not allowed for audio. */
    public static function media(MediaReference $media, ?string $caption = null): self
    {
        $caption = $caption === null ? null : trim($caption);

        if ($caption === '') {
            $caption = null;
        }

        if ($caption !== null && ($media->type === WhatsappMediaType::Audio || mb_strlen($caption) > self::MAX_BODY)) {
            throw new InvalidArgumentException('A caption has up to 1024 characters and audio has none.');
        }

        return self::make(WhatsappContentKind::Media, ['media' => $media->toArray(), 'caption' => $caption]);
    }

    public static function product(string $catalogId, string $productRetailerId, ?string $footer = null): self
    {
        self::assertId($catalogId, 'catalogue');
        self::assertRetailerId($productRetailerId);

        return self::make(WhatsappContentKind::Product, [
            'catalog_id' => $catalogId,
            'product_retailer_id' => $productRetailerId,
            'footer' => self::footer($footer),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $sections  Each `title` and `product_retailer_ids`. Up to 10 sections, up to
     *                                                30 products in total; section title up to 24 characters.
     */
    public static function productList(string $catalogId, string $headerText, array $sections, ?string $footer = null): self
    {
        self::assertId($catalogId, 'catalogue');
        $header = self::textHeader($headerText);

        if ($sections === [] || count($sections) > self::MAX_SECTIONS) {
            throw new InvalidArgumentException('A product list has 1 to 10 sections.');
        }

        $clean = [];
        $total = 0;

        foreach ($sections as $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $ids = array_values(array_map('strval', (array) ($section['product_retailer_ids'] ?? [])));

            if ($title === '' || mb_strlen($title) > 24 || $ids === []) {
                throw new InvalidArgumentException('A product section has a title of up to 24 characters and at least one product.');
            }

            foreach ($ids as $id) {
                self::assertRetailerId($id);
            }

            $total += count($ids);
            $clean[] = ['title' => $title, 'product_retailer_ids' => $ids];
        }

        if ($total > self::MAX_PRODUCTS) {
            throw new InvalidArgumentException('A product list has at most 30 products.');
        }

        return self::make(WhatsappContentKind::ProductList, [
            'catalog_id' => $catalogId,
            'header' => $header,
            'sections' => $clean,
            'footer' => self::footer($footer),
        ]);
    }

    /**
     * A WhatsApp Flow message.
     *
     * @param  string  $flowId  The id of a Flow of the business account.
     * @param  string  $flowToken  Your correlation token, up to 100 characters; it comes back with the response.
     * @param  string|null  $screen  The first screen (`navigate` action); `data` is its initial data.
     * @param  array<array-key, mixed>  $data  Flat map of scalar values: initial data, at most 4000 bytes as JSON.
     * @param  bool  $published  False sends the draft Flow (testing).
     */
    public static function flow(
        string $flowId,
        string $ctaLabel,
        string $flowToken,
        ?string $screen = null,
        array $data = [],
        bool $published = true,
        ?string $headerText = null,
        ?string $footer = null,
    ): self {
        self::assertId($flowId, 'Flow');
        $label = trim($ctaLabel);

        if ($label === '' || mb_strlen($label) > 20) {
            throw new InvalidArgumentException('A Flow button label has 1 to 20 characters.');
        }

        if ($flowToken === '' || mb_strlen($flowToken) > 100) {
            throw new InvalidArgumentException('A Flow token has 1 to 100 characters.');
        }

        if ($screen !== null && preg_match('/^[A-Za-z0-9_]{1,100}$/', $screen) !== 1) {
            throw new InvalidArgumentException('A Flow screen id is letters, digits and underscores.');
        }

        if ($data !== [] && $screen === null) {
            throw new InvalidArgumentException('Flow data needs the screen it is for.');
        }

        foreach ($data as $key => $value) {
            if (preg_match('/^[A-Za-z0-9_]{1,60}$/', (string) $key) !== 1 || (! is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('Flow data is a flat map of scalar values.');
            }
        }

        if (strlen((string) json_encode($data)) > self::MAX_FLOW_DATA_BYTES) {
            throw new InvalidArgumentException('Flow data is at most 4000 bytes.');
        }

        return self::make(WhatsappContentKind::Flow, [
            'flow_id' => $flowId,
            'cta' => $label,
            'flow_token' => $flowToken,
            'screen' => $screen,
            'data' => $data,
            'published' => $published,
            'header' => self::header($headerText, null),
            'footer' => self::footer($footer),
        ]);
    }

    /** Check the message text against this kind's limit (the platform calls it; an app may call it earlier). */
    public function assertBody(string $body): void
    {
        $limit = match ($this->kind) {
            WhatsappContentKind::Template, WhatsappContentKind::Media => 4096,
            default => self::MAX_BODY,
        };

        if (mb_strlen($body) > $limit) {
            throw new InvalidArgumentException('The text of a '.$this->kind->value.' message has up to '.$limit.' characters.');
        }
    }

    /**
     * Every file reference in the content (header or media message), in order.
     *
     * @return list<MediaReference>
     */
    public function mediaReferences(): array
    {
        $references = [];
        $header = $this->spec['header'] ?? null;

        if (is_array($header) && ($header['type'] ?? null) === 'media' && is_array($header['media'] ?? null)) {
            $references[] = MediaReference::fromArray($header['media']);
        }

        if ($this->kind === WhatsappContentKind::Media && is_array($this->spec['media'] ?? null)) {
            $references[] = MediaReference::fromArray($this->spec['media']);
        }

        return $references;
    }

    /**
     * The same content with stored media ids replaced by the provider ids they were uploaded as.
     *
     * @param  array<string, string>  $providerIds  stored media id => provider media id
     */
    public function withProviderMedia(array $providerIds): self
    {
        $spec = $this->spec;
        $swap = static function (array $media) use ($providerIds): array {
            $stored = $media['media_id'] ?? null;

            if (is_string($stored) && isset($providerIds[$stored])) {
                unset($media['media_id']);
                $media['provider_media_id'] = $providerIds[$stored];
            }

            return $media;
        };

        if (is_array($spec['header'] ?? null) && is_array($spec['header']['media'] ?? null)) {
            $spec['header']['media'] = $swap($spec['header']['media']);
        }

        if (is_array($spec['media'] ?? null)) {
            $spec['media'] = $swap($spec['media']);
        }

        return new self($this->kind, $spec);
    }

    public function toJson(): string
    {
        return (string) json_encode(['kind' => $this->kind->value] + $this->spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Re-validates everything: the stored JSON is read back through the same named constructors.
     *
     * @throws InvalidArgumentException When the JSON is not a valid content.
     */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_JSON) {
            throw new InvalidArgumentException('The WhatsApp content is too large.');
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The WhatsApp content is not valid JSON.');
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('The WhatsApp content is not valid.');
        }

        $kind = WhatsappContentKind::tryFrom((string) ($data['kind'] ?? ''))
            ?? throw new InvalidArgumentException('The WhatsApp content kind is unknown.');
        $header = is_array($data['header'] ?? null) ? $data['header'] : null;
        $headerText = $header !== null && ($header['type'] ?? null) === 'text' ? (string) ($header['text'] ?? '') : null;
        $headerMedia = $header !== null && ($header['type'] ?? null) === 'media' && is_array($header['media'] ?? null)
            ? MediaReference::fromArray($header['media'])
            : null;
        $footer = isset($data['footer']) ? (string) $data['footer'] : null;

        return match ($kind) {
            WhatsappContentKind::Template => self::template(
                (string) ($data['name'] ?? ''),
                (string) ($data['language'] ?? ''),
                array_values(array_map('strval', (array) ($data['body_parameters'] ?? []))),
                $headerText,
                $headerMedia,
                array_values((array) ($data['buttons'] ?? [])),
            ),
            WhatsappContentKind::Buttons => self::buttons(array_values((array) ($data['buttons'] ?? [])), $headerText, $headerMedia, $footer),
            WhatsappContentKind::List => self::list((string) ($data['button'] ?? ''), array_values((array) ($data['sections'] ?? [])), $headerText, $footer),
            WhatsappContentKind::CtaUrl => self::ctaUrl((string) ($data['label'] ?? ''), (string) ($data['url'] ?? ''), $headerText, $headerMedia, $footer),
            WhatsappContentKind::Media => self::media(
                MediaReference::fromArray((array) ($data['media'] ?? [])),
                isset($data['caption']) ? (string) $data['caption'] : null,
            ),
            WhatsappContentKind::Product => self::product((string) ($data['catalog_id'] ?? ''), (string) ($data['product_retailer_id'] ?? ''), $footer),
            WhatsappContentKind::ProductList => self::productList((string) ($data['catalog_id'] ?? ''), (string) ($headerText ?? ''), array_values((array) ($data['sections'] ?? [])), $footer),
            WhatsappContentKind::Flow => self::flow(
                (string) ($data['flow_id'] ?? ''),
                (string) ($data['cta'] ?? ''),
                (string) ($data['flow_token'] ?? ''),
                isset($data['screen']) ? (string) $data['screen'] : null,
                (array) ($data['data'] ?? []),
                (bool) ($data['published'] ?? true),
                $headerText,
                $footer,
            ),
        };
    }

    /** @param array<string, mixed> $spec */
    private static function make(WhatsappContentKind $kind, array $spec): self
    {
        $content = new self($kind, $spec);

        if (strlen($content->toJson()) > self::MAX_JSON) {
            throw new InvalidArgumentException('The WhatsApp content is too large.');
        }

        return $content;
    }

    /** @return array{type: string, text?: string, media?: array<string, string>}|null */
    private static function header(?string $text, ?MediaReference $media): ?array
    {
        if ($text !== null && $media !== null) {
            throw new InvalidArgumentException('A header is text or media, not both.');
        }

        if ($media !== null) {
            if ($media->type === WhatsappMediaType::Audio) {
                throw new InvalidArgumentException('A header is text, an image, a video or a document.');
            }

            return ['type' => 'media', 'media' => $media->toArray()];
        }

        return $text !== null ? self::textHeader($text) : null;
    }

    /** @return array{type: string, text: string} */
    private static function textHeader(string $text): array
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > 60) {
            throw new InvalidArgumentException('A header text has 1 to 60 characters.');
        }

        return ['type' => 'text', 'text' => $text];
    }

    private static function footer(?string $footer): ?string
    {
        $footer = $footer === null ? null : trim($footer);

        if ($footer === null || $footer === '') {
            return null;
        }

        if (mb_strlen($footer) > 60) {
            throw new InvalidArgumentException('A footer has up to 60 characters.');
        }

        return $footer;
    }

    private static function assertId(string $id, string $what): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            throw new InvalidArgumentException('A '.$what.' id is letters, digits, dashes and underscores of up to 64 characters.');
        }
    }

    private static function assertRetailerId(string $id): void
    {
        if ($id === '' || mb_strlen($id) > 100 || preg_match('/[\x00-\x1F]/', $id) === 1) {
            throw new InvalidArgumentException('A product retailer id has 1 to 100 characters.');
        }
    }
}
