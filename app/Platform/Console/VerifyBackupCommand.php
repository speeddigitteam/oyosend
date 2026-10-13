<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Illuminate\Console\Command;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Proves that a mysqldump file can be restored, without going near the
 * application's database.
 *
 * The dump is loaded into a scratch database that this command creates itself.
 * The name has to end in `_restore_check`, must not exist yet and must not be
 * the application's database; it is dropped afterwards only when this run
 * created it. A dump that selects or creates another database (`USE`,
 * `CREATE DATABASE`, `DROP DATABASE`) or runs a client command (`SOURCE`,
 * `SYSTEM`, `\!`) is refused before anything is executed, wherever on a
 * line it sits outside a string literal. The restore also runs the client with
 * `--binary-mode`, which turns its backslash commands off, so a statement the
 * scan misses still cannot shell out. Use a restore account that has privileges
 * only on `*_restore_check` (docs/BACKUP_RESTORE.md).
 *
 * Row counts of the core tables are read from the restored copy. With
 * `--source` they are compared with a live database (read-only `COUNT(*)`).
 */
final class VerifyBackupCommand extends Command
{
    private const SWITCH_PATTERN = '/^(CREATE\s+(DATABASE|SCHEMA)|DROP\s+(DATABASE|SCHEMA)|USE\s|SOURCE\s|SYSTEM\s|\\\\[!.])/i';

    public const SCRATCH_PATTERN = '/^[A-Za-z0-9]+(?:_[A-Za-z0-9]+)*_restore_check$/';

    /** Tables a restore must contain for the copy to count as an application backup. */
    private const REQUIRED = ['migrations', 'users', 'tenants', 'tenant_memberships'];

    /** Tables whose row counts are reported (and compared with --source). */
    private const CORE = [
        'migrations', 'users', 'tenants', 'tenant_memberships', 'tenant_domains', 'platform_users', 'roles',
        'permissions', 'subscriptions', 'invoices', 'payments', 'audit_logs', 'contacts', 'companies',
    ];

    protected $signature = 'ops:verify-backup
        {dump : Path to a mysqldump file (.sql or .sql.gz)}
        {--database=oyosend_restore_check : Scratch database to create; must end in _restore_check}
        {--connection= : Database connection whose server and credentials are used (default connection when omitted)}
        {--source= : Compare the restored row counts with this existing database (read-only)}
        {--mysql-bin= : The mysql client executable (default: platform.ops.mysql_bin)}
        {--keep : Leave the scratch database in place for inspection}';

    protected $description = 'Restore a mysqldump into a disposable scratch database, check its core tables, then drop it';

    public function handle(): int
    {
        $dump = (string) $this->argument('dump');
        $scratch = (string) $this->option('database');
        $connection = (string) ($this->option('connection') ?: config('database.default'));

        /** @var array<string, mixed> $settings */
        $settings = (array) config("database.connections.{$connection}", []);

        if (preg_match(self::SCRATCH_PATTERN, $scratch) !== 1 || strlen($scratch) > 64) {
            return $this->refuse("The scratch database name must match {$this->quotePattern()}: [{$scratch}] does not.");
        }

        if (strtolower($scratch) === strtolower((string) ($settings['database'] ?? ''))) {
            return $this->refuse('The scratch database is the application database. Refusing.');
        }

        if (! is_file($dump) || ! is_readable($dump)) {
            return $this->refuse("The dump [{$dump}] does not exist or is not readable.");
        }

        if (($settings['driver'] ?? null) !== 'mysql') {
            return $this->refuse("Connection [{$connection}] is not a mysql connection.");
        }

        $forbidden = $this->findDatabaseSwitch($dump);

        if ($forbidden !== null) {
            return $this->refuse("The dump contains a statement that leaves the scratch database ({$forbidden}). Take it without --databases / --all-databases.");
        }

        $source = $this->option('source');

        if (is_string($source) && $source !== '' && preg_match('/^[A-Za-z0-9_]+$/', $source) !== 1) {
            return $this->refuse('--source is not a valid database name.');
        }

        try {
            $pdo = $this->server($settings);
        } catch (PDOException $exception) {
            return $this->refuse('Cannot reach the database server: '.$this->clean($exception->getMessage(), $settings));
        }

        $exists = $pdo->prepare('select count(*) from information_schema.schemata where schema_name = ?');
        $exists->execute([$scratch]);

        if ((int) $exists->fetchColumn() > 0) {
            return $this->refuse("A database named [{$scratch}] already exists. It was not created by this run, so it is left alone: drop it yourself or choose another name.");
        }

        $pdo->exec('create database `'.$scratch.'` character set utf8mb4 collate utf8mb4_unicode_ci');
        $this->components->info("Created scratch database [{$scratch}].");

        $status = self::FAILURE;

        try {
            $started = microtime(true);
            $this->restore($dump, $scratch, $settings);
            $this->components->info(sprintf('Restored in %.1f s.', microtime(true) - $started));

            $status = $this->verify($pdo, $scratch, is_string($source) && $source !== '' ? $source : null);
        } catch (Throwable $exception) {
            $this->components->error($this->clean($exception->getMessage(), $settings));
        } finally {
            $this->dropScratch($pdo, $scratch);
        }

        return $status;
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }

