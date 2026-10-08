/**
 * Messages for the `settings` pages. English is the source of truth for keys.
 */
export default {
    eyebrow: 'Your account',
    tabs: {
        label: 'Account settings',
        profile: 'Profile',
        security: 'Security',
    },
    profile: {
        title: 'Profile',
        description: 'Your name, and the language and numerals Oyosend uses for you.',
        about: {
            title: 'About you',
            description: 'Shown to the people you work with.',
        },
        name: 'Name',
        email: 'Email',
        emailHint: 'Email changes need a verification step that is not available yet.',
        regional: {
            title: 'Language and numerals',
            description: 'Saved to your account, so they follow you into every workspace you belong to.',
        },
        language: 'Interface language',
        numerals: {
            label: 'Numerals',
            latn: 'Latin digits',
            beng: 'Bengali digits',
        },
        preview: {
            title: 'Preview',
            number: 'A number',
            date: 'A date',
            note: 'The rest of the interface follows once you save.',
        },
        unsaved: 'You have unsaved changes.',
    },
    security: {
        title: 'Security',
        description: 'Your password, and the second step that protects your account.',
        lastSignIn: {
            title: 'Last sign-in',
            body: 'If that was not you, change your password.',
        },
        password: {
            title: 'Password',
            description: 'Changing it signs you out on your other devices.',
            current: 'Current password',
            new: 'New password',
            confirm: 'Confirm new password',
            submit: 'Update password',
        },
        twoFactor: {
            title: 'Two-step verification',
            description: 'A code from your phone, asked for after your password.',
            status: {
                on: 'On',
                off: 'Off',
                pending: 'Setup not finished',
            },
            off: {
                body: 'When it is on, signing in takes your password and a 6-digit code from an authenticator app on your phone. Someone who learns your password still cannot get in.',
                action: 'Turn on',
            },
            pending: {
                intro: 'Two steps to go. Two-step verification stays off until you finish them.',
                step: 'Step {number}',
                scan: 'Add Oyosend to your authenticator app',
                confirm: 'Enter the code the app shows',
                submit: 'Finish setup',
                cancel: 'Cancel setup',
            },
            on: {
                body: 'You are asked for a code from your authenticator app each time you sign in.',
                recoveryHeading: 'Recovery codes left',
                // For screen readers: the heading and the number shown beside it, as one phrase.
                recoveryCount: 'Recovery codes left: {count}',
                recoveryBody: 'Each one signs you in once if you cannot use your phone.',
                recoveryLow: 'Running low. Generate a new set before you need one.',
                recoveryNone: 'None left. Generate a new set, or losing your phone will lock you out.',
                regenerate: 'Generate new recovery codes',
                turnOffHeading: 'Turn off two-step verification',
                turnOffBody: 'Your password alone will be enough to sign in.',
                turnOff: 'Turn off',
            },
            // The password prompt in front of each change.
            prompt: {
                password: 'Current password',
                enable: {
                    title: 'Turn on two-step verification',
                    description: 'Enter your current password to start.',
                    confirm: 'Continue',
                },
                regenerate: {
                    title: 'Generate new recovery codes',
                    description: 'The codes you have now will stop working. Enter your current password to continue.',
                    confirm: 'Generate codes',
                },
                disable: {
                    title: 'Turn off two-step verification',
                    description: 'Signing in will need only your password. Enter it to confirm.',
                    confirm: 'Turn off',
                },
                cancel: {
                    title: 'Cancel setup',
                    description: 'The QR code and key on this page will stop working. Enter your current password to confirm.',
                    confirm: 'Cancel setup',
                },
            },
        },
    },
};
