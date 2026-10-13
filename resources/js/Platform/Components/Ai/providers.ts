/** Values of App\Modules\Ai\Enums\AiDriver. */
export type AiDriver = 'anthropic' | 'openai_compatible';

export interface AiProviderAccount {
    id: string;
    driver: AiDriver;
    driverLabel: string;
    name: string;
    model: string;
    baseUrl: string | null;
    enabled: boolean;
    isDefault: boolean;
    /** Whether a key is stored. The key itself is never sent to the browser. */
    hasKey: boolean;
    lastTest: { status: 'ok' | 'failed' | null; reason: string | null; at: string } | null;
}

export interface AiDriverDefinition {
    value: AiDriver;
    label: string;
    requiresKey: boolean;
    requiresBaseUrl: boolean;
}
