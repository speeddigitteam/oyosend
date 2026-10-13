<?php

declare(strict_types=1);

/*
 * Static architecture guard (CLAUDE.md, docs/MODULES.md): optional modules integrate only through core
 * contracts and domain events, never by importing another module's classes. The scan is over file contents, so
 * it also catches class names written as strings ('App\\Modules\\Hrm\\Events\\EmployeeCreated').
 *
 * Both rules share one allow-list of the documented exceptions. Every exception is a domain event class
 * referenced BY NAME (a string constant or a listener registration); none imports a model. An entry that no
 * longer matches anything fails the test, so the list cannot rot.
 */

/**
 * file (under app/Modules) => the classes it may reference, each with the reason.
 *
 * @return array<string, array<string, string>>
 */
function moduleBoundaryAllowList(): array
{
    return [
        'EmailMarketing/Listeners/MirrorUnsubscribe.php' => [
            'App\Modules\Communication\Events\ContactUnsubscribed' => 'documented hand-off: closes memberships on the unsubscribe event, referenced by name (docs/EMAIL_MARKETING.md); the payload is read, no Communication model is touched',
        ],
        'Invoicing/InvoicingServiceProvider.php' => [
            'App\Modules\CRM\Events\QuoteAccepted' => 'listens to the CRM event by class name (documented event contract, docs/INVOICING.md)',
            'App\Modules\Projects\Events\TimeEntryApproved' => 'listens to the Projects event by class name (documented event contract, docs/INVOICING.md)',
        ],
        'Payroll/PayrollServiceProvider.php' => [
            'App\Modules\Hrm\Events\EmployeeCreated' => 'HRM employee events consumed by class name through a documented listener (docs/MODULES.md)',
            'App\Modules\Hrm\Events\EmployeeUpdated' => 'same',
            'App\Modules\Hrm\Events\EmployeeTerminated' => 'same',
        ],
        'Talent/TalentServiceProvider.php' => [
            'App\Modules\Hrm\Events\EmployeeCreated' => 'HRM employee events consumed by class name through a documented listener (docs/MODULES.md)',
            'App\Modules\Hrm\Events\EmployeeUpdated' => 'same',
            'App\Modules\Hrm\Events\EmployeeTerminated' => 'same',
        ],
    ];
}

/**
 * Every `App\Modules\<Other>\...` class a module file names, per file.
 *
 * @return array<string, array{module: string, refs: list<array{module: string, class: string}>}>
 */
function moduleCrossReferences(): array
{
    static $found = null;

    if ($found !== null) {
        return $found;
    }

    $base = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules';
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
        $module = explode('/', $relative)[0];
        $contents = (string) file_get_contents($file->getPathname());

        // Single or doubled (string literal) backslashes alike.
        if (preg_match_all('#App\\\\+Modules\\\\+(\w+)((?:\\\\+\w+)+)#', $contents, $matches, PREG_SET_ORDER) === 0) {
            continue;
        }

        foreach ($matches as $match) {
            if ($match[1] === $module) {
                continue;
            }

            $class = 'App\\Modules\\'.$match[1].preg_replace('#\\\\+#', '\\', $match[2]);
            $found[$relative]['module'] = $module;
            $found[$relative]['refs'][] = ['module' => $match[1], 'class' => $class];
        }
    }

    return $found;
}

/**
 * @param  list<string>  $modules  Only files of these modules; empty = every module.
 * @param  list<string>  $targets  Only references to these modules; empty = any other module.
 * @param  list<string>  $except  Files of these modules are skipped.
 * @return list<string>
 */
function moduleBoundaryViolations(array $modules, array $targets, array $except = []): array
{
    $allowed = moduleBoundaryAllowList();
    $violations = [];

    foreach (moduleCrossReferences() as $file => $info) {
        if (($modules !== [] && ! in_array($info['module'], $modules, true)) || in_array($info['module'], $except, true)) {
            continue;
        }

        foreach ($info['refs'] as $ref) {
            if ($targets !== [] && ! in_array($ref['module'], $targets, true)) {
                continue;
            }

            if (! isset($allowed[$file][$ref['class']])) {
                $violations[] = $file.' references '.$ref['class'];
            }
        }
    }

    return array_values(array_unique($violations));
}

it('keeps the marketing, social, support, reports, automation and ai apps off Communication classes', function () {
    $violations = moduleBoundaryViolations(
        ['EmailMarketing', 'SmsMarketing', 'WhatsappMarketing', 'Social', 'Support', 'Reports', 'Automation', 'Ai'],
        ['Communication'],
    );

    expect($violations)->toBe([], "These files reach into the Communication app. Use the App\\Core\\Communication contracts or a domain event instead:\n".implode("\n", $violations));
});

it('lets no module outside Communication reference another module by namespace', function () {
    $violations = moduleBoundaryViolations([], [], ['Communication']);

    expect($violations)->toBe([], "Modules integrate only through App\\Core contracts and domain events (CLAUDE.md, docs/MODULES.md):\n".implode("\n", $violations));
});

it('keeps the allow-list honest: every exception is still used, and is only an event class', function () {
    $references = moduleCrossReferences();

    foreach (moduleBoundaryAllowList() as $file => $classes) {
        foreach (array_keys($classes) as $class) {
            $used = array_filter($references[$file]['refs'] ?? [], static fn (array $ref): bool => $ref['class'] === $class);

            expect($used)->not->toBeEmpty("Stale allow-list entry: {$file} no longer references {$class}.");
            expect(str_contains($class, '\\Events\\'))->toBeTrue("{$class} is not an event: only events may be referenced across modules.");
        }
    }
});
