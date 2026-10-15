<?php

declare(strict_types=1);

namespace App\Core\Website;

use App\Core\Billing\Support\Money;
use NumberFormatter;

/**
 * What an app costs, from its own App Store offers, and how money is written on the website: "৳1,990" in English,
 * "৳১,৯৯০" in Bengali. Whole amounts drop their paisa; a fractional one keeps them.
 *
 * The amount is a real offer's: the cheapest monthly one, or the cheapest yearly one when there is no monthly offer.
 * A yearly price is never divided into a monthly one nobody sells.
 */
final readonly class SitePrice
{
    public function __construct(
        public bool $free,
        public ?int $amount = null,
        public ?string $currency = null,
        public string $period = 'month',
        public int $trialDays = 0,
    ) {}

    public function isPaid(): bool
    {
        return $this->amount !== null && $this->amount > 0 && $this->currency !== null;
    }

    /**
     * For a card: "From ৳1,990 a month", "From ৳19,900 a year" or "Free to add".
     */
    public function short(): string
    {
        if (! $this->isPaid()) {
            return __('site.common.app_free');
        }

        return __("site.common.app_paid_{$this->period}", ['price' => self::format((int) $this->amount, (string) $this->currency)]);
    }

    /**
     * For the price list, with the trial: "৳1,990 a month, 14-day free trial".
     */
    public function long(): string
    {
        if (! $this->isPaid()) {
            return __('site.pricing.included');
        }

        $price = self::format((int) $this->amount, (string) $this->currency);

        return $this->trialDays > 0
            ? __("site.pricing.paid_{$this->period}_trial", ['price' => $price, 'count' => self::number($this->trialDays)])
            : __("site.pricing.paid_{$this->period}", ['price' => $price]);
    }

    public static function format(int $minorUnits, string $currency, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if (! class_exists(NumberFormatter::class)) {
            return Money::format($minorUnits, $currency, $locale);
        }

        $scale = 10 ** Money::decimals($currency);
        $whole = $minorUnits % $scale === 0;

        $digits = $whole ? 0 : Money::decimals($currency);
        $bdt = strtoupper($currency) === 'BDT';

        // Taka leads the amount in both languages, the way prices are written in Bangladesh.
        $formatter = new NumberFormatter($locale === 'bn' ? 'bn_BD' : 'en_BD', $bdt ? NumberFormatter::DECIMAL : NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);

        $formatted = $bdt
            ? $formatter->format($minorUnits / $scale)
            : $formatter->formatCurrency($minorUnits / $scale, strtoupper($currency));

        if ($bdt && $formatted !== false) {
            $formatted = '৳'.$formatted;
        }

        return $formatted === false ? Money::format($minorUnits, $currency, $locale) : $formatted;
    }

    /**
     * Text with its digits written in the page's language: a phone number reads "+৮৮ ০১৭৯ ..." on a Bengali page.
     */
    public static function digits(string $text, ?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'bn' ? strtr($text, array_combine(range(0, 9), ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'])) : $text;
    }

    /**
     * A plain number in the page's language: "50" or "৫০".
     */
    public static function number(int $value, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if (! class_exists(NumberFormatter::class)) {
            return (string) $value;
        }

        $formatted = (new NumberFormatter($locale === 'bn' ? 'bn_BD' : 'en_BD', NumberFormatter::DECIMAL))->format($value);

        return $formatted === false ? (string) $value : $formatted;
    }
}
