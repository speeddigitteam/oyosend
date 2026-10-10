/**
 * Messages for the plan pages of the Super Admin panel. The words for
 * intervals, "Free", "Unlimited" and "Not offered" come from `billing.terms`.
 * English is the source of truth for keys.
 */
export default {
    index: {
        description:
            'What a workspace can subscribe to: a price, the modules it includes and the limits it sets. A plan is never deleted. Archive it, and the workspaces on it keep it.',
        caption: 'Plans, active ones first',
        create: 'Create plan',
        columns: {
            plan: 'Plan',
            monthly: 'Per month',
            yearly: 'Per year',
            trial: 'Trial',
            subscribers: 'Workspaces',
            visibility: 'Offered',
            state: 'State',
            actions: 'Actions',
        },
        // Plural forms are separate keys: vue-i18n's own plural syntax uses a character messages must avoid.
        total: '{count} plans',
        totalOne: '{count} plan',
        trialDays: '{count} days',
        trialDay: '{count} day',
        noTrial: 'None',
        public: 'Public',
        publicHint: 'Workspaces can choose it themselves',
        hidden: 'Hidden',
        hiddenHint: 'Only an operator can give it to a workspace',
        active: 'Active',
        archived: 'Archived',
        edit: 'Edit',
        editPlan: 'Edit {name}',
        viewOnly: 'You can look at the plans, but your role cannot change them.',
        empty: {
            title: 'No plans yet',
            body: 'Workspaces have nothing to subscribe to until a plan exists.',
        },
    },
    editor: {
        back: 'All plans',
        createTitle: 'Create plan',
        createDescription: 'What the plan costs, what it includes and what it caps.',
        editTitle: 'Edit {name}',
        editDescription: 'Workspaces already on this plan keep the price they took it at. Changes to its modules and limits reach them at once.',
        subscribers: '{count} workspaces are on this plan.',
        subscribersOne: '{count} workspace is on this plan.',
        details: {
            title: 'Details',
            description: 'How the plan is called, here and where workspaces choose one.',
        },
        key: 'Key',
        keyHint: 'Lower-case letters, digits, hyphens and underscores, starting with a letter.',
        keyHintEdit: 'The audit log names the plan by its key. Change it only to correct a mistake.',
        name: 'Name',
        description: 'Description',
        descriptionHint: 'One line, shown with the plan where workspaces choose one.',
        pricing: {
            title: 'Price',
            description:
                'Write an amount the way you would on paper, such as 1500 or 1500.50. Leave an interval empty when the plan is not sold that way. 0 makes it free.',
        },
        currency: 'Currency',
        monthly: 'Price per month',
        yearly: 'Price per year',
        // Under a price field: what the entry comes to.
        shownAs: 'Shown as {amount}',
        notSold: 'Not sold for this interval',
        amountInvalid: 'Enter an amount such as 1500 or 1500.50, with at most {digits} decimal places.',
        amountInvalidWhole: 'Enter a whole amount, such as 1500. This currency has no smaller unit.',
        amountTooLarge: 'This amount is too large.',
        needsPrice: 'Give the plan a price for at least one interval. 0 makes it free.',
        trialDays: 'Free trial',
        trialDaysHint: 'In days, up to 365. 0 means no trial.',
        modules: {
            title: 'Included modules',
            description: 'Optional apps a workspace on this plan can use. The core features belong to every workspace.',
            none: 'This build has no optional modules yet, so there is nothing to include.',
        },
        limits: {
            title: 'Limits',
            description: 'The most a workspace on this plan may have or use. Leave a limit empty for unlimited.',
            none: 'This build defines nothing a plan can limit.',
            // Keyed by LimitKind.
            kind: {
                gauge: 'Counted from what exists now',
                metered: 'Counted per billing period',
            },
        },
        availability: {
            title: 'Availability',
            description: 'Who can take the plan, and where it is listed.',
        },
        isPublic: 'Public',
        isPublicDescription: 'Workspaces can choose this plan themselves. A plan that is not public can only be given by an operator.',
        isActive: 'Active',
        isActiveDescription: 'Switch this off to archive the plan: it is no longer offered, and the workspaces already on it keep it.',
        sortOrder: 'Sort order',
        sortOrderHint: 'Lower numbers are listed first.',
        create: 'Create plan',
    },
};
