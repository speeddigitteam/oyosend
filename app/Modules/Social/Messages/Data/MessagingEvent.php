<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Data;

use Carbon\CarbonImmutable;

/**
 * One Messenger or Instagram messaging event from a verified webhook delivery, reduced to the fields used. Text is
 * still untrusted here; the ingestor cleans and caps it.
 */
final readonly class MessagingEvent
{
    /** A person wrote to the Page. */
    public const string MESSAGE = 'message';

    /** The Page wrote to the person (here or elsewhere, e.g. Meta Business Suite): `is_echo`. */
    public const string ECHO = 'echo';

    /** The person read the Page's messages (up to the watermark, or up to one message on Instagram). */
    public const string READ = 'read';

    /** The Page's messages were delivered (the listed ids, or everything up to the watermark). */
    public const string DELIVERY = 'delivery';

    /** The person unsent a message (Instagram `is_deleted`). */
    public const string DELETED = 'deleted';

    /**
     * @param  string  $platform  `messenger` or `instagram`.
     * @param  string  $entryId  The Page id (Messenger) or the Instagram professional account id (Instagram).
     * @param  string  $participantId  The person's page-scoped id (PSID / IGSID).
     * @param  list<string>  $mids  Message ids a delivery or an Instagram read names.
     * @param  string  $key  Stable per event, for the failure cap.
     */
    public function __construct(
        public string $platform,
        public string $entryId,
        public string $kind,
        public string $participantId,
        public CarbonImmutable $at,
        public ?string $mid = null,
        public ?string $text = null,
        public ?string $attachmentType = null,
        public ?CarbonImmutable $watermark = null,
        public array $mids = [],
        public string $key = '',
    ) {}
}
