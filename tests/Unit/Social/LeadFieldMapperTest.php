<?php

declare(strict_types=1);

use App\Modules\Social\LeadAds\FieldMapper;
use App\Modules\Social\LeadAds\MappedLead;

/** @param list<array{name: string, values: list<string>}> $data */
function mapLead(array $data, array $mapping = [], ?array $questions = null): MappedLead
{
    $mapper = new FieldMapper;
    $resolved = $mapper->resolve($questions ?? array_map(static fn (array $entry): array => ['key' => $entry['name'], 'label' => $entry['name'], 'type' => 'CUSTOM'], $data), $mapping);

    return $mapper->map($data, $resolved);
}

it('maps Meta default field keys automatically', function () {
    $mapper = new FieldMapper;
    $auto = $mapper->auto([
        ['key' => 'full_name', 'label' => 'Full name', 'type' => 'FULL_NAME'],
        ['key' => 'email', 'label' => 'Email', 'type' => 'EMAIL'],
        ['key' => 'phone_number', 'label' => 'Phone', 'type' => 'PHONE'],
        ['key' => 'company_name', 'label' => 'Company', 'type' => 'COMPANY_NAME'],
        ['key' => 'work_email', 'label' => 'Work email', 'type' => 'WORK_EMAIL'],
        ['key' => 'budget', 'label' => 'Budget', 'type' => 'CUSTOM'],
        ['key' => 'my_contact', 'label' => 'Reach me', 'type' => 'EMAIL'],
    ]);

    expect(array_map(static fn (array $choice): string => $choice['target'], $auto))->toBe([
        'full_name' => 'name', 'email' => 'email', 'phone_number' => 'phone', 'company_name' => 'company',
        'work_email' => 'email', 'budget' => 'answer', 'my_contact' => 'email',
    ]);
});

it('normalises every spelling of a Bangladeshi phone number', function (string $spelling) {
    $mapped = mapLead([['name' => 'email', 'values' => ['a@example.com']], ['name' => 'phone_number', 'values' => [$spelling]]]);

    expect($mapped->phone)->toBe('+8801712345678')->and($mapped->phoneRejected)->toBeFalse();
})->with(['01712345678', '1712345678', '+880 1712-345678', '8801712345678', 'p:+8801712345678', '০১৭১২৩৪৫৬৭৮', '(+880) 1712 345678']);

it('captures with only a phone, or only an email, and flags a lead with neither', function () {
    $phoneOnly = mapLead([['name' => 'phone_number', 'values' => ['01712345678']]]);
    expect($phoneOnly->hasContact())->toBeTrue()->and($phoneOnly->email)->toBeNull()->and($phoneOnly->problem())->toBeNull();

    $emailOnly = mapLead([['name' => 'email', 'values' => ['A@Example.com']]]);
    expect($emailOnly->email)->toBe('a@example.com')->and($emailOnly->problem())->toBeNull();

    $none = mapLead([['name' => 'full_name', 'values' => ['Nobody']]]);
    expect($none->problem())->toBe('missing_contact');

    $blank = mapLead([['name' => 'email', 'values' => ['   ']], ['name' => 'phone_number', 'values' => []]]);
    expect($blank->problem())->toBe('missing_contact');
});

it('drops an unusable email or phone when the other is usable, and fails when neither is', function () {
    $badPhone = mapLead([['name' => 'email', 'values' => ['ok@example.com']], ['name' => 'phone_number', 'values' => ['call me maybe']]]);
    expect($badPhone->email)->toBe('ok@example.com')->and($badPhone->phone)->toBeNull()->and($badPhone->phoneRejected)->toBeTrue()
        ->and($badPhone->problem())->toBeNull();

    $badEmail = mapLead([['name' => 'email', 'values' => ['not-an-email']], ['name' => 'phone_number', 'values' => ['01712345678']]]);
    expect($badEmail->email)->toBeNull()->and($badEmail->phone)->toBe('+8801712345678')->and($badEmail->emailRejected)->toBeTrue();

    $both = mapLead([['name' => 'email', 'values' => ['nope']], ['name' => 'phone_number', 'values' => ['123']]]);
    expect($both->problem())->toBe('invalid_contact');
});

it('uses the first valid candidate when several questions map to email or phone', function () {
    $mapped = mapLead([
        ['name' => 'email', 'values' => ['broken']],
        ['name' => 'work_email', 'values' => ['work@example.com']],
        ['name' => 'phone_number', 'values' => ['bad']],
        ['name' => 'work_phone_number', 'values' => ['+8801812345678']],
    ]);

    expect($mapped->email)->toBe('work@example.com')->and($mapped->phone)->toBe('+8801812345678');
});

it('builds a name from first and last name, or takes the full name', function () {
    $split = mapLead([['name' => 'last_name', 'values' => ['Uddin']], ['name' => 'first_name', 'values' => ['Rahim']], ['name' => 'email', 'values' => ['a@example.com']]]);
    expect($split->name)->toBe('Rahim Uddin');

    $full = mapLead([['name' => 'first_name', 'values' => ['Rahim']], ['name' => 'full_name', 'values' => ['Rahim Uddin']], ['name' => 'email', 'values' => ['a@example.com']]]);
    expect($full->name)->toBe('Rahim Uddin');
});

