<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\UnsubscribeLinks;
use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sends a campaign's email to workspace members for a proof read.
 *
 * Recipients are an allow-list: only members of this workspace, never an address typed in. The email goes through
 * the same platform gateway as a campaign, so the same rules apply: a member is a real recipient only when they
 * exist as a contact (matched by their sign-in email) and have marketing consent. Otherwise the result says why
 * nothing was sent; the in-app preview shows the same content without sending.
 */
final class TestSender
{
    public function __construct(
        private readonly ChannelGateway $gateway,
        private readonly UnsubscribeLinks $links,
        private readonly ContentComposer $composer,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<string>  $membershipIds
     * @return list<array{member: string, outcome: string, reason: string|null}>
     *
     * @throws ValidationException
     */
    public function send(Campaign $campaign, array $membershipIds): array
    {
        $ids = array_values(array_unique($membershipIds));
        $max = (int) config('email_marketing.test_send_max_recipients', 5);

        if ($ids === [] || count($ids) > $max) {
            throw ValidationException::withMessages(['recipients' => __('Choose between 1 and :count members.', ['count' => $max])]);
        }

        $members = TenantMembership::query()
            ->with('user')
            ->where('status', MembershipStatus::Active)
            ->whereIn('id', $ids)
            ->get();

        if ($members->count() !== count($ids)) {
            throw ValidationException::withMessages(['recipients' => __('Choose members of this workspace.')]);
        }

        $request = Str::lower(Str::random(12));
        $results = [];

        foreach ($members as $member) {
            $name = $member->user->name;
            $contact = Contact::query()->with('company')->whereRaw('lower(email) = ?', [Str::lower($member->user->email)])->first();

            if ($contact === null) {
                $results[] = ['member' => $name, 'outcome' => 'no_contact', 'reason' => null];

                continue;
            }

            $availability = $this->gateway->availability(MarketingChannel::Email);

            if (! $availability->isAvailable()) {
                $results[] = ['member' => $name, 'outcome' => 'unavailable', 'reason' => 'channel_unavailable'];

                continue;
            }

            $unsubscribe = $this->links->issue($contact->id, MarketingChannel::Email, DeliverySender::MODULE);

            if ($unsubscribe === null) {
                $results[] = ['member' => $name, 'outcome' => 'unavailable', 'reason' => 'no_unsubscribe_link'];

                continue;
            }

            $email = $this->composer->forContact($campaign->blocks, $campaign->subject, $campaign->preheader, $contact, $unsubscribe);

            $receipt = $this->gateway->send(new MarketingMessage(
                module: DeliverySender::MODULE,
                idempotencyKey: 'test:'.$campaign->id.':'.$request.':'.$contact->id,
                channel: MarketingChannel::Email,
                contactId: $contact->id,
                subject: ContentBlocks::line('['.__('Test').'] '.$email->subject),
                body: $email->text,
                campaignId: $campaign->id,
                locale: $this->context->require()->locale,
            ));

            $results[] = match ($receipt->state) {
                DeliveryState::Blocked => ['member' => $name, 'outcome' => 'blocked', 'reason' => $receipt->reason],
                DeliveryState::Unavailable, DeliveryState::Failed => ['member' => $name, 'outcome' => 'unavailable', 'reason' => $receipt->reason],
                default => ['member' => $name, 'outcome' => 'sent', 'reason' => null],
            };
        }

        return $results;
    }
}
