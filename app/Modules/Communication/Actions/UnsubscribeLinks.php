<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\UnsubscribeToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-click unsubscribe links for marketing messages.
 *
 * The token is 40 random characters that exist only inside the outgoing message. The database keeps its
 * SHA-256 hash, so a leaked table cannot be used to unsubscribe (or confirm the existence of) anybody.
 */
final class UnsubscribeLinks
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantHost $hosts,
        private readonly ConsentManager $consents,
        private readonly SuppressionManager $suppressions,
    ) {}

    /** Issue a link for the message's recipient. The token is returned inside the URL only. */
    public function issue(Message $message): string
    {
        $token = Str::random(40);

        UnsubscribeToken::query()->create([
            'contact_id' => $message->contact_id,
            'message_id' => $message->id,
            'channel' => $message->channel,
            'address' => $message->recipient,
            'token_hash' => $this->hash($token),
            'expires_at' => CarbonImmutable::now()->addDays((int) config('communication.unsubscribe_token_days', 365)),
        ]);

        return $this->hosts->url($this->context->require(), 'communication/unsubscribe/'.$token);
    }

    /** The live (unused, unexpired) token row for a presented token, or null. */
    public function find(string $token): ?UnsubscribeToken
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }

        return UnsubscribeToken::query()
            ->where('token_hash', $this->hash($token))
            ->where('expires_at', '>', CarbonImmutable::now())
            ->first();
    }

    /**
     * Withdraw marketing consent for the token's address. Repeating it changes nothing.
     */
    public function redeem(UnsubscribeToken $token): void
    {
        DB::transaction(function () use ($token): void {
            $this->suppressions->suppress(
                $token->channel,
                $token->address,
                SuppressionScope::Marketing,
                SuppressionReason::Unsubscribed,
                $token->contact_id,
            );

            $contact = $token->contact_id === null ? null : Contact::query()->find($token->contact_id);

            if ($contact !== null) {
                $this->consents->record($contact, $token->channel, ConsentStatus::OptedOut, 'unsubscribe_link');
            }

            $token->forceFill(['used_at' => $token->used_at ?? CarbonImmutable::now()])->save();
        });
    }

    public function channelOf(UnsubscribeToken $token): Channel
    {
        return $token->channel;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
