import type { AddressRow, AddressType } from './types';

type Translate = (key: string, params?: Record<string, unknown>) => string;

/** The types in the order a company's or a contact's addresses are listed. */
export const ADDRESS_TYPES: readonly AddressType[] = ['invoice', 'delivery', 'other'];

/** What an address is called on its card: its label, or else what it is for. */
export function addressTitle(address: AddressRow, t: Translate): string {
    return address.label?.trim() || t(`contacts.addresses.types.${address.type}`);
}

/** City and postal code on one line, as an envelope has them. */
export function cityLine(address: AddressRow): string {
    return [address.city, address.postalCode].filter((part): part is string => Boolean(part)).join(' ');
}

/** The addresses of each type that has any, in listing order; the server already sorted them within a type. */
export function groupAddresses(addresses: AddressRow[]): Array<{ type: AddressType; addresses: AddressRow[] }> {
    return ADDRESS_TYPES.map((type) => ({ type, addresses: addresses.filter((address) => address.type === type) })).filter(
        (group) => group.addresses.length > 0,
    );
}
