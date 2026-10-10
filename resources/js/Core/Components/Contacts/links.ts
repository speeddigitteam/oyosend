import { route } from '@/Core/lib/route';

/** The people list, narrowed to one company. */
export function peopleOfCompanyUrl(companyId: string): string {
    return route('core.contacts.index', { company: companyId });
}

export function mailHref(email: string | null): string | undefined {
    return email ? `mailto:${email}` : undefined;
}

/**
 * A `tel:` address for a phone number as it was typed, or nothing when the
 * text is more than one plain number ("ext. 12", two numbers, a remark):
 * dialling a guess is worse than leaving it as text.
 */
export function telHref(phone: string | null): string | undefined {
    const typed = (phone ?? '').trim();

    if (!/^\+?[\d\s().-]+$/.test(typed)) {
        return undefined;
    }

    const dialable = typed.replace(/[^\d+]/g, '');

    return dialable.replace('+', '').length >= 3 ? `tel:${dialable}` : undefined;
}

/** Selector of a row's actions button, to return keyboard focus to it after a dialog closes. */
export function actionsSelector(id: string | null | undefined): string | null {
    return id ? `[data-record-actions="${id}"]` : null;
}

/** The "Add" button of the page header. */
export const ADD_BUTTON = '[data-directory-add]';
