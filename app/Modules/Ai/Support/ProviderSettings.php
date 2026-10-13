<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Enums\AiDriver;

/**
 * Everything an adapter needs to make a call: built per call from the default account or from config, held
 * only for the duration of that call. The key never leaves this object except into the request header, and a
 * dump of it shows no key.
 */
final readonly class ProviderSettings
{
    public function __construct(
        public AiDriver $driver,
        public string $apiKey,
        public string $model,
        public string $baseUrl,
        public int $timeout = 60,
        public string $version = '2023-06-01',
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['driver' => $this->driver, 'model' => $this->model, 'baseUrl' => $this->baseUrl, 'apiKey' => $this->apiKey === '' ? '' : '[hidden]'];
    }
}
