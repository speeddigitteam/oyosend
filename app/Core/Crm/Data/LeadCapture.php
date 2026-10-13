<?php

declare(strict_types=1);

namespace App\Core\Crm\Data;

use App\Core\Communication\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One enquiry from outside the CRM (a WhatsApp conversation, a Facebook lead form, a web form) as plain,
 * validated data. It carries no tenant, no person and no permission: the CRM captures it as the workspace.
 *
 * Everything in it is untrusted text from a third party; the CRM stores it as plain text and never evaluates it.
 * `consentEvidence` only says that the source collected consent: the CRM never writes platform consent.
 */
final readonly class LeadCapture
{
    public const int MAX_ANSWERS = 30;

    public const int MAX_ANSWER_KEY = 64;

    public const int MAX_ANSWER_VALUE = 1000;

    public string $source;

    public string $idempotencyKey;

    public ?string $name;

    public ?string $email;

    /** Canonical E.164 (`+8801712345678`). */
    public ?string $phone;

    public ?string $companyName;

    /** @var array<string, string> */
    public array $answers;

    public ?string $campaignRef;

    public ?string $adRef;

    public bool $consentEvidence;

    public CarbonImmutable $occurredAt;

    /**
     * @param  string  $source  stable key: `whatsapp`, `facebook_lead_ads`, `web_form`; lower-case letters, digits, `_`
     * @param  string  $idempotencyKey  identifies the external event (message id, lead-gen id): 1 to 160 characters
     * @param  string|null  $phone  any spelling {@see PhoneNumber::normalize()} understands
     * @param  array<string, scalar|null>  $answers  form answers; keys cut to 64 characters, values to 1000
     *
     * @throws InvalidArgumentException when the key, source or contact data is unusable
     */
    public function __construct(
        string $source,
        string $idempotencyKey,
        ?string $name = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $companyName = null,
        array $answers = [],
        ?string $campaignRef = null,
        ?string $adRef = null,
        bool $consentEvidence = false,
        ?CarbonImmutable $occurredAt = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,39}$/', $source) !== 1) {
            throw new InvalidArgumentException('A lead source key is 2 to 40 characters: lower-case letters, digits and underscores.');
        }

        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            throw new InvalidArgumentException('An idempotency key of 1 to 160 characters is required.');
        }

        $email = $email === null ? null : mb_strtolower(trim($email));
        $email = $email === '' ? null : $email;

        if ($email !== null && (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('The email address is not valid.');
        }

        $normalizedPhone = null;

        if ($phone !== null && trim($phone) !== '') {
            $normalizedPhone = PhoneNumber::normalize($phone);

            if ($normalizedPhone === null) {
                throw new InvalidArgumentException('The phone number is not valid.');
            }
        }

        if ($email === null && $normalizedPhone === null) {
            throw new InvalidArgumentException('A lead capture needs an email address or a phone number.');
        }

        $this->source = $source;
        $this->idempotencyKey = $idempotencyKey;
        $this->name = self::text($name, 200);
        $this->email = $email;
        $this->phone = $normalizedPhone;
        $this->companyName = self::text($companyName, 150);
        $this->answers = self::answers($answers);
        $this->campaignRef = self::text($campaignRef, 120);
        $this->adRef = self::text($adRef, 120);
        $this->consentEvidence = $consentEvidence;
        $this->occurredAt = $occurredAt ?? CarbonImmutable::now();
    }

    private static function text(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        // Plain text only: control characters and markup-looking tags are dropped, never interpreted.
        $value = trim(strip_tags(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @param  array<string, scalar|null>  $answers
     * @return array<string, string>
     */
    private static function answers(array $answers): array
    {
        $clean = [];

        foreach ($answers as $key => $value) {
            if (count($clean) >= self::MAX_ANSWERS) {
                break;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $label = self::text((string) $key, self::MAX_ANSWER_KEY);
            $text = self::text(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value, self::MAX_ANSWER_VALUE);

            if ($label !== null && $text !== null) {
                $clean[$label] = $text;
            }
        }

        return $clean;
    }
}
