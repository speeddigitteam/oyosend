/**
 * Messages for the AI providers page of the Super Admin panel. English is the source of truth for keys.
 */
export default {
    description: 'The AI providers the AI Assistant uses for every workspace. One enabled provider is the default.',
    secretsNote: 'API keys are stored encrypted and are never shown again. Leave the key empty when editing to keep the stored one.',
    envNote: 'No provider has been added, so the key in the server configuration (if any) is used.',
    add: 'Add provider',
    default: 'Default',
    enabled: 'Enabled',
    disabled: 'Disabled',
    keySet: 'Key stored',
    keyMissing: 'No key',
    makeDefault: 'Make default',
    list: {
        title: 'Providers',
        description: 'Add several providers and choose which one answers.',
    },
    empty: {
        title: 'No provider added yet',
        envConfigured: 'The key from the server configuration is in use. Add a provider here to manage it from the panel.',
        envMissing: 'The AI Assistant is not configured. Add a provider to turn it on.',
    },
    drivers: {
        anthropic: 'Anthropic',
        openai_compatible: 'OpenAI-compatible',
    },
    dialog: {
        addTitle: 'Add AI provider',
        editTitle: 'Edit AI provider',
        description: 'The key is encrypted before it is stored and cannot be read back.',
        driver: 'Provider type',
        name: 'Name',
        model: 'Model',
        modelHint: 'The model id exactly as the provider names it.',
        baseUrl: 'Endpoint URL',
        baseUrlRequired: 'Include the version prefix, for example https://api.openai.com/v1.',
        baseUrlOptional: 'Leave empty to use the provider default.',
        apiKey: 'API key',
        keyRequired: 'Required.',
        keyOptional: 'Optional for servers that need no key.',
        keyStored: 'A key is stored. Leave empty to keep it.',
        enabled: 'Enabled',
        enabledHint: 'A disabled provider is never used and cannot be the default.',
    },
    test: {
        action: 'Test connection',
        ok: 'Last test passed on {when}.',
        failed: 'Last test failed on {when} ({code}).',
    },
    remove: {
        title: 'Remove this provider?',
        description: '{name} will be deleted together with its stored key. If it is the default, the AI Assistant stops answering until another is chosen.',
    },
};
