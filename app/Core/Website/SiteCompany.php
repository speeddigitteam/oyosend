<?php

declare(strict_types=1);

namespace App\Core\Website;

use App\Core\Settings\PlatformSettings;

/**
 * The company behind the platform, as the website shows it: what an operator set in the Super Admin panel
 * (Website > Company), or else the defaults in config/site.php. The footer, the contact page, the structured
 * data, llms.txt and the contact form all read it from here.
 */
final readonly class SiteCompany
{
    /** Networks the website can link to, in the order the footer shows them. */
    public const array SOCIAL = ['facebook', 'linkedin', 'youtube', 'instagram', 'x'];

    public function __construct(private PlatformSettings $settings) {}

    public function name(): string
    {
        return $this->text('website.name') ?? 'Oyosend';
    }

    /** The registered name, when the operator gave one. */
    public function legalName(): ?string
    {
        return $this->text('website.legal_name');
    }

    /** The year the company was founded, when the operator gave it. */
    public function founded(): ?string
    {
        $year = $this->text('website.founded');

        return $year !== null && preg_match('/^\d{4}$/', $year) === 1 ? $year : null;
    }

    public function email(): string
    {
        return (string) $this->text('website.email');
    }

    /**
     * @return list<array{display: string, e164: string}> as written, and in the international form for `tel:` links
     */
    public function phones(): array
    {
        $phones = [];

        foreach (['website.phone_1', 'website.phone_2'] as $key) {
            $display = $this->text($key);

            if ($display !== null) {
                $phones[] = ['display' => $display, 'e164' => '+'.preg_replace('/\D+/', '', $display)];
            }
        }

        return $phones;
    }

    /**
     * @return array{street: string, locality: string, city: string, postal_code: string, country: string}
     */
    public function address(): array
    {
        return [
            'street' => (string) $this->text('website.street'),
            'locality' => (string) $this->text('website.locality'),
            'city' => (string) $this->text('website.city'),
            'postal_code' => (string) $this->text('website.postal_code'),
            'country' => 'BD',
        ];
    }

    /**
     * The address as the page in this language writes it, one line each.
     *
     * @return list<string>
     */
    public function addressLines(?string $locale = null): array
    {
        if (($locale ?? app()->getLocale()) === 'bn' && ($bengali = $this->text('website.address_bn')) !== null) {
            return array_values(array_filter(array_map('trim', preg_split('/\R/', $bengali) ?: [])));
        }

        $address = $this->address();
        $city = trim($address['city'].($address['postal_code'] !== '' ? '-'.$address['postal_code'] : ''), '-');

        return array_values(array_filter([$address['street'], trim($address['locality'].', '.$city, ', '), 'Bangladesh']));
    }

    public function mapUrl(): ?string
    {
        return $this->text('website.map_url');
    }

    public function contactRecipient(): string
    {
        return $this->text('website.contact_recipient') ?? $this->email();
    }

    /**
     * @return array<string, string> network => address, only those set
     */
    public function social(): array
    {
        $links = [];

        foreach (self::SOCIAL as $network) {
            if (($url = $this->text("website.social.{$network}")) !== null) {
                $links[$network] = $url;
            }
        }

        return $links;
    }

    private function text(string $key): ?string
    {
        $value = $this->settings->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
