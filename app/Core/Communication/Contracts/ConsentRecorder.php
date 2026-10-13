<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\MarketingChannel;

/**
 * Records marketing consent decisions a contact made themselves, right now: withdrawing it (replied STOP,
 * pressed a button) or giving it by confirming a double opt-in. These are the only consent writes other modules
 * may make.
 *
 * The communication app records an opt-out and suppresses the contact's address for marketing in one
 * transaction, so the very next send is blocked. Repeating it changes nothing. Without the communication app
 * (or when the workspace is not entitled) it records nothing and returns false: the caller must say so
 * honestly rather than claim the contact is unsubscribed (or subscribed).
 */
interface ConsentRecorder
{
    /**
     * @param  string  $method  How it was given: `keyword` (replied STOP), `button`.
     * @param  string|null  $evidenceNote  A short, non-personal note (at most 500 characters), e.g. "WhatsApp STOP".
     * @param  string|null  $address  The number or address the person actually wrote from, when it may differ from
     *                                the contact's saved one: it is suppressed for marketing too, so the opt-out
     *                                holds for the address that asked.
     * @return bool Whether the opt-out is now in force.
     */
    public function recordOptOut(string $contactId, MarketingChannel $channel, string $method, ?string $evidenceNote = null, ?string $address = null): bool;

    /**
     * Record that the contact explicitly opted in (a confirmed double opt-in on a form). The platform never lets
     * this override a decision it holds against the address: with any suppression of the contact's address for
     * marketing (an unsubscribe, a bounce, a complaint, a manual block) or an opt-out recorded as a complaint,
     * nothing changes and false comes back. Only a contact's own plain `opted_out` record with no suppression
     * behind it is replaced. Repeating it for a contact already opted in changes nothing and returns true.
     *
     * @param  string  $method  `form` or `double_opt_in` (both are kept as the platform's `form` method; the
     *                          evidence says it was a double opt-in).
     * @param  string  $evidence  What the contact did, non-personal, 1 to 500 characters. No IP address.
     * @return bool Whether the opt-in is now in force; false when refused or when the communication app is
     *              unavailable for the workspace.
     *
     * @throws \InvalidArgumentException For an unknown method, or empty or too long evidence.
     */
    public function recordOptIn(string $contactId, MarketingChannel $channel, string $method, string $evidence): bool;
}
