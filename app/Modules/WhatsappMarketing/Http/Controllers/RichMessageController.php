<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Communication\Contracts\MediaGateway;
use App\Core\Communication\Data\Whatsapp\MediaReference;
use App\Core\Communication\Data\Whatsapp\WhatsappContent;
use App\Core\Communication\Data\Whatsapp\WhatsappMediaType;
use App\Core\Support\Toast;
use App\Modules\WhatsappMarketing\Actions\ConversationAccess;
use App\Modules\WhatsappMarketing\Actions\Messenger;
use App\Modules\WhatsappMarketing\Actions\RichComposer;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Support\Interactive;
use App\Modules\WhatsappMarketing\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rich replies in the open 24-hour window (ADR 0022): reply buttons, a list message, a call-to-action URL and
 * media (image, document, video, audio) with an optional caption. Each is built as a {@see RichComposer}
 * {@see WhatsappContent} and sent through the gateway, which enforces the
 * window. The agent picks the sending business number (`sender_number_id`).
 */
final class RichMessageController
{
    /** A sending business number id is a workspace number (a ULID). */
    private const string NUMBER_RULE = 'regex:/^[0-9A-Za-z]{26}$/';

    public function __construct(
        private readonly ConversationAccess $access,
        private readonly RichComposer $composer,
        private readonly Messenger $messenger,
        private readonly MediaGateway $media,
        private readonly Presenter $presenter,
    ) {}

    public function interactive(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_REPLY, $conversation);
        $input = $request->validate([
            'body' => ['required', 'string', 'max:'.Interactive::MAX_BODY],
            'sender_number_id' => ['nullable', 'string', 'size:26', self::NUMBER_RULE],
            'kind' => ['required', Rule::in([Interactive::BUTTONS, Interactive::LIST])],
            'button' => ['nullable', 'string', 'max:'.Interactive::LIST_BUTTON],
            'options' => ['required', 'array', 'min:1', 'max:'.Interactive::MAX_ROWS],
            'options.*' => ['required', 'string', 'max:'.Interactive::ROW_TITLE],
        ]);

        $interactive = Interactive::fromInput([
            'kind' => $input['kind'],
            'button' => $input['button'] ?? null,
            'options' => $input['options'],
        ]);

        $this->composer->sendInteractive($conversation, $interactive, $input['body'], $this->presenter->membership($actor), $input['sender_number_id'] ?? null);
        Toast::success(__('Reply queued.'));

        return back();
    }

    public function cta(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_REPLY, $conversation);
        $input = $request->validate([
            'body' => ['required', 'string', 'max:'.Interactive::MAX_BODY],
            'label' => ['required', 'string', 'max:20'],
            'url' => ['required', 'string', 'max:2000', 'url'],
            'sender_number_id' => ['nullable', 'string', 'size:26', self::NUMBER_RULE],
        ]);

        $this->composer->sendCtaUrl($conversation, $input['body'], $input['label'], $input['url'], $this->presenter->membership($actor), $input['sender_number_id'] ?? null);
        Toast::success(__('Reply queued.'));

        return back();
    }

    public function media(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_REPLY, $conversation);
        $input = $request->validate([
            'type' => ['required', Rule::in(array_map(static fn (WhatsappMediaType $type): string => $type->value, WhatsappMediaType::cases()))],
            'caption' => ['nullable', 'string', 'max:1024'],
            'media_id' => ['nullable', 'string', 'size:26'],
            'file' => ['nullable', 'file', 'max:102400'],
            'sender_number_id' => ['nullable', 'string', 'size:26', self::NUMBER_RULE],
        ]);

        $type = WhatsappMediaType::from($input['type']);
        [$mediaId, $filename] = $this->resolveMedia($input, $type);

        $this->composer->sendMedia(
            $conversation,
            $mediaId,
            $type,
            $input['caption'] ?? null,
            $filename,
            $this->presenter->membership($actor),
            $input['sender_number_id'] ?? null,
        );
        Toast::success(__('Reply queued.'));

        return back();
    }

    /** An approved template with a media header and/or dynamic button values (ADR 0022), inside or outside the window. */
    public function template(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_REPLY, $conversation);
        $input = $request->validate([
            'template_id' => ['required', 'string', 'size:26'],
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*' => ['nullable', 'string', 'max:1024'],
            'sender_number_id' => ['nullable', 'string', 'size:26', self::NUMBER_RULE],
            'header_type' => ['nullable', Rule::in(['image', 'video', 'document'])],
            'header_media_id' => ['nullable', 'string', 'size:26'],
            'header_file' => ['nullable', 'file', 'max:102400'],
            'buttons' => ['nullable', 'array', 'max:10'],
            'buttons.*.index' => ['required_with:buttons', 'integer', 'min:0', 'max:9'],
            'buttons.*.type' => ['required_with:buttons', Rule::in(['url', 'quick_reply', 'copy_code'])],
            'buttons.*.value' => ['required_with:buttons', 'string', 'max:2000'],
        ]);

        $template = Template::query()->find($input['template_id']);
        abort_if($template === null, 404);

        $header = $this->resolveHeader($input);
        $buttons = array_values(array_map(
            static fn (array $button): array => ['sub_type' => (string) $button['type'], 'index' => (int) $button['index'], 'value' => (string) $button['value']],
            $input['buttons'] ?? [],
        ));

        $this->messenger->sendTemplate(
            $conversation,
            $template,
            array_values(array_map('strval', $input['variables'] ?? [])),
            $this->presenter->membership($actor),
            headerMedia: $header,
            buttonParams: $buttons,
            senderNumberId: $input['sender_number_id'] ?? null,
        );
        Toast::success(__('Template queued.'));

        return back();
    }

    /**
     * Resolve the optional media header into a {@see MediaReference}: an uploaded file or an existing media id.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function resolveHeader(array $input): ?MediaReference
    {
        $type = $input['header_type'] ?? null;

        if ($type === null) {
            return null;
        }

        $mediaType = WhatsappMediaType::from($type);
        [$mediaId, $filename] = $this->resolveMedia(['file' => $input['header_file'] ?? null, 'media_id' => $input['header_media_id'] ?? null], $mediaType);

        return new MediaReference($mediaType, mediaId: $mediaId, filename: $filename);
    }

    /**
     * Store an uploaded file in the workspace media store, or take an existing media id.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: string|null} The stored media id and the file name to send.
     *
     * @throws ValidationException
     */
    private function resolveMedia(array $input, WhatsappMediaType $type): array
    {
        $file = $input['file'] ?? null;

        if ($file !== null) {
            $outcome = $this->media->store($type, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName(), $file->getClientMimeType());

            if (! $outcome->ok || $outcome->media === null) {
                throw ValidationException::withMessages(['file' => $this->storeMessage((string) $outcome->reason)]);
            }

            return [$outcome->media->id, $outcome->media->filename];
        }

        if (is_string($input['media_id'] ?? null) && $input['media_id'] !== '') {
            return [$input['media_id'], null];
        }

        throw ValidationException::withMessages(['file' => __('Choose a file to send.')]);
    }

    private function storeMessage(string $reason): string
    {
        return match ($reason) {
            'unsupported_media_type' => __('That file type cannot be sent on WhatsApp.'),
            'content_mismatch' => __('The file does not match the chosen type.'),
            'too_large' => __('That file is too large for WhatsApp.'),
            'empty_file' => __('The file is empty.'),
            'module_unavailable', 'provider_not_configured' => __('WhatsApp is not connected for this workspace yet.'),
            default => __('That file could not be stored.'),
        };
    }
}
