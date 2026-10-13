<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Communication\Contracts\MediaGateway;
use App\Core\Communication\Data\InboundMedia;
use App\Core\Communication\Data\MediaContents;
use App\Modules\WhatsappMarketing\Actions\ConversationAccess;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Support\Permissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the file of an inbound WhatsApp media message, downloaded on demand through the platform's
 * {@see MediaGateway} (ADR 0022). It is tenant-scoped (the message resolves within the active tenant) and
 * access-checked (the viewer must be able to see the conversation), and it answers with the headers of
 * {@see MediaContents::headers()} so even an HTML or SVG file can run nothing: the
 * sniffed type, `nosniff`, a forced download and a sandboxing CSP. Outbound media the workspace sent is not
 * served here; only what a customer sent is downloaded.
 */
final class MediaController
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly MediaGateway $media,
        private readonly Presenter $presenter,
    ) {}

    public function show(Request $request, Message $message): Response
    {
        $actor = $this->presenter->actor($request);
        $conversation = $message->conversation;
        abort_if($conversation === null, 404);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_VIEW, $conversation);

        $attachment = collect($message->attachments ?? [])
            ->first(static fn (array $attachment): bool => ($attachment['downloadable'] ?? false) === true && is_string($attachment['provider_media_id'] ?? null));

        abort_if($attachment === null, 404);

        $inbound = new InboundMedia(
            type: (string) $attachment['kind'],
            providerMediaId: (string) $attachment['provider_media_id'],
            mimeType: is_string($attachment['mime'] ?? null) ? $attachment['mime'] : null,
            filename: is_string($attachment['filename'] ?? null) ? $attachment['filename'] : null,
        );

        $downloaded = $this->media->download($inbound, is_string($attachment['number_id'] ?? null) ? $attachment['number_id'] : null);
        abort_unless($downloaded->ok && $downloaded->media !== null, 404);

        $contents = $this->media->read($downloaded->media->id);
        abort_if($contents === null, 404);

        return response($contents->bytes, 200, $contents->headers());
    }
}
