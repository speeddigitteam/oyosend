/** Values of App\Modules\Ai\Enums\AiDriver. */
export type AiDriver = 'anthropic' | 'openai_compatible';

export interface AiProviderAccount {
    id: string;
    driver: AiDriver;
    driverLabel: string;
    name: string;
    /** Chat model; empty for an account that only transcribes speech. */
    model: string;
    /** Speech-to-text model for voice input (ADR 0038), OpenAI-compatible accounts only. */
    transcriptionModel: string | null;
    baseUrl: string | null;
    enabled: boolean;
    isDefault: boolean;
    /** The account that transcribes voice input for every workspace. */
    isTranscriptionDefault: boolean;
    /** Whether a key is stored. The key itself is never sent to the browser. */
    hasKey: boolean;
    lastTest: { status: 'ok' | 'failed' | null; reason: string | null; at: string } | null;
}

export interface AiDriverDefinition {
    value: AiDriver;
    label: string;
    requiresKey: boolean;
    requiresBaseUrl: boolean;
    supportsTranscription: boolean;
}
