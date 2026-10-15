<?php

declare(strict_types=1);

namespace App\Modules\Links\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Links\Models\Link;

final class LinkCreated extends TenantDomainEvent
{
    public readonly string $linkId;

    public readonly string $kind;

    public readonly string $title;

    public readonly ?string $folder;

    public readonly ?string $ownerId;

    public readonly bool $isPinned;

    public function __construct(Link $link)
    {
        parent::__construct();
        $this->linkId = $link->id;
        $this->kind = $link->kind->value;
        $this->title = $link->title;
        $this->folder = $link->folder;
        $this->ownerId = $link->owner_id;
        $this->isPinned = $link->is_pinned;
    }

    public static function name(): string
    {
        return 'links.link.created';
    }

    public function payload(): array
    {
        return [
            'link_id' => $this->linkId,
            'kind' => $this->kind,
            'title' => $this->title,
            'folder' => $this->folder,
            'owner_id' => $this->ownerId,
            'is_pinned' => $this->isPinned,
        ];
    }
}
