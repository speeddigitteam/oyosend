/**
 * Messages for the platform's audit log in the Super Admin panel. The words
 * for an entry's details (`audit.details`, `audit.fields`) are shared with a
 * workspace's audit log. English is the source of truth for keys.
 */
export default {
    description:
        'What happened at platform level, and everything an operator did inside a workspace. What a workspace’s own people do stays in its own audit log. Entries cannot be edited or deleted.',
    caption: 'Platform activity, newest first',
    order: 'Newest first',
    orderFiltered: 'Search results, newest first',
    search: 'Search the audit log',
    searchPlaceholder: 'An email, a record or an event name',
    searchHint: 'An event is found by the name printed under each activity, such as plan.updated.',
    columns: {
        time: 'Time',
        event: 'Activity',
        actor: 'By',
        subject: 'Affected',
        workspace: 'Workspace',
        ip: 'IP address',
    },
    // In the Workspace column of an entry that belongs to no workspace.
    platformLevel: 'Platform',
    empty: {
        title: 'No activity yet',
        body: 'Sign-ins to this panel, changes to plans and settings, and what operators do inside workspaces are recorded here as they happen.',
        filteredTitle: 'Nothing matches “{query}”',
        filteredBody: 'Try an email address, or the name of the event, such as platform.workspace.suspended.',
    },
};
