/**
 * Messages for the branches and departments page. English is the source of
 * truth for keys.
 *
 * `branches` and `departments` have the same keys on purpose: the card that
 * lists either one reads `organization.{kind}.…`.
 *
 * Counts come in keys picked in code (`none`, `one`, `other`): vue-i18n's own
 * plural syntax needs the pipe character, which the catalogues avoid.
 */
export default {
    title: 'Branches & departments',
    description: 'Where the people of {workspace} work, and the teams they work in. A permission can be limited to a person’s own branch or department.',
    members: {
        none: 'No one yet',
        one: '{count} person',
        other: '{count} people',
    },
    viewOnly: 'You can see the branches and departments here, but not change them.',
    placeHint: 'To put someone in a branch or a department, open their menu on the Members page.',
    goToMembers: 'Go to members',
    form: {
        name: 'Name',
        code: 'Code',
        codeHint: 'Your own short reference, such as HQ.',
    },
    branches: {
        title: 'Branches',
        description: 'The places the company works from: offices, shops, warehouses.',
        add: 'Add branch',
        edit: 'Edit {name}',
        remove: 'Delete {name}',
        empty: {
            title: 'No branches yet',
            body: 'Add the places the company works from. People are put in them from the Members page.',
            bodyViewOnly: 'No branch has been added to this workspace.',
        },
        form: {
            createTitle: 'Add a branch',
            editTitle: 'Edit {name}',
            intro: 'An office, a shop, a warehouse: anywhere the company works from.',
            phone: 'Phone',
            address: 'Address',
        },
        delete: {
            title: 'Delete {name}?',
            body: {
                none: 'No one is in this branch. Records that belong to it stay, and simply no longer belong to a branch.',
                one: '{count} person is in this branch. They stay in the workspace and so do the records of the branch; they simply no longer belong to a branch.',
                other: '{count} people are in this branch. They stay in the workspace and so do the records of the branch; they simply no longer belong to a branch.',
            },
            access: 'Anyone who reaches records through this branch loses that access.',
            confirm: 'Delete branch',
        },
    },
    departments: {
        title: 'Departments',
        description: 'The teams of the company, such as Sales, Accounts or Support.',
        add: 'Add department',
        edit: 'Edit {name}',
        remove: 'Delete {name}',
        empty: {
            title: 'No departments yet',
            body: 'Add the teams of the company. People are put in them from the Members page.',
            bodyViewOnly: 'No department has been added to this workspace.',
        },
        form: {
            createTitle: 'Add a department',
            editTitle: 'Edit {name}',
            intro: 'A team of the company. It is not tied to a branch: a person has one branch and one department.',
            description: 'Description',
        },
        delete: {
            title: 'Delete {name}?',
            body: {
                none: 'No one is in this department. Records that belong to it stay, and simply no longer belong to a department.',
                one: '{count} person is in this department. They stay in the workspace and so do the records of the department; they simply no longer belong to a department.',
                other: '{count} people are in this department. They stay in the workspace and so do the records of the department; they simply no longer belong to a department.',
            },
            access: 'Anyone who reaches records through this department loses that access.',
            confirm: 'Delete department',
        },
    },
};
