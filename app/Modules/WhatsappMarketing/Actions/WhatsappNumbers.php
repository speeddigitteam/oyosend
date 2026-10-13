<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Communication\Contracts\ChannelNumberDirectory;
use App\Core\Communication\Data\ChannelNumber;
use App\Core\Communication\Data\MarketingChannel;

/**
 * A thin read view over the communication platform's {@see ChannelNumberDirectory} (ADR 0022), shaped for this
 * app's pages. The connection, the claim and the provider account all live in the communication app; this app
 * only ever holds a number's platform id ({@see ChannelNumber::$id}) and passes it as
 * `MarketingMessage::$senderNumberId`.
 *
 * Conversations stay one-per-customer-phone; a number only decides which business line a message goes out from.
 */
final class WhatsappNumbers
{
    public function __construct(private readonly ChannelNumberDirectory $directory) {}

    /**
     * Every WhatsApp number of the workspace, default first, as the number picker and the settings page use them.
     *
     * @return list<array{id: string, label: string, displayNumber: string|null, accountId: string, isDefault: bool, isActive: bool}>
     */
    public function all(): array
    {
        return array_map(
            fn (ChannelNumber $number): array => $this->present($number),
            $this->directory->numbers(MarketingChannel::Whatsapp),
        );
    }

    /**
     * The numbers a message may be sent from (active only), and which one is pre-selected (the default), for the
     * composer and the campaign form. Empty when the connection has no numbers (a single-number legacy
     * connection always reports exactly one).
     *
     * @return array{options: list<array{id: string, label: string, displayNumber: string|null, accountId: string, isDefault: bool, isActive: bool}>, defaultId: string|null}
     */
    public function picker(): array
    {
        $active = array_values(array_filter($this->all(), static fn (array $number): bool => $number['isActive']));
        $default = $this->directory->default(MarketingChannel::Whatsapp);

        return ['options' => $active, 'defaultId' => $default?->id];
    }

    /** @return array{id: string, label: string, displayNumber: string|null, accountId: string, isDefault: bool, isActive: bool} */
    private function present(ChannelNumber $number): array
    {
        return [
            'id' => $number->id,
            'label' => $number->label,
            'displayNumber' => $number->displayNumber,
            'accountId' => $number->accountId,
            'isDefault' => $number->isDefault,
            'isActive' => $number->isActive,
        ];
    }
}
