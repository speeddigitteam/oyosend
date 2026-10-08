/**
 * Messages for the `audit` pages. English is the source of truth for keys.
 */
export default {
    title: 'Audit log',
    description: 'A record of who did what in {workspace}. Entries cannot be edited or deleted.',
    caption: 'Activity in this workspace, newest first',
    order: 'Newest first',
    orderFiltered: 'Filtered, newest first',
    filters: {
        label: 'Filter the audit log',
        search: 'Search',
        searchPlaceholder: 'A person or a record',
        family: 'Activity',
        allFamilies: 'All activity',
        from: 'From',
        to: 'To',
        apply: 'Apply',
        clear: 'Clear filters',
    },
    columns: {
        time: 'Time',
        event: 'Activity',
        actor: 'By',
        subject: 'Affected',
        ip: 'IP address',
    },
    details: {
        toggle: 'Details: {event}',
        // Label of a change the server recorded without naming a field.
        change: 'Change',
        value: 'Details',
        becomes: 'changed to',
        none: 'none',
        redacted: 'hidden',
        yes: 'Yes',
        no: 'No',
    },
    // Names of the fields that show up in entry details. Anything not listed
    // here is shown by its own name with the underscores taken out.
    fields: {
        name: 'Name',
        description: 'Description',
        email: 'Email',
        status: 'Status',
        role: 'Role',
        role_id: 'Role ID',
        user_id: 'Person ID',
        tenant_id: 'Workspace ID',
        guard_name: 'Guard',
        is_owner: 'Workspace owner',
        is_system: 'System role',
        joined_at: 'Joined',
        expires_at: 'Expires',
        invited_by: 'Invited by',
        locale: 'Language',
        timezone: 'Timezone',
        added: 'Added',
        removed: 'Removed',
    },
    empty: {
        title: 'No activity yet',
        body: 'Sign-ins, invitations, role changes and other actions in this workspace are recorded here as they happen.',
        filteredTitle: 'Nothing matches these filters',
        filteredBody: 'Try a wider date range, or search for someone else.',
    },
};
