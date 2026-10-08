export default {
    403: {
        title: 'You do not have access to this',
        body: 'Your role in this workspace does not include it. Ask a workspace admin if you need it.',
    },
    404: {
        title: 'We could not find that page',
        body: 'The link may be out of date, or the page may have been moved.',
    },
    429: {
        title: 'Too many requests',
        body: 'Give it a minute, then try again.',
    },
    500: {
        title: 'Something went wrong on our side',
        body: 'It has been logged. Please try again in a moment.',
    },
    503: {
        title: 'Back shortly',
        body: 'We are doing some maintenance. Please check back soon.',
    },
    back: 'Go back',
    home: 'Go to the start page',
    workspace: {
        suspended: {
            title: '{workspace} is suspended',
            body: 'Access is paused. Your data is safe. The workspace owner can contact support to restore it.',
        },
        pending: {
            title: '{workspace} is not ready yet',
            body: 'This workspace is still being set up. Please check back soon.',
        },
    },
};
