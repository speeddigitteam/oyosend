<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds synthetic contacts and tickets into a disposable `*_testing` database,
 * times the list and report queries a workspace of that size runs, and prints
 * EXPLAIN for each so a missing index shows up as a full scan.
 *
 * It uses the application's own models (tenant scope included) and, for the
 * ticket report, the report service itself, so the SQL measured is the SQL the
 * pages run. It measures the database only: no HTTP, no Inertia, no Redis.
 */
final class BenchmarkCommand extends Command
{
    protected $signature = 'ops:benchmark
        {--rows=20000 : Contacts to seed (tickets are seeded at half of this unless --tickets is given)}
        {--tickets= : Tickets to seed}
        {--slug=perf-bench : Workspace slug to create or reuse}
        {--runs=7 : Timed runs per query (the first is discarded as warm-up)}
        {--detail : Print every SELECT with its time and EXPLAIN row}
        {--cleanup : Delete the benchmark workspace and its rows afterwards}';

    protected $description = 'Seed synthetic rows into a *_testing database and time key list queries with EXPLAIN';

    public function handle(TenantContext $context, TenantProvisioner $provisioner): int
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (app()->isProduction() || preg_match('/(^|_)testing(_test_\w+)?$/', $database) !== 1) {
            $this->components->error("Refusing: [{$database}] is not a disposable *_testing database.");

            return self::FAILURE;
        }

