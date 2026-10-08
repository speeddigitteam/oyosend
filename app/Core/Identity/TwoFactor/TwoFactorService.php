<?php

declare(strict_types=1);

namespace App\Core\Identity\TwoFactor;

use App\Core\Identity\Contracts\TwoFactorAuthenticatable;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * TOTP (RFC 6238) enrolment and verification, shared by both guards.
 */
final class TwoFactorService
{
    public function __construct(
        private readonly Google2FA $engine,
        private readonly Cache $cache,
    ) {}

    /**
     * Start enrolment: store a fresh secret and recovery codes, unconfirmed.
     * Two-factor is not active until {@see confirm()} succeeds.
     */
    public function enable(TwoFactorAuthenticatable&Model $account): void
    {
        $account->forceFill([
            'two_factor_secret' => $this->engine->generateSecretKey(32),
            'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Finish enrolment by proving the authenticator app produces valid codes.
     */
    public function confirm(TwoFactorAuthenticatable&Model $account, string $code): bool
    {
        if ($account->twoFactorSecret() === null || ! $this->verify($account, $code)) {
            return false;
        }

        $account->forceFill(['two_factor_confirmed_at' => Carbon::now()])->save();

        return true;
    }

    public function disable(TwoFactorAuthenticatable&Model $account): void
    {
        $account->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(TwoFactorAuthenticatable&Model $account): array
    {
        $codes = $this->generateRecoveryCodes();

        $account->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    /**
     * Verify a 6-digit code.
     *
     * A code is accepted once: the time step of the last accepted code is
     * remembered so it cannot be replayed. And an account that keeps entering
     * wrong codes is refused for a while, whatever it enters: with only a
     * million possible codes, anyone holding the password could otherwise
     * guess their way past the second factor.
     */
    public function verify(TwoFactorAuthenticatable $account, string $code): bool
    {
        $secret = $account->twoFactorSecret();
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $failuresKey = $this->failuresKey($account);

        if ($this->isLockedOut($account)) {
            return false;
        }

        $cacheKey = 'two-factor:last-step:'.$account->twoFactorKey();

        // Always pass a previous step. Given null the library skips the
        // "newer than" comparison and answers `true` instead of the step, and
        // then there is nothing to remember: the first code would stay
        // replayable for as long as its window lasts.
        $lastStep = (int) $this->cache->get($cacheKey, 0);

        try {
            $step = $this->engine->verifyKeyNewer($secret, $code, $lastStep);
        } catch (Throwable) {
            return false;
        }

        if (! is_int($step)) {
            // The window starts with the first wrong code and is not extended by later ones.
            $this->cache->add($failuresKey, 0, (int) config('platform.two_factor.lockout_seconds', 900));
            $this->cache->increment($failuresKey);

            return false;
        }

        $this->cache->put($cacheKey, $step, 300);
        $this->cache->forget($failuresKey);

        return true;
    }

    /**
     * Whether codes are being refused after too many wrong ones. Recovery
     * codes are not affected.
     */
    public function isLockedOut(TwoFactorAuthenticatable $account): bool
    {
        return (int) $this->cache->get($this->failuresKey($account), 0) >= (int) config('platform.two_factor.max_failed_codes', 10);
    }

    private function failuresKey(TwoFactorAuthenticatable $account): string
    {
        return 'two-factor:failures:'.$account->twoFactorKey();
    }

    public function provisioningUri(TwoFactorAuthenticatable $account): ?string
    {
        $secret = $account->twoFactorSecret();

        return $secret === null
            ? null
            : $this->engine->getQRCodeUrl((string) config('app.name'), $account->twoFactorLabel(), $secret);
    }

    /**
     * The enrolment QR code as inline SVG (no external image service involved).
     */
    public function qrCodeSvg(TwoFactorAuthenticatable $account): ?string
    {
        $uri = $this->provisioningUri($account);

        if ($uri === null) {
            return null;
        }

        $renderer = new ImageRenderer(
            new RendererStyle(192, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(20, 28, 24))),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($uri);

        return trim(substr($svg, (int) strpos($svg, '<svg')));
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $count = (int) config('platform.two_factor.recovery_codes', 8);

        return array_map(
            static fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, max(1, $count)),
        );
    }
}
