<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/** The remote upload/processing state of one target, persisted between publisher runs. */
final readonly class PublishSession
{
    /**
     * @param  string|null  $remoteId  The platform's id of the item being uploaded (video id).
     * @param  string  $state  `uploading` or `processing`.
     * @param  int  $offset  Bytes the platform confirmed.
     * @param  array<string, mixed>  $meta  Adapter-private state (upload session, uploaded photo ids...). No secrets.
     * @param  string|null  $externalId  Set once the platform accepted the final "publish" call.
     */
    public function __construct(
        public ?string $remoteId = null,
        public string $state = 'uploading',
        public int $offset = 0,
        public array $meta = [],
        public ?string $externalId = null,
    ) {}
}
