<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Access\RecordAccess;
use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\SmsMarketing\Models\Group;
use App\Modules\SmsMarketing\Models\GroupMember;
use App\Modules\SmsMarketing\Models\Import;
use App\Modules\SmsMarketing\Models\Recipient;
use App\Modules\SmsMarketing\Support\CsvSafe;
use App\Modules\SmsMarketing\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CSV in and out of groups.
 *
 * Import adds EXISTING contacts to a group by phone number: a file never creates a contact and never records
 * consent. Parsing is strict (size and row caps, UTF-8 only, one required `phone` column) and nothing in a
 * file is evaluated; a cell is only looked up as a number. Export is formula-safe ({@see CsvSafe}).
 */
final class GroupCsv
{
    public const array COLUMNS = ['phone'];

    private const int MAX_ERRORS = 100;

    public function __construct(
        private readonly GroupManager $groups,
        private readonly RecordAccess $records,
        private readonly AuditLogger $audit,
    ) {}

    public static function template(): string
    {
        return CsvSafe::render(self::COLUMNS, [['+8801712345678'], ['01812345678']]);
    }

    /**
     * @throws ValidationException when the file as a whole is unusable or the contact limit would be exceeded
     */
    public function import(Group $group, UploadedFile $file, User $actor, TenantMembership $by): Import
    {
        $rows = $this->parse($this->read($file));

        $numbers = [];
        $errors = [];

        foreach ($rows as $line => $phone) {
            $normalized = PhoneNumber::normalize($phone);

            if ($normalized === null) {
                $errors[] = ['line' => $line, 'message' => __('This is not a valid phone number.')];

                continue;
            }

            $numbers[$line] = $normalized;
        }

        $byNumber = $this->contactsByNumber(array_values(array_unique($numbers)), $actor);
        $contactIds = [];

        foreach ($numbers as $line => $number) {
            if (! isset($byNumber[$number])) {
                $errors[] = ['line' => $line, 'message' => __('No contact you can see has this number.')];

                continue;
            }

            foreach ($byNumber[$number] as $id) {
                $contactIds[$id] = true;
            }
        }

        usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        $result = $contactIds === [] ? ['added' => 0, 'existing' => 0, 'missing' => 0] : $this->groups->addContacts($group, array_keys($contactIds), $by);

        $import = Import::query()->create([
            'group_id' => $group->id,
            'file_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 190, ''),
            'total_rows' => count($rows),
            'added_rows' => $result['added'],
            'failed_rows' => count($errors),
            'errors' => array_slice($errors, 0, self::MAX_ERRORS),
            'created_by' => $by->id,
        ]);

        $this->audit->record('sms_marketing.group.imported', $group, [
            'import_id' => $import->id, 'rows' => count($rows), 'added' => $result['added'], 'failed' => count($errors),
        ]);

        return $import;
    }

    /** The members of a group the actor may see, as CSV text: name and phone. */
    public function exportMembers(Group $group, User $actor): string
    {
        $rows = [];
        $visible = $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')->select('id');

        GroupMember::query()
            ->where('group_id', $group->id)
            ->whereIn('contact_id', $visible)
            ->with('contact:id,first_name,last_name,phone')
            ->orderBy('id')
            ->chunk(1000, function ($members) use (&$rows): void {
                foreach ($members as $member) {
                    if ($member->contact !== null) {
                        $rows[] = [$member->contact->fullName(), (string) $member->contact->phone];
                    }
                }
            });

        return CsvSafe::render(['name', 'phone'], $rows);
    }

    /** The recipients of one campaign as CSV text. */
    public function exportRecipients(string $campaignId): string
    {
        $rows = [];

        Recipient::query()
            ->where('campaign_id', $campaignId)
            ->with('contact:id,first_name,last_name')
            ->orderBy('id')
            ->chunk(1000, function ($recipients) use (&$rows): void {
                foreach ($recipients as $recipient) {
                    $rows[] = [
                        $recipient->contact?->fullName() ?? '',
                        (string) $recipient->phone,
                        $recipient->status->value,
                        (string) $recipient->reason,
                        $recipient->segments,
                        $recipient->sent_at?->toIso8601String() ?? '',
                        $recipient->delivered_at?->toIso8601String() ?? '',
                    ];
                }
            });

        return CsvSafe::render(['name', 'phone', 'status', 'reason', 'segments', 'sent_at', 'delivered_at'], $rows);
    }

    /**
     * Contacts the actor may see, grouped by the normalized number they carry.
     *
     * @param  list<string>  $numbers
     * @return array<string, list<string>>
     */
    private function contactsByNumber(array $numbers, User $actor): array
    {
        $found = [];

        foreach (array_chunk($numbers, 200) as $chunk) {
            $variants = [];

            foreach ($chunk as $number) {
                array_push($variants, ...PhoneNumber::variants($number));
            }

            $query = $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')->whereIn('phone', $variants);

            foreach ($query->get(['id', 'phone']) as $contact) {
                $normalized = PhoneNumber::normalize($contact->phone);

                if ($normalized !== null) {
                    $found[$normalized][] = $contact->id;
                }
            }
        }

        return $found;
    }

    /** @throws ValidationException */
    private function read(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $max = (int) config('sms_marketing.import.max_bytes', 524288);

        if ($path === false || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => __('The file could not be uploaded.')]);
        }

        if ((int) $file->getSize() > $max) {
            throw ValidationException::withMessages(['file' => __('The file is too large.')]);
        }

        $contents = (string) file_get_contents($path);

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => __('The file must be a UTF-8 text CSV.')]);
        }

        return str_replace(["\r\n", "\r"], "\n", $contents);
    }

    /**
     * @return array<int, string> phone cells keyed by their line number in the file
     *
     * @throws ValidationException
     */
    private function parse(string $contents): array
    {
        $lines = explode("\n", rtrim($contents, "\n"));
        $header = str_getcsv((string) array_shift($lines), ',', '"', '');
        $header = array_map(static fn (?string $cell): string => mb_strtolower(trim((string) $cell)), $header);
        $column = array_search('phone', $header, true);

        if ($column === false) {
            throw ValidationException::withMessages(['file' => __('The first row must have a "phone" column.')]);
        }

        $max = (int) config('sms_marketing.import.max_rows', 5000);
        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            if (count($rows) >= $max) {
                throw ValidationException::withMessages(['file' => __('The file has more than :max rows.', ['max' => $max])]);
            }

            $cells = str_getcsv($line, ',', '"', '');
            // An export of this app prefixes `+`-numbers with an apostrophe (formula safety): read it back.
            $rows[$index + 2] = ltrim(trim((string) ($cells[$column] ?? '')), "'");
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The file has no rows.')]);
        }

        return $rows;
    }
}
