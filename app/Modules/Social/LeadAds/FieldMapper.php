<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

use App\Core\Communication\Support\PhoneNumber;

/**
 * Turns a lead form's answers into the fields a CRM lead capture understands, following the person's mapping
 * (or the automatic one). Pure: no database, no platform call. Everything it returns is plain, bounded text.
 *
 * Targets: `name`, `email`, `phone`, `company`, `consent` (an explicit yes/no question: consent evidence is
 * reported only when this question was answered affirmatively), `answer` (kept under an answer key) and `ignore`.
 */
final class FieldMapper
{
    public const string NAME = 'name';

    public const string EMAIL = 'email';

    public const string PHONE = 'phone';

    public const string COMPANY = 'company';

    public const string CONSENT = 'consent';

    public const string ANSWER = 'answer';

    public const string IGNORE = 'ignore';

    /** @var list<string> */
    public const array TARGETS = [self::NAME, self::EMAIL, self::PHONE, self::COMPANY, self::CONSENT, self::ANSWER, self::IGNORE];

    /** Answers a lead may carry to the CRM; one slot is kept for the form name. */
    public const int MAX_ANSWERS = 29;

    private const array BY_KEY = [
        'email' => self::EMAIL,
        'work_email' => self::EMAIL,
        'phone_number' => self::PHONE,
        'work_phone_number' => self::PHONE,
        'phone' => self::PHONE,
        'full_name' => self::NAME,
        'name' => self::NAME,
        'first_name' => self::NAME,
        'last_name' => self::NAME,
        'company_name' => self::COMPANY,
        'company' => self::COMPANY,
    ];

    private const array BY_TYPE = [
        'EMAIL' => self::EMAIL,
        'PHONE' => self::PHONE,
        'FULL_NAME' => self::NAME,
        'FIRST_NAME' => self::NAME,
        'LAST_NAME' => self::NAME,
        'COMPANY_NAME' => self::COMPANY,
    ];

    private const array AFFIRMATIVE = ['yes', 'y', 'true', '1', 'agree', 'agreed', 'i agree', 'accept', 'accepted', 'i accept', 'ok', 'opt in', 'হ্যাঁ', 'হ্যা', 'হাঁ'];

    /**
     * The automatic mapping for a form's questions.
     *
     * @param  list<array{key: string, label: string, type: string}>  $questions
     * @return array<string, array{target: string, answer_key: string|null}>
     */
    public function auto(array $questions): array
    {
        $mapping = [];

        foreach ($questions as $question) {
            $mapping[$question['key']] = ['target' => $this->autoTarget($question['key'], $question['type']), 'answer_key' => null];
        }

        return $mapping;
    }

    /**
     * The mapping in force: the person's choices over the automatic mapping, for the questions as they are now.
     *
     * @param  list<array{key: string, label: string, type: string}>  $questions
     * @param  array<string, array{target?: string, answer_key?: string|null}>|null  $saved
     * @return array<string, array{target: string, answer_key: string|null}>
     */
    public function resolve(array $questions, ?array $saved): array
    {
        $mapping = $this->auto($questions);

        foreach ($saved ?? [] as $key => $choice) {
            $target = $choice['target'] ?? null;

            if (isset($mapping[$key]) && is_string($target) && in_array($target, self::TARGETS, true)) {
                $answerKey = $choice['answer_key'] ?? null;
                $mapping[$key] = ['target' => $target, 'answer_key' => is_string($answerKey) && trim($answerKey) !== '' ? trim($answerKey) : null];
            }
        }

        return $mapping;
    }

    /**
     * What the mapping would do, as the person should be warned about it. Codes only.
     *
     * @param  array<string, array{target: string, answer_key: string|null}>  $mapping
     * @return list<string>
     */
    public function warnings(array $mapping): array
    {
        $targets = array_column($mapping, 'target');
        $warnings = [];

        if (! in_array(self::EMAIL, $targets, true) && ! in_array(self::PHONE, $targets, true)) {
            $warnings[] = 'no_contact_field';
        }

        if (! in_array(self::EMAIL, $targets, true) && in_array(self::PHONE, $targets, true)) {
            $warnings[] = 'phone_only';
        }

        if (count(array_filter($targets, static fn (string $target): bool => $target === self::CONSENT)) > 1) {
            $warnings[] = 'several_consent';
        }

        return $warnings;
    }

