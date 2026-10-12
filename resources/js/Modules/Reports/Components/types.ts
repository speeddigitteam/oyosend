export type ColumnType = 'text' | 'integer' | 'money' | 'basis_points' | 'hundredths' | 'minutes' | 'date' | 'datetime';
export type FilterType = 'select' | 'text' | 'currency' | 'id' | 'integer' | 'boolean';
export type Visibility = 'private' | 'role' | 'department';
export type RangeMode = 'last_days' | 'fixed';

/** One cell as the server sends it: raw. Money is integer minor units, datetimes are UTC. */
export type Cell = string | number | boolean | null | undefined;
export type Row = Record<string, Cell>;

export interface ReportColumn {
    key: string;
    label: string;
    type: ColumnType;
    currencyColumn: string | null;
}

export interface ReportFilter {
    key: string;
    label: string;
    type: FilterType;
    options: { value: string; label: string }[];
    min: number | null;
    max: number | null;
}

export interface ReportDefinition {
    key: string;
    module: string;
    label: string;
    description: string;
    usesDateRange: boolean;
    columns: ReportColumn[];
    defaultColumns: string[];
    filters: ReportFilter[];
}

export interface ReportResult {
    columns: ReportColumn[];
    rows: Row[];
    page: { currentPage: number; lastPage: number; perPage: number; total: number };
    truncated: boolean;
    rowLimit: number;
    scope: 'own' | 'department' | 'branch' | 'all';
    from: string;
    to: string;
}

export interface RunValues {
    from: string;
    to: string;
    columns: string[];
    filters: Record<string, string | number | boolean>;
}

export interface SavedRow {
    id: string;
    name: string;
    providerKey: string;
    providerLabel: string | null;
    available: boolean;
    columns: string[];
    filters: Record<string, string | number | boolean>;
    rangeMode: RangeMode;
    rangeDays: number;
    rangeFrom: string | null;
    rangeTo: string | null;
    visibility: Visibility;
    roleId: string | null;
    departmentId: string | null;
    ownerName: string | null;
    isOwner: boolean;
    canManage: boolean;
    updatedAt: string | null;
}

export interface CatalogueEntry {
    key: string;
    label: string;
    description: string;
    usesDateRange: boolean;
}

export interface CatalogueGroup {
    module: string;
    reports: CatalogueEntry[];
}

export interface Bounds {
    maxDays: number;
    defaultDays: number;
    rowLimit: number;
}

export interface ShareOptions {
    roles: { id: string; name: string }[];
    departments: { id: string; name: string }[];
}

export interface SavedLimit {
    used: number;
    max: number | null;
}
