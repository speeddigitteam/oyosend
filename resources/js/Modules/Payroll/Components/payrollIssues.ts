import type { RunIssue } from './types';

/** The codes the server writes to a run's `errors` (they block submission). */
export const ERROR_CODES = ['no_salary_assignment', 'currency_mismatch', 'negative_net', 'unknown_rule_engine', 'amount_out_of_range'] as const;

/** The codes the server writes to a run's or payslip's `warnings` (informational). */
export const WARNING_CODES = [
    'no_salary_assignment',
    'items_not_applied',
    'partial_period',
    'overtime_capped',
    'installment_deferred',
    'open_attendance_days',
    'incomplete_days',
] as const;

export interface IssueFormatters {
    number: (value: number) => string;
    money: (amount: number) => string;
}

/**
 * The translation key and parameters of one issue. Only the figures a message
 * mentions are passed on, already formatted in the person's numerals. A code the
 * interface does not know falls back to a generic message that names the code.
 */
export function issueMessage(
    kind: 'errors' | 'warnings',
    issue: RunIssue,
    formatters: IssueFormatters,
): { key: string; params: Record<string, string> } {
    const known: readonly string[] = kind === 'errors' ? ERROR_CODES : WARNING_CODES;
    const num = (value: unknown): string => (typeof value === 'number' ? formatters.number(value) : '');
    const params: Record<string, string> = {
        employee: typeof issue.employee_name === 'string' ? issue.employee_name : '',
        count: num(issue.count),
        days: num(issue.days),
        payable: num(issue.payable_days),
        period: num(issue.period_days),
        minutes: num(issue.minutes),
        cap: num(issue.cap),
        amount: typeof issue.amount === 'number' ? formatters.money(issue.amount) : '',
    };

    if (!known.includes(issue.code)) {
        return { key: 'payroll.codes.unknown', params: { ...params, code: issue.code } };
    }

    return { key: `payroll.codes.${kind}.${issue.code}`, params };
}
