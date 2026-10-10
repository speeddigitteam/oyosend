/**
 * Prop shapes of the contact directory pages (people and companies), as the
 * controllers in `app/Core/Contacts/Http/Controllers` return them.
 */

/** Something picked by id: a company to put a person in, a member to hand a record to. */
export interface DirectoryOption {
    id: string;
    name: string;
}

/** What every record of the directory carries. */
interface DirectoryRecord {
    id: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    /** ISO 3166-1 alpha-2, upper case. */
    country: string | null;
    notes: string | null;
    /** The member the record belongs to; `id` is their membership, not their account. */
    owner: DirectoryOption | null;
    /** The viewer's permission reaches this record (decided by the server). */
    editable: boolean;
    deletable: boolean;
    createdAt: string;
}

export interface ContactRow extends DirectoryRecord {
    /** First and last name together, as shown. */
    name: string;
    firstName: string;
    lastName: string | null;
    jobTitle: string | null;
    company: DirectoryOption | null;
}

/** Why the server considers another contact a possible duplicate. */
export type ContactMatchReason = 'email' | 'phone';

/** A possible duplicate returned by the contact matching endpoint. */
export interface ContactDuplicateCandidate {
    id: string;
    name: string;
    firstName: string;
    lastName: string | null;
    email: string | null;
    phone: string | null;
    jobTitle: string | null;
    address: string | null;
    city: string | null;
    country: string | null;
    notes: string | null;
    company: DirectoryOption | null;
    owner: DirectoryOption | null;
    matchReasons: ContactMatchReason[];
    /** The viewer may manage this record and soft-delete it as part of a merge. */
    mergeable: boolean;
}

export type ContactMergeSource = 'canonical' | 'duplicate' | 'input';

export type ContactMergeField =
    | 'first_name'
    | 'last_name'
    | 'email'
    | 'phone'
    | 'job_title'
    | 'address'
    | 'city'
    | 'country'
    | 'notes'
    | 'company_id';

export type ContactMergeFieldSelection = {
    source: ContactMergeSource;
    value?: string | null;
};

export type ContactMergeFields = Partial<Record<ContactMergeField, ContactMergeFieldSelection>>;

export interface CompanyRow extends DirectoryRecord {
    name: string;
    website: string | null;
    industry: string | null;
    taxId: string | null;
    /** How many of its people the viewer can see. */
    contacts: number;
}

/** One line of a record's details. */
export interface DetailItem {
    key: string;
    label: string;
    /** Nothing recorded when null or empty. */
    value: string | null;
    /** Makes the value a link: `mailto:`, `tel:`, a website or a page of the workspace. */
    href?: string;
    /** `page` stays in the workspace, `external` opens a new tab, `contact` hands over to mail or phone. */
    link?: 'page' | 'external' | 'contact';
    /** Keeps the line breaks of an address or a note. */
    multiline?: boolean;
}