        $rows = max(100, (int) $this->option('rows'));
        $tickets = $this->option('tickets') !== null ? max(0, (int) $this->option('tickets')) : intdiv($rows, 2);
        $slug = Str::lower((string) $this->option('slug'));
        $runs = max(2, (int) $this->option('runs'));

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            Artisan::call('permissions:sync');
            $owner = User::query()->create([
                'name' => 'Benchmark Owner',
                'email' => $slug.'-owner@example.test',
                'password' => Hash::make(Str::random(40)),
            ]);
            $tenant = $provisioner->provision('Benchmark Workspace', $slug, $owner);
        }

        $this->components->info("Workspace [{$slug}] in [{$database}].");
        $this->seed($tenant, $rows, $tickets);

        $results = $context->run($tenant, fn (): array => $this->measureAll($runs));

        $this->newLine();
        $this->table(['Query', 'median ms', 'min ms', 'queries', 'rows read (EXPLAIN)', 'flags'], $results);
        $this->components->twoColumnDetail('MySQL', (string) DB::selectOne('select version() as v')->v);
        $this->components->twoColumnDetail('PHP', PHP_VERSION.' / '.PHP_OS_FAMILY);

        if ($this->option('cleanup')) {
            DB::table('tenants')->where('id', $tenant->getKey())->delete();
            $this->components->info('Workspace removed.');
        }

        return self::SUCCESS;
    }

    private function seed(Tenant $tenant, int $contacts, int $tickets): void
    {
        $tenantId = (string) $tenant->getKey();
        $haveContacts = (int) DB::table('contacts')->where('tenant_id', $tenantId)->count();
        $haveTickets = (int) DB::table('support_tickets')->where('tenant_id', $tenantId)->count();

        $first = ['Rahim', 'Karim', 'Fatema', 'Nusrat', 'Sabbir', 'Tania', 'Imran', 'Shirin', 'Arif', 'Mitu', 'Jahid', 'Rumana'];
        $last = ['Ahmed', 'Hossain', 'Khan', 'Chowdhury', 'Akter', 'Islam', 'Rahman', 'Uddin', 'Sarker', 'Begum', 'Mia', 'Das'];
        $cities = ['Dhaka', 'Chattogram', 'Khulna', 'Rajshahi', 'Sylhet', 'Barishal', 'Rangpur', 'Cumilla'];
        $now = Carbon::now();

        if ($haveContacts < $contacts) {
            $this->components->task("Seeding contacts ({$haveContacts} -> {$contacts})", function () use ($tenantId, $haveContacts, $contacts, $first, $last, $cities, $now): void {
                for ($offset = $haveContacts; $offset < $contacts; $offset += 1000) {
                    $batch = [];

                    for ($i = $offset; $i < min($offset + 1000, $contacts); $i++) {
                        $batch[] = [
                            'id' => (string) Str::ulid(),
                            'tenant_id' => $tenantId,
                            'first_name' => $first[$i % 12],
                            'last_name' => $last[intdiv($i, 12) % 12].($i % 7),
                            'email' => "contact{$i}@example.test",
                            'phone' => '+88017'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                            'city' => $cities[$i % 8],
                            'country' => 'BD',
                            'created_at' => $now->copy()->subMinutes($i),
                            'updated_at' => $now,
                        ];
                    }

                    DB::table('contacts')->insert($batch);
                }
            });
        }

        if ($haveTickets < $tickets) {
            $statuses = ['new', 'open', 'open', 'waiting_customer', 'on_hold', 'resolved', 'resolved', 'closed', 'closed', 'closed'];
            $priorities = ['low', 'normal', 'normal', 'high', 'urgent'];

            $this->components->task("Seeding tickets ({$haveTickets} -> {$tickets})", function () use ($tenantId, $haveTickets, $tickets, $statuses, $priorities, $now): void {
                for ($offset = $haveTickets; $offset < $tickets; $offset += 1000) {
                    $batch = [];

                    for ($i = $offset; $i < min($offset + 1000, $tickets); $i++) {
                        $created = $now->copy()->subMinutes($i * 7);
                        $status = $statuses[$i % 10];
                        $batch[] = [
                            'id' => (string) Str::ulid(),
                            'tenant_id' => $tenantId,
                            'number' => 'BENCH-'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                            'subject' => 'Synthetic ticket '.$i,
                            'status' => $status,
                            'priority' => $priorities[$i % 5],
                            'channel' => 'web',
                            'created_at' => $created,
                            'updated_at' => $created,
                            'last_activity_at' => $created,
                            'first_response_due_at' => $created->copy()->addHours(4),
                            'resolution_due_at' => $created->copy()->addDay(),
                            'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $created->copy()->addHours(6) : null,
                        ];
                    }

                    DB::table('support_tickets')->insert($batch);
                }
            });
        }
    }

    /** @return list<array<int, string>> */
    private function measureAll(int $runs): array
    {
        $out = [];
        $from = Carbon::now()->subDays(29)->toDateString();
        $to = Carbon::now()->toDateString();

        $scenarios = [
            'contacts: first page (25, ordered by name)' => fn () => Contact::query()->orderBy('first_name')->orderBy('last_name')->orderBy('id')->paginate(25),
            'contacts: deep page (offset 10 000)' => fn () => Contact::query()->orderBy('first_name')->orderBy('last_name')->orderBy('id')->paginate(25, ['*'], 'page', 401),
            'contacts: search "%rahim%" (list filter)' => fn () => Contact::query()
                ->where(fn ($match) => $match
                    ->where('first_name', 'like', '%rahim%')
                    ->orWhere('last_name', 'like', '%rahim%')
                    ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", ['%rahim%'])
                    ->orWhere('email', 'like', '%rahim%')
                    ->orWhere('phone', 'like', '%rahim%'))
                ->orderBy('first_name')->orderBy('last_name')->orderBy('id')->limit(25)->get(),
            'contacts: exact email lookup' => fn () => Contact::query()->where('email', 'contact777@example.test')->first(),
            'tickets: open, newest activity first (25)' => fn () => DB::table('support_tickets')
                ->where('tenant_id', $this->tenantId())->whereIn('status', ['new', 'open'])
                ->orderByDesc('last_activity_at')->limit(25)->get(),
            'tickets: count by status' => fn () => DB::table('support_tickets')
                ->where('tenant_id', $this->tenantId())->selectRaw('status, count(*) as c')->groupBy('status')->get(),
            'tickets: SLA due scan' => fn () => DB::table('support_tickets')
                ->where('tenant_id', $this->tenantId())->whereIn('status', ['new', 'open'])
                ->where('first_response_due_at', '<', Carbon::now())->limit(100)->get(),
            'tickets: newest by number (list default)' => fn () => DB::table('support_tickets')
                ->where('tenant_id', $this->tenantId())->orderByDesc('created_at')->limit(25)->get(),
        ];

        $reports = 'App\\Modules\\Support\\Actions\\SupportReports';

        if (class_exists($reports)) {
            $scenarios['report: support overview, last 30 days (service)'] = fn () => app($reports)->overview($from, $to);
        }

        foreach ($scenarios as $label => $callback) {
            if ($this->option('detail')) {
                $this->line($label);
            }

            $out[] = $this->measure($label, $callback, $runs);
        }

        return $out;
    }

    private function tenantId(): string
    {
        return app(TenantContext::class)->requireId();
    }

    /**
     * @return array<int, string>
     */
    private function measure(string $label, Closure $callback, int $runs): array
    {
        $captured = [];
        $listener = static function (QueryExecuted $query) use (&$captured): void {
            $captured[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'time' => $query->time];
        };

        DB::listen($listener);
        $callback();
        $statements = $captured;
        $captured = [];

        $timings = [];

        for ($run = 1; $run < $runs; $run++) {
            $started = hrtime(true);
            $callback();
            $timings[] = (hrtime(true) - $started) / 1e6;
        }

        sort($timings);
        $median = $timings[intdiv(count($timings), 2)];

        $examined = 0;
        $flags = [];

        foreach ($statements as $statement) {
            if (! str_starts_with(strtolower(ltrim($statement['sql'])), 'select')) {
                continue;
            }

            try {
                $plan = DB::select('explain '.$statement['sql'], $statement['bindings']);
            } catch (\Throwable $exception) {
                $flags[] = 'explain failed';

                continue;
            }

            foreach ($plan as $row) {
                $row = (array) $row;
                $rowsRead = (int) ($row['rows'] ?? 0);
                $examined += $rowsRead;

                if ($this->option('detail')) {
                    $this->line(sprintf(
                        '    %6.1f ms  %-5s key=%s rows=%d %s  | %s',
                        $statement['time'],
                        $row['type'] ?? '-',
                        $row['key'] ?? 'NULL',
                        $rowsRead,
                        $row['Extra'] ?? '',
                        Str::limit(preg_replace('/\s+/', ' ', $statement['sql']) ?? '', 110),
                    ));
                }

                if (($row['type'] ?? '') === 'ALL' && $rowsRead > 1000) {
                    $flags[] = 'full scan of '.($row['table'] ?? '?');
                }

                if (str_contains((string) ($row['Extra'] ?? ''), 'filesort') && $rowsRead > 1000) {
                    $flags[] = 'filesort '.($row['table'] ?? '?');
                }

                if (str_contains((string) ($row['Extra'] ?? ''), 'temporary') && $rowsRead > 1000) {
                    $flags[] = 'temporary '.($row['table'] ?? '?');
                }
            }
        }

        return [
            $label,
            number_format($median, 1),
            number_format($timings[0], 1),
            (string) count($statements),
            number_format($examined),
            implode('; ', array_unique($flags)) ?: '-',
        ];
    }
}
