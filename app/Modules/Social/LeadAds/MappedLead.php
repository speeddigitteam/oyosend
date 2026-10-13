<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

/** The result of applying a form mapping to one lead's answers. */
final readonly class MappedLead
{
    /**
     * @param  array<string, string>  $answers
     */
    public function __construct(
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $company,
        public array $answers,
        public bool $consentEvidence,
        public bool $emailRejected,
        public bool $phoneRejected,
    ) {}

    /** Whether the CRM can capture it: an email address or a phone number is required. */
    public function hasContact(): bool
    {
        return $this->email !== null || $this->phone !== null;
    }

    /** `missing_contact` (nothing mapped or answered) or `invalid_contact` (answered, but not usable). */
    public function problem(): ?string
    {
        if ($this->hasContact()) {
            return null;
        }

        return $this->emailRejected || $this->phoneRejected ? 'invalid_contact' : 'missing_contact';
    }

    /** @return array<string, mixed> What the mapping preview shows. */
    public function toPreview(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'answers' => $this->answers,
            'consentEvidence' => $this->consentEvidence,
            'problem' => $this->problem(),
            'ignored' => array_values(array_filter([$this->emailRejected && $this->email === null ? 'email' : null, $this->phoneRejected && $this->phone === null ? 'phone' : null])),
        ];
    }
}
