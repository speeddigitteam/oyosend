<?php

declare(strict_types=1);

use App\Modules\Automation\Engine\RunExecutor;

it('removes urls, email addresses and long numbers from a message bound for the log', function () {
    $message = 'cURL error 6: Could not resolve https://api.example.test/v1/send?token=abc123 for rahim@example.test phone +880 1712-345678';

    $clean = RunExecutor::sanitized($message);

    expect($clean)->not->toContain('example.test')
        ->not->toContain('abc123')
        ->not->toContain('1712')
        ->toContain('[url]')->toContain('[email]')->toContain('[number]')
        ->toContain('cURL error 6');
});

it('keeps short numbers and cuts long messages', function () {
    expect(RunExecutor::sanitized('Error 500 on step 3'))->toBe('Error 500 on step 3')
        ->and(mb_strlen(RunExecutor::sanitized(str_repeat('x', 1000))))->toBe(200);
});
