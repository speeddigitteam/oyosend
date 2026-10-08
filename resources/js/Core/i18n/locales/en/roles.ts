/**
 * Messages for the `roles` pages. English is the source of truth for keys.
 *
 * Counts come in two keys, `one` and `other`, picked in code: vue-i18n's own
 * plural syntax needs the pipe character, which the catalogues avoid.
 */
export default {
    index: {
        title: 'Roles',
        description: 'A role is a named set of permissions. Everyone in {workspace} holds exactly one.',
        create: 'New role',
        columns: {
            role: 'Role',
            people: 'People',
            permissions: 'Permissions',
            actions: 'Actions',
        },
        system: 'System',
        granted: '{count} of {total}',
        grantedAll: 'All {total}',
        edit: 'Edit',
        editRole: 'Edit {name}',
        view: 'View',
        viewRole: 'View {name}',
        delete: 'Delete {name}',
        systemNote: 'System roles come with every workspace. They keep their names and cannot be deleted.',
        viewOnly: 'You can see the roles here, but not change them.',
        empty: 'No roles yet',
    },
    delete: {
        title: 'Delete {name}?',
        body: 'No one holds this role, so nobody loses access. A deleted role cannot be brought back.',
        confirm: 'Delete role',
        inUseTitle: '{name} is still in use',
        inUse: {
            one: '{count} person holds this role. Give them another role on the Members page, then delete it.',
            other: '{count} people hold this role. Give them another role on the Members page, then delete it.',
        },
        goToMembers: 'Go to members',
    },
    editor: {
        eyebrow: 'Roles',
        createTitle: 'New role',
        createDescription: 'Name the role, then choose what the people who hold it can do.',
        editTitle: '{name} role',
        editDescription: 'Changes apply to everyone who holds this role as soon as you save.',
        back: 'All roles',
        details: 'Details',
        name: 'Name',
        description: 'Description',
        descriptionHint: 'A line about who this role is for.',
        systemName: 'System roles keep their name.',
        owner: {
            title: 'The Owner role cannot be changed',
            body: 'The owner always holds every permission in the workspace, including those of apps added later. The list below is for reference.',
        },
        permissions: 'Permissions',
        permissionsHelp: 'Tick what people with this role may do. Anything left unticked stays out of reach for them.',
        locked: {
            one: 'You can only grant permissions you hold yourself. {count} permission below is locked: you can neither add it to this role nor take it away.',
            other: 'You can only grant permissions you hold yourself. {count} permissions below are locked: you can neither add them to this role nor take them away.',
        },
        lockedItem: 'You do not hold this, so you cannot change it',
        sensitive: 'Sensitive',
        granted: 'Granted',
        selectAll: 'select all',
        groupCount: '{count} of {total}',
        moduleCount: '{count} of {total} selected',
        emptyCatalogue: 'There are no permissions to assign yet',
        summary: {
            selected: 'of {total} permissions selected',
            ownerAll: 'permissions, all of them',
            added: '{count} added',
            removed: '{count} removed',
            sensitive: '{count} sensitive',
            unsaved: 'Details changed',
        },
        create: 'Create role',
    },
};
