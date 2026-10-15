import { countryName } from '@/Core/lib/countries';
import { mailHref, peopleOfCompanyUrl, telHref } from './links';
import type { ContactRow, DetailItem } from './types';

type Translate = (key: string, params?: Record<string, unknown>) => string;

/** Everything recorded about one person, read only: what a contact's details dialog shows. */
export function contactDetails(contact: ContactRow, t: Translate, locale: string, date: (value: string) => string): DetailItem[] {
    return [
        { key: 'jobTitle', label: t('contacts.fields.jobTitle'), value: contact.jobTitle },
        {
            key: 'company',
            label: t('contacts.fields.company'),
            value: contact.company?.name ?? null,
            href: contact.company ? peopleOfCompanyUrl(contact.company.id) : undefined,
            link: 'page',
        },
        { key: 'email', label: t('contacts.fields.email'), value: contact.email, href: mailHref(contact.email), link: 'contact' },
        { key: 'phone', label: t('contacts.fields.phone'), value: contact.phone, href: telHref(contact.phone), link: 'contact' },
        { key: 'address', label: t('contacts.fields.address'), value: contact.address, multiline: true },
        { key: 'city', label: t('contacts.fields.city'), value: contact.city },
        { key: 'country', label: t('contacts.fields.country'), value: countryName(contact.country, locale) },
        { key: 'notes', label: t('contacts.fields.notes'), value: contact.notes, multiline: true },
        { key: 'owner', label: t('contacts.fields.owner'), value: contact.owner?.name ?? t('contacts.list.noOwner') },
        { key: 'added', label: t('contacts.fields.added'), value: date(contact.createdAt) },
    ];
}
