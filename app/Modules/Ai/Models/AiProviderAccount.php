<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Core\Tenancy\Concerns\UsesCentralConnection;
use App\Modules\Ai\Enums\AiDriver;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An AI provider account an operator added in the Super Admin panel. Platform data: central connection, no
 * tenant scope. `secrets` is encrypted at rest and hidden from serialization; only the provider resolver and the
 * account manager read it, and nothing returns it to a browser.
 *
 * @property string $id
 * @property AiDriver $driver
 * @property string $name
 * @property string $model
 * @property string|null $transcription_model
 * @property string|null $base_url
 * @property bool $is_enabled
 * @property bool $is_default
 * @property bool $is_transcription_default
 * @property array<string, string>|null $secrets
 * @property string|null $last_test_status
 * @property string|null $last_test_reason
 * @property Carbon|null $last_tested_at
 */
class AiProviderAccount extends Model
{
    use HasUlids;
    use UsesCentralConnection;

    protected $table = 'ai_provider_accounts';

    /** @var list<string> */
    protected $fillable = ['driver', 'name', 'model', 'transcription_model', 'base_url', 'is_enabled', 'is_default', 'is_transcription_default', 'secrets'];

    /** @var list<string> */
    protected $hidden = ['secrets'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'driver' => AiDriver::class,
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'is_transcription_default' => 'boolean',
            'secrets' => 'encrypted:array',
            'last_tested_at' => 'datetime',
        ];
    }

    public function hasKey(): bool
    {
        $key = $this->secrets['api_key'] ?? null;

        return is_string($key) && trim($key) !== '';
    }
}