    private function quotePattern(): string
    {
        return '*_restore_check (letters, digits and underscores)';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function server(array $settings): PDO
    {
        $dsn = isset($settings['unix_socket']) && $settings['unix_socket'] !== ''
            ? 'mysql:unix_socket='.$settings['unix_socket']
            : 'mysql:host='.$settings['host'].';port='.$settings['port'];

        return new PDO($dsn, (string) $settings['username'], (string) ($settings['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * First statement that would move the restore to another database or run a
     * client command, or null. Two layers: a cheap line-prefix test, and a scan
     * of the text outside string literals for a statement after `;` and for any
     * backslash client command (`\!`, `\.`, `\u` ...). The restore itself also
     * runs the client with --binary-mode, which turns client commands off.
     */
    private function findDatabaseSwitch(string $dump): ?string
    {
        $handle = $this->open($dump);
        $open = null; // the quote character of a string literal that continues on the next line

        try {
            while (($line = fgets($handle)) !== false) {
                $head = ltrim(substr($line, 0, 64));

                if ($open === null && str_starts_with($head, '--')) {
                    continue; // a comment line: the client ignores everything on it
                }

                if ($open === null && $head !== '' && in_array($head[0], ['C', 'c', 'U', 'u', 'D', 'd', 'S', 's', '/', '\\'], true)) {
                    // `/*!40000 DROP DATABASE ... */` style version comments carry statements too.
                    $bare = preg_replace('/^\/\*![0-9]*\s*/', '', $head) ?? $head;

                    if (preg_match(self::SWITCH_PATTERN, $bare, $match) === 1) {
                        return strtoupper(trim($match[0]));
                    }
                }

                $rest = $this->outsideStrings($line, $open);

                if ($rest === null) {
                    return 'UNSCANNABLE LINE';
                }

                if (str_contains($rest, '\\')) {
                    return 'BACKSLASH CLIENT COMMAND';
                }

                // A statement that follows another one on the same line (`...; USE prod;`).
                $rest = preg_replace('/\/\*![0-9]*/', ' ', $rest) ?? $rest;

                if (preg_match('/(?:^|;)\s*(?:CREATE\s+(?:DATABASE|SCHEMA)|DROP\s+(?:DATABASE|SCHEMA)|USE\s|SOURCE\s|SYSTEM\s)/i', $rest, $match) === 1) {
                    return strtoupper(trim($match[0], "; \t\n\r"));
                }
            }
        } finally {
            gzclose($handle);
        }

        return null;
    }

    /**
     * The line with every string literal and quoted identifier removed. $open
     * carries a literal that is still open at the end of the line. Null when
     * the regex engine gave up (so the caller refuses rather than guesses).
     */
    private function outsideStrings(string $line, ?string &$open): ?string
    {
        $out = '';
        $offset = 0;
        $length = strlen($line);

        while ($offset < $length) {
            if ($open !== null) {
                // Look for the closing quote; `\x` and a doubled quote stay inside.
                $q = preg_quote($open, '/');
                $matched = preg_match('/\G(?>[^'.$q.'\\\\]+|\\\\.|'.$q.$q.')*'.$q.'/s', $line, $m, 0, $offset);

                if ($matched === false) {
                    return null;
                }

                if ($matched === 0) {
                    return $out; // still open on the next line
                }

                $offset += strlen($m[0]);
                $open = null;

                continue;
            }

            $next = strcspn($line, '\'"`', $offset);
            $out .= substr($line, $offset, $next);
            $offset += $next;

            if ($offset < $length) {
                $open = $line[$offset];
                $offset++;
            }
        }

        return $out;
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open [{$path}].");
        }

        return $handle;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function restore(string $dump, string $scratch, array $settings): void
    {
        $binary = (string) ($this->option('mysql-bin') ?: config('platform.ops.mysql_bin', 'mysql'));

        $command = [$binary, '--host='.$settings['host'], '--port='.$settings['port'], '--user='.$settings['username'],
            '--default-character-set=utf8mb4', '--binary-mode', '--database='.$scratch];

        if (! empty($settings['unix_socket'])) {
            $command = [$binary, '--socket='.$settings['unix_socket'], '--user='.$settings['username'],
                '--default-character-set=utf8mb4', '--binary-mode', '--database='.$scratch];
        }

        // The password travels in the environment, never in the argument list.
        $process = new Process($command, null, ['MYSQL_PWD' => (string) ($settings['password'] ?? '')], null, 3600);
        $process->setInput((function () use ($dump) {
            $handle = $this->open($dump);

            try {
                while (! gzeof($handle)) {
                    $chunk = gzread($handle, 1024 * 1024);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    yield $chunk;
                }
            } finally {
                gzclose($handle);
            }
        })());

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new RuntimeException("Could not run [{$binary}]: ".$exception->getMessage());
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The restore failed: '.trim($process->getErrorOutput()));
        }
    }

    private function verify(PDO $pdo, string $scratch, ?string $source): int
    {
        $tables = $this->tableNames($pdo, $scratch);
        $this->components->twoColumnDetail('Tables restored', (string) count($tables));

        $failed = false;

        foreach (self::REQUIRED as $required) {
            if (! in_array($required, $tables, true)) {
                $this->components->error("Required table [{$required}] is missing from the restore.");
                $failed = true;
            }
        }

        $sourceTables = $source === null ? [] : $this->tableNames($pdo, $source);

        if ($source !== null && $sourceTables === []) {
            $this->components->error("--source database [{$source}] has no tables or does not exist.");
            $failed = true;
        }

        foreach (self::CORE as $table) {
            if (! in_array($table, $tables, true)) {
                $this->components->twoColumnDetail($table, 'missing');

                continue;
            }

            $restored = $this->count($pdo, $scratch, $table);
            $line = (string) $restored;

            if ($source !== null && in_array($table, $sourceTables, true)) {
                $live = $this->count($pdo, $source, $table);
                $line .= $live === $restored ? " (matches {$source})" : " (DIFFERS: {$source} has {$live})";
                $failed = $failed || $live !== $restored;
            }

            $this->components->twoColumnDetail($table, $line);
        }

        if ($this->count($pdo, $scratch, 'migrations') === 0 && in_array('migrations', $tables, true)) {
            $this->components->error('The migrations table is empty: this is not a restored application database.');
            $failed = true;
        }

        $failed
            ? $this->components->error('Backup verification FAILED.')
            : $this->components->info('Backup verification passed: restored and core tables present.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> */
    private function tableNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "select table_name as t from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'",
        );
        $statement->execute([$database]);

        return array_map('strtolower', array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function count(PDO $pdo, string $database, string $table): int
    {
        // Both names come from fixed lists or a validated pattern, never from the dump.
        return (int) $pdo->query('select count(*) from `'.$database.'`.`'.$table.'`')->fetchColumn();
    }

    private function dropScratch(PDO $pdo, string $scratch): void
    {
        // Defence in depth: the name was validated above, and is checked again right before the drop.
        if (preg_match(self::SCRATCH_PATTERN, $scratch) !== 1) {
            return;
        }

        if ($this->option('keep')) {
            $this->components->warn("Scratch database [{$scratch}] kept; drop it when you are done.");

            return;
        }

        $pdo->exec('drop database if exists `'.$scratch.'`');
        $this->components->info("Dropped scratch database [{$scratch}].");
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function clean(string $message, array $settings): string
    {
        $password = (string) ($settings['password'] ?? '');

        return $password === '' ? $message : str_replace($password, '***', $message);
    }
}
