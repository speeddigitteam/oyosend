<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

function backupTestDump(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'dump').'.sql';
    file_put_contents($path, $body);

    return $path;
}

function backupTestMysql(): ?string
{
    $candidates = glob('D:/laragon/bin/mysql/*/bin/mysql.exe') ?: [];
    $candidates[] = 'mysql';

    foreach ($candidates as $candidate) {
        $probe = @shell_exec('"'.$candidate.'" --version 2>&1');

        if (is_string($probe) && stripos($probe, 'mysql') !== false) {
            return $candidate;
        }
    }

    return null;
}

const GOOD_DUMP = <<<'SQL'
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (`id` int unsigned NOT NULL AUTO_INCREMENT, `migration` varchar(255) NOT NULL, PRIMARY KEY (`id`));
INSERT INTO `migrations` VALUES (1,'a'),(2,'b');
CREATE TABLE `users` (`id` char(26) NOT NULL, `email` varchar(255) NOT NULL, PRIMARY KEY (`id`));
INSERT INTO `users` VALUES ('01','a@example.test'),('02','b@example.test'),('03','it''s \ fine');
CREATE TABLE `tenants` (`id` char(26) NOT NULL, PRIMARY KEY (`id`));
INSERT INTO `tenants` VALUES ('t1');
CREATE TABLE `tenant_memberships` (`id` char(26) NOT NULL, PRIMARY KEY (`id`));
SQL;

describe('ops:verify-backup refusals', function () {
    it('refuses a scratch name that does not end in _restore_check', function () {
        $dump = backupTestDump(GOOD_DUMP);

        $this->artisan('ops:verify-backup', ['dump' => $dump, '--database' => 'oyosend'])->assertFailed();
        $this->artisan('ops:verify-backup', ['dump' => $dump, '--database' => 'oyosend_testing'])->assertFailed();
        $this->artisan('ops:verify-backup', ['dump' => $dump, '--database' => 'x`; drop database oyosend; --_restore_check'])->assertFailed();
    });

    it('refuses the application database even when named like a scratch one', function () {
        $dump = backupTestDump(GOOD_DUMP);
        config(['database.connections.mysql.database' => 'app_restore_check']);

        $this->artisan('ops:verify-backup', ['dump' => $dump, '--database' => 'app_restore_check'])->assertFailed();
    });

    it('refuses a missing dump', function () {
        $this->artisan('ops:verify-backup', ['dump' => '/no/such/file.sql'])->assertFailed();
    });

    it('refuses a dump that switches or creates a database', function (string $statement) {
        $dump = backupTestDump("-- header\n".GOOD_DUMP."\n{$statement}\n");

        $this->artisan('ops:verify-backup', ['dump' => $dump, '--database' => 'oyosend_refusal_restore_check'])
            ->assertFailed();

        // Nothing was created: the refusal happens before the server is touched.
        expect(DB::selectOne('select count(*) as c from information_schema.schemata where schema_name = ?', ['oyosend_refusal_restore_check'])->c)->toBe(0);
    })->with([
        'use' => 'USE `oyosend`;',
        'create database' => 'CREATE DATABASE `other`;',
        'versioned drop database' => '/*!40000 DROP DATABASE IF EXISTS `oyosend`*/;',
        'client shell escape' => '\! echo hi',
        'source' => 'SOURCE /etc/passwd;',
        'tampered insert with a trailing client command' => "INSERT INTO `users` VALUES ('09','x@example.test'); \! cmd",
        'statement after a semicolon mid-line' => "INSERT INTO `users` VALUES ('09','x@example.test');USE prod;",
        'versioned use mid-line' => 'SELECT 1; /*!50000 USE prod */;',
        'source command mid-line' => 'SELECT 1; SOURCE /etc/passwd;',
        'dot (source) client command' => "SELECT 1; \. /etc/passwd",
        'backslash command after a closed string' => "INSERT INTO `users` VALUES ('09','x'); \u prod",
    ]);
});

describe('ops:verify-backup restore', function () {
    it('restores a dump into a scratch database, reports core counts and drops it', function () {
        $mysql = backupTestMysql();

        if ($mysql === null) {
            $this->markTestSkipped('No mysql client executable found.');
        }

        $scratch = 'oyosend_vbtest_restore_check';
        $dump = backupTestDump(GOOD_DUMP);

        $this->artisan('ops:verify-backup', ['dump' => $dump, '--database' => $scratch, '--mysql-bin' => $mysql])
            ->expectsOutputToContain('Backup verification passed')
            ->expectsOutputToContain('Dropped scratch database')
            ->assertSuccessful();

        expect(DB::selectOne('select count(*) as c from information_schema.schemata where schema_name = ?', [$scratch])->c)->toBe(0);
    });

    it('does not mistake statement-looking text inside a string literal for a statement', function () {
        $mysql = backupTestMysql();

        if ($mysql === null) {
            $this->markTestSkipped('No mysql client executable found.');
        }

        $scratch = 'oyosend_vbstr_restore_check';
        $dump = backupTestDump(GOOD_DUMP."
INSERT INTO `users` VALUES ('04','a; USE prod; \\! hi'),('05','multi
line; USE prod;');
");

        $this->artisan('ops:verify-backup', ['dump' => $dump, '--database' => $scratch, '--mysql-bin' => $mysql])
            ->expectsOutputToContain('Backup verification passed')
            ->assertSuccessful();
    });

    it('restores a gzip-compressed dump', function () {
        $mysql = backupTestMysql();

        if ($mysql === null) {
            $this->markTestSkipped('No mysql client executable found.');
        }

        $scratch = 'oyosend_vbtest4_restore_check';
        $dump = tempnam(sys_get_temp_dir(), 'dump').'.sql.gz';
        file_put_contents($dump, gzencode(GOOD_DUMP));

        $this->artisan('ops:verify-backup', ['dump' => $dump, '--database' => $scratch, '--mysql-bin' => $mysql])
            ->assertSuccessful();

        expect(DB::selectOne('select count(*) as c from information_schema.schemata where schema_name = ?', [$scratch])->c)->toBe(0);
    });

    it('fails when a required table is missing and still drops the scratch database', function () {
        $mysql = backupTestMysql();

        if ($mysql === null) {
            $this->markTestSkipped('No mysql client executable found.');
        }

        $scratch = 'oyosend_vbtest2_restore_check';
        $dump = backupTestDump("CREATE TABLE `migrations` (`id` int);\nINSERT INTO `migrations` VALUES (1);\n");

        $this->artisan('ops:verify-backup', ['dump' => $dump, '--database' => $scratch, '--mysql-bin' => $mysql])
            ->assertFailed();

        expect(DB::selectOne('select count(*) as c from information_schema.schemata where schema_name = ?', [$scratch])->c)->toBe(0);
    });

    it('does not touch a database that already exists under the scratch name', function () {
        $mysql = backupTestMysql();

        if ($mysql === null) {
            $this->markTestSkipped('No mysql client executable found.');
        }

        $scratch = 'oyosend_vbtest3_restore_check';
        DB::statement("create database `{$scratch}`");

        try {
            $this->artisan('ops:verify-backup', [
                'dump' => backupTestDump(GOOD_DUMP), '--database' => $scratch, '--mysql-bin' => $mysql,
            ])->assertFailed();

            expect(DB::selectOne('select count(*) as c from information_schema.schemata where schema_name = ?', [$scratch])->c)->toBe(1);
        } finally {
            DB::statement("drop database if exists `{$scratch}`");
        }
    });
});
