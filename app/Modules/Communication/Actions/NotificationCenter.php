<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Contracts\Notifier;
use App\Core\Communication\Data\NotificationMessage;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\NotificationChannel;
use App\Modules\Communication\Models\MemberNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The notification center and the implementation of the core {@see Notifier} contract.
 *
 * Applies, on the server, the workspace's entitlement and each member's preferences. In-app notifications are
 * rows; email goes through the outbox like any other message (so it is logged, retried and rate limited).
 */
final class NotificationCenter implements Notifier
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly NotificationPreferences $preferences,
        private readonly Outbox $outbox,
        private readonly TenantContext $context,
    ) {}

    public function notify(array $membershipIds, NotificationMessage $message): int
    {
        if (! $this->access->entitled() || $membershipIds === []) {
            return 0;
        }

        if ($message->linkRoute !== null && ! $this->isWorkspaceRoute($message->linkRoute)) {
            // A link to a page that does not exist (or is not a workspace page) is dropped, not trusted.
            $message = new NotificationMessage(
                $message->category, $message->titleKey, $message->params, $message->bodyKey, null, [],
                $message->severity, $message->dedupeKey, $message->sourceModule,
            );
        }

        $members = TenantMembership::query()
            ->with('user:id,email,locale')
            ->whereIn('id', array_values(array_unique($membershipIds)))
            ->where('status', MembershipStatus::Active)
            ->get();

        $saved = $this->preferences->saved($members->pluck('id')->all());
        $reached = 0;

        foreach ($members as $member) {
            $inApp = $message->severity === 'critical'
                || ($saved[$member->id][NotificationChannel::InApp->value][$message->category] ?? $this->preferences->default(NotificationChannel::InApp));
            $email = $saved[$member->id][NotificationChannel::Email->value][$message->category] ?? $this->preferences->default(NotificationChannel::Email);
            $key = $message->dedupeKey ?? (string) Str::ulid();
            $done = false;

            if ($inApp) {
                $done = $this->store($member, $message);
            }

            if ($email) {
                $done = $this->email($member, $message, $key) || $done;
            }

            $reached += $done ? 1 : 0;
        }

        return $reached;
    }

    private function store(TenantMembership $member, NotificationMessage $message): bool
    {
        if ($message->dedupeKey !== null && MemberNotification::query()
            ->where('membership_id', $member->id)->where('dedupe_key', $message->dedupeKey)->exists()) {
            return false;
        }

        try {
            MemberNotification::query()->create([
                'membership_id' => $member->id,
                'category' => $message->category,
                'severity' => $message->severity,
                'title_key' => $message->titleKey,
                'body_key' => $message->bodyKey,
                'params' => $message->params === [] ? null : $message->params,
                'link_route' => $message->linkRoute,
                'link_params' => $message->linkParams === [] ? null : $message->linkParams,
                'source_module' => $message->sourceModule,
                'dedupe_key' => $message->dedupeKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function email(TenantMembership $member, NotificationMessage $message, string $key): bool
    {
        $address = $member->user->email;
        $locale = $member->user->locale ?: $this->context->require()->locale;
        $title = (string) __($message->titleKey, $this->params($message), $locale);
        $body = $message->bodyKey === null ? $title : (string) __($message->bodyKey, $this->params($message), $locale);
        $link = $this->url($message);

        try {
            $this->outbox->queue(new OutboundRequest(
                channel: Channel::Email,
                purpose: MessagePurpose::Transactional,
                recipient: $address,
                subject: $title,
                body: $link === null ? $body : $body."\n\n".$link,
                idempotencyKey: 'notification:'.$member->id.':'.$key,
                membershipId: $member->id,
                locale: $locale,
                source: 'notification',
            ));
        } catch (ValidationException) {
            // An address that cannot be mailed must not stop the in-app notification of the others.
            return false;
        }

        return true;
    }

    /** @return array<string, scalar|null> */
    private function params(NotificationMessage $message): array
    {
        return $message->params;
    }

    private function url(NotificationMessage $message): ?string
    {
        if ($message->linkRoute === null) {
            return null;
        }

        try {
            return route($message->linkRoute, $message->linkParams);
        } catch (Throwable) {
            return null;
        }
    }

    private function isWorkspaceRoute(string $name): bool
    {
        return Route::has($name) && ! str_starts_with($name, 'central.') && ! str_starts_with($name, 'platform.');
    }
}
