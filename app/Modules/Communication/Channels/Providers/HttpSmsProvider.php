<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers;

use App\Modules\Communication\Channels\Contracts\PlatformAccountProvider;
use App\Modules\Communication\Channels\Contracts\SmsChannel;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SMS through any gateway that takes one HTTP request per message (most Bangladeshi and regional gateways do).
 * The operator describes the request once: endpoint, method, how the API key is sent and which parameter carries
 * the number, the text and the sender. The key is the account's encrypted secret.
 *
 * Guard rails: the endpoint is operator configuration (never tenant input), `https` unless the platform allows
 * plain `http` (`communication.sms.allow_insecure_http`), no embedded credentials and no IP-literal, local or
 * private host. The key never appears in an error, a log or a stored result. A gateway that answers `200` with an
 * error text is handled by `success_contains`: when set, the response body must contain it.
 *
 * Delivery receipts are not consumed: accepted messages stay `sent`.
 */
final class HttpSmsProvider implements PlatformAccountProvider, SmsChannel
{
    public const string KEY = 'http_sms';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Custom HTTP SMS gateway';
    }

    public function channel(): Channel
    {
        return Channel::Sms;
    }

    public function isTestDouble(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [];
    }

    public function accountFields(): array
    {
        return [
            ['key' => 'endpoint', 'label' => 'Endpoint URL', 'type' => 'text', 'required' => true, 'maxLength' => 300],
            ['key' => 'method', 'label' => 'HTTP method', 'type' => 'select', 'required' => true, 'options' => ['POST', 'GET']],
            ['key' => 'body_format', 'label' => 'Send parameters as', 'type' => 'select', 'required' => true, 'options' => ['form', 'json', 'query']],
            ['key' => 'auth_mode', 'label' => 'API key is sent as', 'type' => 'select', 'required' => true, 'options' => ['bearer', 'header', 'query', 'body', 'basic']],
            ['key' => 'auth_name', 'label' => 'Header / parameter / user name for the key', 'type' => 'text', 'required' => false, 'maxLength' => 60],
            ['key' => 'secret', 'label' => 'API key', 'type' => 'secret', 'required' => true],
            ['key' => 'to_param', 'label' => 'Number parameter', 'type' => 'text', 'required' => true, 'maxLength' => 40],
            ['key' => 'to_format', 'label' => 'Number format', 'type' => 'select', 'required' => true, 'options' => ['e164', 'digits']],
            ['key' => 'message_param', 'label' => 'Message parameter', 'type' => 'text', 'required' => true, 'maxLength' => 40],
            ['key' => 'sender_param', 'label' => 'Sender parameter', 'type' => 'text', 'required' => false, 'maxLength' => 40],
            ['key' => 'sender_value', 'label' => 'Sender ID value', 'type' => 'text', 'required' => false, 'maxLength' => 40],
            ['key' => 'extra_params', 'label' => 'Fixed parameters (name=value&name=value)', 'type' => 'text', 'required' => false, 'maxLength' => 300],
            ['key' => 'success_contains', 'label' => 'Response must contain', 'type' => 'text', 'required' => false, 'maxLength' => 60],
        ];
    }

    public function supportsTestSend(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    /**
     * Problems with an account's settings, keyed by field. Used when the account is saved.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    public static function problems(array $settings): array
    {
        $problems = [];
        $endpoint = (string) ($settings['endpoint'] ?? '');

        if (($problem = self::endpointProblem($endpoint)) !== null) {
            $problems['endpoint'] = $problem;
        }

        foreach (['to_param', 'message_param', 'sender_param', 'auth_name'] as $key) {
            $value = (string) ($settings[$key] ?? '');

            if ($value !== '' && preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $value) !== 1) {
                $problems[$key] = 'Use letters, digits, dot, dash and underscore only.';
            }
        }

        if (in_array($settings['auth_mode'] ?? '', ['header', 'query', 'body', 'basic'], true) && ($settings['auth_name'] ?? '') === '') {
            $problems['auth_name'] = 'Name the header, parameter or user the key goes in.';
        }

        if (($settings['sender_param'] ?? '') !== '' && ($settings['sender_value'] ?? '') === '') {
            $problems['sender_value'] = 'Enter the sender ID this parameter carries.';
        }

        if (self::extraParams((string) ($settings['extra_params'] ?? '')) === null) {
            $problems['extra_params'] = 'Use name=value pairs joined with &.';
        }

        return $problems;
    }

    public function state(ChannelConnection $connection): ConfigurationState
    {
        return $connection->credential_ref === null ? ConfigurationState::NotConfigured : ConfigurationState::Configured;
    }

    public function send(OutboundPayload $payload, ChannelConnection $connection, ?ChannelCredentials $credentials): SendResult
    {
        $settings = $credentials?->settings() ?? [];

        if ($credentials === null || self::problems($settings) !== [] || ! isset($settings['to_param'], $settings['message_param'], $settings['method'])) {
            return SendResult::rejected('provider_not_configured', 'The SMS gateway account is not configured.');
        }

        if (preg_match('/^\+[1-9]\d{6,14}$/', $payload->to) !== 1) {
            return SendResult::rejected('invalid_recipient', 'The number is not a valid international phone number.');
        }

        if (trim($payload->body) === '') {
            return SendResult::rejected('empty_message', 'There is nothing to send.');
        }

        $parameters = [
            ...(self::extraParams((string) ($settings['extra_params'] ?? '')) ?? []),
            (string) $settings['to_param'] => ($settings['to_format'] ?? 'e164') === 'digits' ? ltrim($payload->to, '+') : $payload->to,
            (string) $settings['message_param'] => $payload->body,
        ];

        if (($settings['sender_param'] ?? '') !== '' && ($settings['sender_value'] ?? '') !== '') {
            $parameters[(string) $settings['sender_param']] = (string) $settings['sender_value'];
        }

        $request = Http::acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
        $query = [];
        $authName = (string) ($settings['auth_name'] ?? '');

        switch ($settings['auth_mode'] ?? 'bearer') {
            case 'bearer':
                $request = $request->withToken($credentials->secret());
                break;
            case 'header':
                $request = $request->withHeaders([$authName => $credentials->secret()]);
                break;
            case 'basic':
                $request = $request->withBasicAuth($authName, $credentials->secret());
                break;
            case 'query':
                $query[$authName] = $credentials->secret();
                break;
            case 'body':
                $parameters[$authName] = $credentials->secret();
                break;
        }

        try {
            $response = $this->dispatch($request, (string) $settings['method'], (string) ($settings['body_format'] ?? 'form'), (string) $settings['endpoint'], $parameters, $query);
        } catch (ConnectionException) {
            return SendResult::retry('provider_unreachable', 'The SMS gateway could not be reached.');
        } catch (Throwable $exception) {
            return SendResult::retry('provider_error', class_basename($exception));
        }

        $status = $response->status();

        if ($status === 429 || $status >= 500) {
            return SendResult::retry('provider_unavailable', 'The SMS gateway is busy or unavailable.');
        }

        if ($status === 401 || $status === 403) {
            return SendResult::rejected('provider_credentials_rejected', 'The SMS gateway refused the credentials.');
        }

        if (! $response->successful()) {
            return SendResult::rejected('provider_rejected', 'The SMS gateway refused the message (HTTP '.$status.').');
        }

        $needle = (string) ($settings['success_contains'] ?? '');

        if ($needle !== '' && ! str_contains($response->body(), $needle)) {
            return SendResult::rejected('provider_rejected', 'The SMS gateway did not confirm the message.');
        }

        $id = $response->json('message_id') ?? $response->json('id') ?? $response->json('sid');

        return SendResult::accepted(is_string($id) || is_int($id) ? mb_substr((string) $id, 0, 150) : null);
    }

    public function parseWebhook(string $body, array $headers, string $secret): array
    {
        throw new InvalidWebhookSignature('This provider does not receive webhooks.');
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, string>  $query
     */
    private function dispatch(PendingRequest $request, string $method, string $format, string $endpoint, array $parameters, array $query): Response
    {
        if ($method === 'GET' || $format === 'query') {
            return $request->send($method === 'GET' ? 'GET' : 'POST', $endpoint, ['query' => [...$parameters, ...$query]]);
        }

        $request = $request->withQueryParameters($query);

        return $format === 'json'
            ? $request->asJson()->post($endpoint, $parameters)
            : $request->asForm()->post($endpoint, $parameters);
    }

    /**
     * @return array<string, string>|null Null when the text is not name=value pairs.
     */
    private static function extraParams(string $text): ?array
    {
        if (trim($text) === '') {
            return [];
        }

        $pairs = [];

        foreach (explode('&', $text) as $pair) {
            if (preg_match('/^([A-Za-z0-9_.-]{1,60})=([^&]{0,120})$/', $pair, $match) !== 1) {
                return null;
            }

            $pairs[$match[1]] = $match[2];
        }

        return $pairs;
    }

    private static function endpointProblem(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'The URL is not valid.';
        }

        $scheme = strtolower($parts['scheme']);
        $allowHttp = (bool) config('communication.sms.allow_insecure_http', false);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $allowHttp)) {
            return 'Only https URLs are allowed.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL must not contain credentials.';
        }

        $host = strtolower($parts['host']);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_contains($host, '[')) {
            return 'Use a host name, not an IP address.';
        }

        if (! str_contains($host, '.') || preg_match('/(^|\.)(localhost|local|internal|lan|home)$/', $host) === 1) {
            return 'The host must be a public name.';
        }

        return null;
    }
}