it('honours the person mapping: answer keys, ignored questions and re-targeted questions', function () {
    $mapped = mapLead(
        [
            ['name' => 'full_name', 'values' => ['Rahim']],
            ['name' => 'how_to_reach', 'values' => ['rahim@example.com']],
            ['name' => 'secret_note', 'values' => ['do not keep']],
            ['name' => 'budget', 'values' => ['10000', '20000']],
        ],
        [
            'how_to_reach' => ['target' => 'email'],
            'secret_note' => ['target' => 'ignore'],
            'budget' => ['target' => 'answer', 'answer_key' => 'Budget (BDT)'],
        ],
    );

    expect($mapped->email)->toBe('rahim@example.com')->and($mapped->answers)->toBe(['Budget (BDT)' => '10000, 20000']);
});

it('records consent evidence only for an explicitly mapped consent question answered affirmatively', function () {
    $data = fn (string $answer): array => [['name' => 'email', 'values' => ['a@example.com']], ['name' => 'ok_to_contact', 'values' => [$answer]]];

    // Not mapped as consent: the answer is just an answer, never evidence.
    expect(mapLead($data('Yes'))->consentEvidence)->toBeFalse();

    $consent = ['ok_to_contact' => ['target' => 'consent']];
    expect(mapLead($data('Yes'), $consent)->consentEvidence)->toBeTrue()
        ->and(mapLead($data('হ্যাঁ'), $consent)->consentEvidence)->toBeTrue()
        ->and(mapLead($data('No'), $consent)->consentEvidence)->toBeFalse()
        ->and(mapLead($data('maybe'), $consent)->consentEvidence)->toBeFalse()
        ->and(mapLead([['name' => 'email', 'values' => ['a@example.com']]], $consent)->consentEvidence)->toBeFalse();
});

it('bounds the answers and strips control characters', function () {
    $data = [['name' => 'email', 'values' => ['a@example.com']]];

    for ($i = 1; $i <= 40; $i++) {
        $data[] = ['name' => 'q'.$i, 'values' => ['v'.$i]];
    }

    $data[] = ['name' => 'long', 'values' => [str_repeat('x', 5000)]];
    $mapped = mapLead($data);

    expect(count($mapped->answers))->toBeLessThanOrEqual(FieldMapper::MAX_ANSWERS);

    $messy = mapLead([['name' => 'email', 'values' => ['a@example.com']], ['name' => 'note', 'values' => ["line\x00one\ttwo"]]]);
    expect($messy->answers['note'])->toBe('line one two');

    $long = mapLead([['name' => 'email', 'values' => ['a@example.com']], ['name' => 'long', 'values' => [str_repeat('x', 5000)]]]);
    expect(mb_strlen($long->answers['long']))->toBe(1000);
});

it('keeps two questions with the same answer name apart', function () {
    $mapped = mapLead(
        [['name' => 'email', 'values' => ['a@example.com']], ['name' => 'a', 'values' => ['1']], ['name' => 'b', 'values' => ['2']]],
        ['a' => ['target' => 'answer', 'answer_key' => 'Same'], 'b' => ['target' => 'answer', 'answer_key' => 'Same']],
    );

    expect($mapped->answers)->toBe(['Same' => '1', 'Same_2' => '2']);
});

it('warns about a mapping that cannot capture anything', function () {
    $mapper = new FieldMapper;

    expect($mapper->warnings(['q' => ['target' => 'answer', 'answer_key' => null]]))->toContain('no_contact_field')
        ->and($mapper->warnings(['p' => ['target' => 'phone', 'answer_key' => null]]))->toBe(['phone_only'])
        ->and($mapper->warnings(['e' => ['target' => 'email', 'answer_key' => null]]))->toBe([])
        ->and($mapper->warnings([
            'e' => ['target' => 'email', 'answer_key' => null],
            'a' => ['target' => 'consent', 'answer_key' => null],
            'b' => ['target' => 'consent', 'answer_key' => null],
        ]))->toBe(['several_consent']);
});

it('previews with example values that never look like real data', function () {
    $mapper = new FieldMapper;
    $questions = [
        ['key' => 'full_name', 'label' => 'Name', 'type' => 'FULL_NAME'],
        ['key' => 'email', 'label' => 'Email', 'type' => 'EMAIL'],
        ['key' => 'agree', 'label' => 'Agree', 'type' => 'CUSTOM'],
    ];
    $mapping = $mapper->resolve($questions, ['agree' => ['target' => 'consent']]);
    $preview = $mapper->map($mapper->sample($questions, $mapping), $mapping)->toPreview();

    expect($preview['email'])->toBe('sample@example.com')->and($preview['name'])->toBe('Sample Person')
        ->and($preview['consentEvidence'])->toBeTrue()->and($preview['problem'])->toBeNull();
});
