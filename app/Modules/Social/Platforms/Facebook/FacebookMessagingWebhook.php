<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Messages\Data\MessagingEvent;
use Carbon\CarbonImmutable;

/**
 * Parses Messenger (`object=page`) and Instagram (`object=instagram`) messaging webhooks. Called only after the
 * signature over the raw bytes was verified (see the comments `WebhookReceiver`).
 *
 * Documented payload: `{"object":"page|instagram","entry":[{"id":"<page or IG account>","time":..,"messaging":[{
 * "sender":{"id":".."},"recipient":{"id":".."},"timestamp":..,"message":{"mid":"..","text":"..","attachments":[{"type":
 * "image|video|audio|file|.."}],"is_echo":true,"is_deleted":true}}|"read":{"watermark":..|"mid":".."}|"delivery":{
 * "mids":[..],"watermark":..}}]}]}`.
 * https://developers.facebook.com/docs/messenger-platform/webhooks
 * https://developers.facebook.com/docs/messenger-platform/instagram/features/webhook/
 *
 * Postbacks, reactions, referrals and every other event are ignored. Attachments are described by their type; their
 * URLs are never read.
 */
final class FacebookMessagingWebhook
{
    private const array ATTACHMENTS = ['image', 'video', 'audio', 'file'];

    private const string ID = '/^[A-Za-z0-9_\-]{1,100}$/';

    private const string MID = '/^[A-Za-z0-9_\-.:=+\/]{1,191}$/';

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $platforms  the platforms to read (`messenger`, `instagram`)
     * @return list<MessagingEvent>
     */
    public static function events(array $payload, array $platforms, int $max = 200): array
    {
        $platform = match ($payload['object'] ?? null) {
            'page' => 'messenger',
            'instagram' => 'instagram',
            default => null,
        };

        if ($platform === null || ! in_array($platform, $platforms, true) || ! is_array($payload['entry'] ?? null)) {
            return [];
        }

        $events = [];

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry) || ! is_array($entry['messaging'] ?? null)) {
                continue;
            }

            $entryId = self::id($entry['id'] ?? null);

            if ($entryId === null) {
                continue;
            }

            foreach ($entry['messaging'] as $item) {
                $event = is_array($item) ? self::event($platform, $entryId, $item) : null;

                if ($event !== null) {
                    $events[] = $event;
                }

                if (count($events) >= $max) {
                    return $events;
                }
            }
        }

        return $events;
    }

    /** @param array<mixed> $item */
    private static function event(string $platform, string $entryId, array $item): ?MessagingEvent
    {
        $sender = self::id(is_array($item['sender'] ?? null) ? ($item['sender']['id'] ?? null) : null);
        $recipient = self::id(is_array($item['recipient'] ?? null) ? ($item['recipient']['id'] ?? null) : null);
        $at = self::time($item['timestamp'] ?? null) ?? CarbonImmutable::now();

        if ($sender === null || $recipient === null) {
            return null;
        }

        if (is_array($item['message'] ?? null)) {
            return self::message($platform, $entryId, $sender, $recipient, $at, $item['message']);
        }

        // Read and delivery receipts come from the person: the recipient is the Page or the Instagram account.
        if ($recipient !== $entryId || $sender === $entryId) {
            return null;
        }

        if (is_array($item['read'] ?? null)) {
            $read = $item['read'];
            $mid = self::mid($read['mid'] ?? null);
            $watermark = self::time($read['watermark'] ?? null);

            if ($mid === null && $watermark === null) {
                return null;
            }

            return new MessagingEvent($platform, $entryId, MessagingEvent::READ, $sender, $at,
                watermark: $watermark, mids: $mid === null ? [] : [$mid], key: self::key($platform, $entryId, 'read', $sender, $mid ?? (string) $watermark->getTimestampMs()));
        }

        if (is_array($item['delivery'] ?? null)) {
            $delivery = $item['delivery'];
            $mids = array_values(array_filter(array_map(self::mid(...), array_slice((array) ($delivery['mids'] ?? []), 0, 50))));
            $watermark = self::time($delivery['watermark'] ?? null);

            if ($mids === [] && $watermark === null) {
                return null;
            }

            return new MessagingEvent($platform, $entryId, MessagingEvent::DELIVERY, $sender, $at,
                watermark: $watermark, mids: $mids, key: self::key($platform, $entryId, 'delivery', $sender, implode(',', $mids).(string) $watermark?->getTimestampMs()));
        }

        return null;
    }

    /** @param array<mixed> $message */
    private static function message(string $platform, string $entryId, string $sender, string $recipient, CarbonImmutable $at, array $message): ?MessagingEvent
    {
        $mid = self::mid($message['mid'] ?? null);

        if ($mid === null) {
            return null;
        }

        $echo = ($message['is_echo'] ?? false) === true;

        // An echo is the Page (or Instagram account) writing to the person; anything else is the person writing to it.
        [$from, $to] = $echo ? [$sender, $recipient] : [$recipient, $sender];

        if ($from !== $entryId || $to === $entryId) {
            return null;
        }

        if (($message['is_deleted'] ?? false) === true) {
            return new MessagingEvent($platform, $entryId, MessagingEvent::DELETED, $to, $at, mid: $mid, key: self::key($platform, $entryId, 'deleted', $to, $mid));
        }

        $text = is_string($message['text'] ?? null) ? mb_substr($message['text'], 0, 20000) : null;
        $type = null;

        foreach (is_array($message['attachments'] ?? null) ? $message['attachments'] : [] as $attachment) {
            if (is_array($attachment)) {
                $type = in_array($attachment['type'] ?? null, self::ATTACHMENTS, true) ? (string) $attachment['type'] : 'other';

                break;
            }
        }

        if (($message['is_unsupported'] ?? false) === true) {
            $type ??= 'other';
        }

        if (($text === null || trim($text) === '') && $type === null) {
            // A sticker, a reaction or something else without text or attachment: still a message, shown as "other".
            $type = 'other';
        }

        return new MessagingEvent(
            $platform,
            $entryId,
            $echo ? MessagingEvent::ECHO : MessagingEvent::MESSAGE,
            $to,
            $at,
            mid: $mid,
            text: $text,
            attachmentType: $type,
            key: self::key($platform, $entryId, $echo ? 'echo' : 'message', $to, $mid),
        );
    }

    private static function key(string $platform, string $entryId, string $kind, string $participant, string $value): string
    {
        return sha1($platform.'|'.$entryId.'|'.$kind.'|'.$participant.'|'.$value);
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $milliseconds = (int) $value;

        // Meta sends milliseconds; a seconds value would be a date in 1970.
        if ($milliseconds < 100000000000) {
            $milliseconds *= 1000;
        }

        return CarbonImmutable::createFromTimestampMsUTC($milliseconds);
    }

    private static function id(mixed $value): ?string
    {
        return (is_string($value) || is_int($value)) && preg_match(self::ID, (string) $value) === 1 ? (string) $value : null;
    }

    private static function mid(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::MID, $value) === 1 ? $value : null;
    }
}
