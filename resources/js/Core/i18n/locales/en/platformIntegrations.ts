/**
 * Messages for the integrations page of the Super Admin panel: the Social platform apps and the
 * Meta Ads app credentials. English is the source of truth for keys.
 */
export default {
    description: 'App credentials for the Social and Meta Ads integrations, set here instead of in the server environment.',
    title: 'Integration credentials',
    intro:
        'Enter an app’s secret here and it is stored encrypted; it is never shown again or logged. A public app ID is shown back so it can match the OAuth redirect URI. An integration with nothing here falls back to the server environment.',
    // Where an integration's credentials come from.
    source: {
        panel: 'Credentials set in this panel',
        environment: 'Using the server environment',
        none: 'No credentials, so it is not available',
        switch: 'Uses the Meta app above; only this switch is set here',
    },
    // Credential field labels.
    fields: {
        client_id: 'App ID / client ID',
        client_key: 'Client key',
        client_secret: 'App secret / client secret',
        webhook_verify_token: 'Webhook verify token',
        app_id: 'App ID',
        app_secret: 'App secret',
    },
    // Non-secret on/off switches, keyed by field.
    flags: {
        publishing: {
            label: 'Instagram publishing enabled',
            hint: 'Turn on only after Meta has approved instagram_basic and instagram_content_publish for the Facebook app. Until then Instagram shows as not available.',
        },
    },
    publicHint: 'Shown back to you so it can match the OAuth redirect URI. Not a secret.',
    secretStored: 'Stored — leave blank to keep',
    enabled: 'Use these panel credentials',
    save: 'Save credentials',
    clear: 'Clear',
    none: 'No integrations in this build',
    noneBody: 'Nothing to configure here yet.',
    // Keyed by group then name.
    integrations: {
        social: {
            facebook: {
                name: 'Facebook Pages',
                description: 'The Meta app for Facebook Pages publishing and Lead Ads.',
            },
            linkedin: {
                name: 'LinkedIn',
                description: 'The LinkedIn app for company page publishing.',
            },
            x: {
                name: 'X',
                description: 'The X app for posting.',
            },
            instagram: {
                name: 'Instagram',
                description: 'Publishing to Instagram professional accounts. It uses the Facebook app above, so it has no credentials of its own.',
            },
            tiktok: {
                name: 'TikTok',
                description: 'The TikTok app for the Content Posting API.',
            },
            youtube: {
                name: 'YouTube',
                description: 'The Google OAuth client for YouTube video uploads.',
            },
            pinterest: {
                name: 'Pinterest',
                description: 'The Pinterest app for creating Pins.',
            },
        },
        meta_ads: {
            app: {
                name: 'Meta Ads',
                description: 'The Meta app for the Marketing API: ad accounts, campaigns and insights.',
            },
        },
    },
};
