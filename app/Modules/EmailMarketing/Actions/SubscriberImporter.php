<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\EmailMarketing\Enums\SubscriptionSource;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\ImportRun;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use App\Modules\EmailMarketing\Support\CsvSafe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bulk membership from a CSV file.
 *
 * Safe parsing: size and row caps, UTF-8 only, no NUL bytes, a fixed column list (`prop_*` for properties; any
 * other unknown column refuses the file) and nothing is ever evaluated. The same file (by hash) is not imported
 * into the same list twice. Each row is its own unit: bad rows are reported by line, good rows are kept.
 *
 * Consent: a row is `subscribed` ONLY when it carries a consent method and evidence text. Every other row is
 * `pending` and is never sent to. Importing never records platform consent (that lives in the communication app)
 * and never reopens an unsubscribed, bounced or complained membership.
 */
final class SubscriberImporter
{
    public const array COLUMNS = ['email', 'first_name', 'last_name', 'consent_method', 'consent_evidence', 'consented_at'];

    private const int MAX_ERRORS = 100;

    public function __construct(
        private readonly SubscriberManager $subscribers,
        private readonly ContactDirectory $contacts,
        private readonly AuditLogger $audit,
    ) {}

    public static function template(): string
    {
        return CsvSafe::render([...self::COLUMNS, 'prop_plan'], [
            ['amina@example.com', 'Amina', 'Rahman', 'website_form', 'Signed up on the newsletter form on 2026-09-30', '2026-09-30', 'pro'],
            ['rafi@example.com', 'Rafi', 'Hasan', '', '', '', ''],
        ]);
    }

    /**
     * @throws ValidationException when the file as a whole is unusable
     */
    public function import(UploadedFile $file, MailingList $list, TenantMembership $by, bool $mayCreateContacts): ImportRun
    {
        $contents = $this->read($file);
        $hash = hash('sha256', $contents);

        if (ImportRun::query()->where('list_id', $list->id)->where('file_sha256', $hash)->exists()) {
            throw ValidationException::withMessages(['file' => __('This file has already been imported into this list.')]);
        }

        [$header, $rows] = $this->parse($contents);

        $counts = ['subscribed' => 0, 'pending' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];

        foreach ($rows as $line => $row) {
            try {
                $outcome = $this->row($list, $header, $row, $by, $mayCreateContacts);
                $counts[$outcome]++;
            } catch (ValidationException $exception) {
                $counts['failed']++;
                $errors[] = ['line' => $line, 'message' => (string) collect($exception->errors())->flatten()->first()];
            } catch (Throwable) {
                $counts['failed']++;
                $errors[] = ['line' => $line, 'message' => __('This row could not be imported.')];
            }
        }

        $run = ImportRun::query()->create([
            'list_id' => $list->id,
            'file_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 190, ''),
            'file_sha256' => $hash,
            'total_rows' => count($rows),
            'subscribed_rows' => $counts['subscribed'],
            'pending_rows' => $counts['pending'],
            'skipped_rows' => $counts['skipped'],
            'failed_rows' => $counts['failed'],
            'errors' => array_slice($errors, 0, self::MAX_ERRORS),
            'created_by' => $by->id,
        ]);

        $this->audit->record('email_marketing.import.completed', $run, [
            'list_id' => $list->id, 'total' => count($rows), 'subscribed' => $counts['subscribed'], 'pending' => $counts['pending'],
        ], subjectLabel: $run->file_name);

        return $run;
    }

    /**
     * @param  list<string>  $header
     * @param  array<int, string>  $row
     * @return 'subscribed'|'pending'|'skipped'
     */
    private function row(MailingList $list, array $header, array $row, TenantMembership $by, bool $mayCreateContacts): string
    {
        $data = [];

        foreach ($header as $index => $column) {
            $data[$column] = trim((string) ($row[$index] ?? ''));
        }

        $email = Str::lower($data['email'] ?? '');

        // An address that starts with a formula character is refused outright: no real mailbox does.
        if ($email === '' || strlen($email) > 190 || preg_match('/^[a-z0-9_]/', $email) !== 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => __('The email address is not valid.')]);
        }

        $contact = Contact::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

        if ($contact === null) {
            if (! $mayCreateContacts) {
                throw ValidationException::withMessages(['email' => __('No contact has this address, and you may not create contacts.')]);
            }

            $first = ContentBlocks::line($data['first_name'] ?? '');
            $contact = $this->contacts->createContact([
                'first_name' => mb_substr($first !== '' ? $first : Str::before($email, '@'), 0, 100),
                'last_name' => ($last = ContentBlocks::line($data['last_name'] ?? '')) === '' ? null : mb_substr($last, 0, 100),
                'email' => $email,
            ], $by);
        }

        $existing = Subscription::query()->where('list_id', $list->id)->where('contact_id', $contact->id)->first();

        if ($existing !== null && $existing->status->isClosed()) {
            throw ValidationException::withMessages(['email' => __('This person unsubscribed or bounced earlier and is left alone.')]);
        }

        $properties = [];

        foreach ($data as $column => $value) {
            if (str_starts_with($column, 'prop_') && $value !== '') {
                $properties[substr($column, 5)] = $value;
            }
        }

        $subscription = $this->subscribers->add($list, $contact->id, [
            'consent_method' => $data['consent_method'] ?? null,
            'consent_evidence' => $data['consent_evidence'] ?? null,
            'consented_at' => $data['consented_at'] ?? null,
            'properties' => $properties,
        ], SubscriptionSource::Import, $by, audit: false);

        if ($existing !== null && $subscription->status === $existing->status) {
            return 'skipped';
        }

        return $subscription->status === SubscriptionStatus::Subscribed ? 'subscribed' : 'pending';
    }

    /** @throws ValidationException */
    private function read(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $max = (int) config('email_marketing.import.max_bytes', 1048576);

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
     * @return array{0: list<string>, 1: array<int, list<string|null>>} header and rows keyed by file line
     *
     * @throws ValidationException
     */
    private function parse(string $contents): array
    {
        $maxRows = (int) config('email_marketing.import.max_rows', 2000);
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw ValidationException::withMessages(['file' => __('The file could not be read.')]);
        }

        fwrite($stream, $contents);
        rewind($stream);

        $header = fgetcsv($stream, 4096, ',', '"', '');

        if (! is_array($header)) {
            throw ValidationException::withMessages(['file' => __('The file is empty.')]);
        }

        $header = array_map(static fn (?string $column): string => Str::lower(trim((string) $column)), $header);

        foreach ($header as $column) {
            if (! in_array($column, self::COLUMNS, true) && preg_match('/^prop_[a-z][a-z0-9_]{0,31}$/', $column) !== 1) {
                throw ValidationException::withMessages(['file' => __('The column ":column" is not recognised.', ['column' => Str::limit($column, 40)])]);
            }
        }

        if (! in_array('email', $header, true) || count($header) !== count(array_unique($header))) {
            throw ValidationException::withMessages(['file' => __('The file needs one "email" column and no repeated columns.')]);
        }

        $rows = [];
        $line = 1;

        while (($row = fgetcsv($stream, 8192, ',', '"', '')) !== false) {
            $line++;

            if ($row === [null]) {
                continue;
            }

            if (count($rows) >= $maxRows) {
                throw ValidationException::withMessages(['file' => __('The file has more than :count rows. Split it.', ['count' => $maxRows])]);
            }

            $rows[$line] = $row;
        }

        fclose($stream);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The file has no rows.')]);
        }

        return [$header, $rows];
    }
}
