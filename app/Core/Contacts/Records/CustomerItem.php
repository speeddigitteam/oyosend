<?php

declare(strict_types=1);

namespace App\Core\Contacts\Records;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/** One record in a Customer 360 section, already formatted for the member who asked. */
final readonly class CustomerItem
{
    public const array TONES = ['neutral', 'brand', 'accent', 'ok', 'warn', 'danger', 'info'];

    /**
     * @param  string  $title  The record's own title or a short preview; untrusted text, rendered as text.
     * @param  string|null  $status  A translated status label.
     * @param  string|null  $tone  One of {@see TONES}, for the status badge.
     * @param  string|null  $detail  One short translated line, such as a stage or an amount.
     * @param  CarbonInterface|null  $at  The time that orders the record (UTC).
     * @param  string|null  $url  The owning module's page for the record.
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $status = null,
        public ?string $tone = null,
        public ?string $detail = null,
        public ?CarbonInterface $at = null,
        public ?string $url = null,
    ) {
        if ($tone !== null && ! in_array($tone, self::TONES, true)) {
            throw new InvalidArgumentException('Unknown customer item tone.');
        }
    }

    /** @return array{id: string, title: string, status: string|null, tone: string|null, detail: string|null, at: string|null, url: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => mb_substr($this->title, 0, 200),
            'status' => $this->status,
            'tone' => $this->tone,
            'detail' => $this->detail,
            'at' => $this->at?->toIso8601String(),
            'url' => $this->url,
        ];
    }
}
