<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Assistant (module `ai`)
    |--------------------------------------------------------------------------
    |
    | The credential is PLATFORM-level. The operator manages provider accounts
    | in the Super Admin panel (keys stored encrypted, write-only); the key
    | below is the fallback used only while no account exists. A key is never
    | taken from a tenant or from the request, and never logged or shown.
    | Without a credential the provider reports `not_configured` and nothing
    | is simulated.
    |
    | `default` is `anthropic` or `fake`. `fake` is honoured only in the
    | `local` and `testing` environments; anywhere else it is `not_configured`.
    |
    */

    'default' => env('AI_PROVIDER', 'anthropic'),

    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'version' => '2023-06-01',
            'model' => env('AI_ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'timeout' => (int) env('AI_TIMEOUT_SECONDS', 60),
        ],
    ],

    /*
    | Speech to text for voice input (ADR 0038). Used only while no provider
    | account in the Super Admin panel has a transcription model. Any server
    | with the OpenAI transcription API (`POST {base_url}/audio/transcriptions`),
    | including a self-hosted Whisper server, so audio need not leave servers
    | the operator chose. No endpoint or no model means `not_configured` and no
    | microphone is offered. `fake` is honoured only in `local` and `testing`.
    | Audio is held in memory for one request and never stored or logged.
    */
    'transcription' => [
        'driver' => env('AI_TRANSCRIPTION_PROVIDER', 'openai_compatible'),
        'base_url' => env('AI_TRANSCRIPTION_BASE_URL'),
        'api_key' => env('AI_TRANSCRIPTION_API_KEY'),
        'model' => env('AI_TRANSCRIPTION_MODEL'),
        'timeout' => (int) env('AI_TRANSCRIPTION_TIMEOUT_SECONDS', 30),
        // Longest clip the browser records and the server accepts.
        'max_seconds' => 30,
        // Largest upload accepted, in kilobytes (30 seconds of Opus is well under this).
        'max_kilobytes' => 2048,
        // What the fake transcriber "hears".
        'fake_text' => 'Contacts',
    ],

    /*
    | Cost guardrails. Every provider call is bounded before it is made.
    */
    'limits' => [
        // Output tokens a single provider call may produce.
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1024),
        // Provider calls (tool rounds) for one assistant turn.
        'max_tool_rounds' => 4,
        // Tokens (input + output) one assistant turn may spend across its rounds.
        'max_turn_tokens' => (int) env('AI_MAX_TURN_TOKENS', 20000),
        // Characters accepted from a person in one message / draft instruction / rewrite text.
        'max_input_chars' => 6000,
        // Characters of one tool result handed to the model.
        'tool_result_chars' => 6000,
        // Characters of one text field inside a tool result.
        'field_chars' => 1500,
        // Earlier messages of a conversation sent as context.
        'history_messages' => 20,
        // Requests per minute: whole workspace, and one member.
        'tenant_per_minute' => (int) env('AI_TENANT_RPM', 60),
        'member_per_minute' => (int) env('AI_MEMBER_RPM', 20),
    ],

    /*
    | Circuit breaker: after `failures` provider failures inside `window`
    | seconds, calls are refused for `open` seconds instead of piling up.
    */
    'circuit_breaker' => [
        'failures' => 5,
        'window' => 120,
        'open' => 60,
    ],

    /*
    | PII redaction. OFF by default for the platform; a workspace can switch
    | it on in its AI settings. It masks matches in everything sent to the
    | provider (messages and tool results). It is pattern based: a safeguard,
    | not a guarantee. `patterns` is name => regex; replacement is `[name]`.
    */
    'redaction' => [
        'enabled' => (bool) env('AI_REDACTION_DEFAULT', false),
        'patterns' => [
            'email' => '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u',
            'phone' => '/(?<![\w])\+?\d[\d\-\s().]{7,}\d/u',
            'card' => '/\b(?:\d[ \-]?){13,19}\b/u',
        ],
    ],

    /*
    | Conversation retention in days (workspace setting within these bounds).
    */
    'retention' => [
        'default_days' => 90,
        'min_days' => 1,
        'max_days' => 365,
    ],

];