    /**
     * @param  list<array{name?: mixed, values?: mixed}>  $fieldData  the platform's `field_data`
     * @param  array<string, array{target: string, answer_key: string|null}>  $mapping
     */
    public function map(array $fieldData, array $mapping): MappedLead
    {
        $names = [];
        $emails = [];
        $phones = [];
        $companies = [];
        $answers = [];
        $consent = false;
        $consentAsked = false;

        foreach ($fieldData as $entry) {
            if (! is_string($entry['name'] ?? null)) {
                continue;
            }

            $key = $entry['name'];
            $value = $this->value($entry['values'] ?? null);

            if ($value === null) {
                continue;
            }

            $target = $mapping[$key]['target'] ?? self::BY_KEY[mb_strtolower($key)] ?? self::ANSWER;

            switch ($target) {
                case self::NAME:
                    $names[] = [$key, $value];
                    break;
                case self::EMAIL:
                    $emails[] = $value;
                    break;
                case self::PHONE:
                    $phones[] = $value;
                    break;
                case self::COMPANY:
                    $companies[] = $value;
                    break;
                case self::CONSENT:
                    $consentAsked = true;
                    $consent = $consent || $this->affirmative($value);
                    break;
                case self::ANSWER:
                    $label = $mapping[$key]['answer_key'] ?? null;
                    $label = is_string($label) && $label !== '' ? $label : $key;
                    $label = mb_substr(trim($label), 0, 64);

                    if ($label !== '' && count($answers) < self::MAX_ANSWERS) {
                        $answers[$this->uniqueKey($answers, $label)] = mb_substr($value, 0, 1000);
                    }

                    break;
            }
        }

        $email = null;

        foreach ($emails as $candidate) {
            $candidate = mb_strtolower(trim($candidate));

            if (strlen($candidate) <= 190 && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $email = $candidate;
                break;
            }
        }

        $phone = null;

        foreach ($phones as $candidate) {
            // Lead exports prefix numbers with `p:`.
            $candidate = preg_replace('/^\s*p:\s*/i', '', $candidate) ?? $candidate;
            $normalized = PhoneNumber::normalize($candidate);

            if ($normalized !== null) {
                $phone = $normalized;
                break;
            }
        }

        return new MappedLead(
            name: $this->name($names),
            email: $email,
            phone: $phone,
            company: $companies[0] ?? null,
            answers: $answers,
            consentEvidence: $consentAsked && $consent,
            emailRejected: $emails !== [] && $email === null,
            phoneRejected: $phones !== [] && $phone === null,
        );
    }

    /**
     * Example answers for a form's questions, for the mapping preview. Never real people's data.
     *
     * @param  list<array{key: string, label: string, type: string}>  $questions
     * @param  array<string, array{target: string, answer_key: string|null}>  $mapping
     * @return list<array{name: string, values: list<string>}>
     */
    public function sample(array $questions, array $mapping): array
    {
        $data = [];

        foreach ($questions as $question) {
            $key = $question['key'];
            $target = $mapping[$key]['target'] ?? $this->autoTarget($key, $question['type']);
            $lower = mb_strtolower($key);

            $value = match (true) {
                $target === self::EMAIL => 'sample@example.com',
                $target === self::PHONE => '+8801712345678',
                $target === self::CONSENT => 'yes',
                $lower === 'first_name' => 'Sample',
                $lower === 'last_name' => 'Person',
                $target === self::NAME => 'Sample Person',
                $target === self::COMPANY => 'Example Ltd',
                default => 'Sample answer',
            };

            $data[] = ['name' => $key, 'values' => [$value]];
        }

        return $data;
    }

    private function autoTarget(string $key, string $type): string
    {
        return self::BY_KEY[mb_strtolower($key)] ?? self::BY_TYPE[strtoupper($type)] ?? self::ANSWER;
    }

    private function value(mixed $values): ?string
    {
        $parts = [];

        foreach (is_array($values) ? $values : [$values] as $value) {
            if (is_scalar($value)) {
                $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));

                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return $parts === [] ? null : mb_substr(implode(', ', $parts), 0, 1000);
    }

    private function affirmative(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), self::AFFIRMATIVE, true);
    }

    /**
     * @param  array<string, string>  $answers
     */
    private function uniqueKey(array $answers, string $label): string
    {
        if (! isset($answers[$label])) {
            return $label;
        }

        for ($i = 2; $i < 40; $i++) {
            $candidate = mb_substr($label, 0, 60).'_'.$i;

            if (! isset($answers[$candidate])) {
                return $candidate;
            }
        }

        return mb_substr($label, 0, 60).'_x';
    }

    /**
     * @param  list<array{0: string, 1: string}>  $parts  [question key, value]
     */
    private function name(array $parts): ?string
    {
        if ($parts === []) {
            return null;
        }

        foreach ($parts as [$key, $value]) {
            if (! in_array(mb_strtolower($key), ['first_name', 'last_name'], true)) {
                return $value;
            }
        }

        $ordered = [];

        foreach (['first_name', 'last_name'] as $want) {
            foreach ($parts as [$key, $value]) {
                if (mb_strtolower($key) === $want) {
                    $ordered[] = $value;
                }
            }
        }

        return implode(' ', $ordered);
    }
}
