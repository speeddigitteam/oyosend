import { describe, expect, it } from 'vitest';
import { listAmountBeside, percentOff, yearlySaving } from '@/Core/Components/Billing/offer';

describe('a plan offer', () => {
    it('says the whole percent it takes off, rounded down so it never claims more than it gives', () => {
        expect(percentOff(450000, 300000)).toBe(33);
        expect(percentOff(144000, 120000)).toBe(16);
        expect(percentOff(450000, 450000)).toBe(0);
        expect(percentOff(450000, 500000)).toBe(0);
        expect(percentOff(null, 100)).toBe(0);
        expect(percentOff(0, 0)).toBe(0);
    });

    it('says what a yearly price saves against twelve months', () => {
        expect(yearlySaving(12000_00, 120000_00)).toBe(16);
        expect(yearlySaving(12000_00, 144000_00)).toBe(0);
        expect(yearlySaving(null, 120000_00)).toBe(0);
    });

    it('strikes through the plan\'s own price only while an offer runs below it', () => {
        const plan = { monthlyAmount: 300000, yearlyAmount: 4500000, listMonthlyAmount: 450000, listYearlyAmount: 4500000 };

        expect(listAmountBeside(plan, 'monthly')).toBe(450000);
        expect(listAmountBeside(plan, 'yearly')).toBeNull();
        expect(listAmountBeside({ monthlyAmount: 300000, yearlyAmount: null }, 'monthly')).toBeNull();
        expect(listAmountBeside({ ...plan, yearlyAmount: null }, 'yearly')).toBeNull();
    });
});
