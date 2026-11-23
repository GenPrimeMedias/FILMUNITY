/** Exact UI amounts. Server validation/posting remains authoritative. */
export type EntrySide = 'DR' | 'CR';
export type VoucherLine = { account_id: string; debit: string; credit: string };
export function toMinor(value: string): bigint {
 if (!/^\d{1,13}(?:\.\d{1,2})?$/.test(value)) throw new Error('Enter an amount with at most two decimal places.');
 const [whole, fraction = ''] = value.split('.');
 return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}
export function minorDecimal(value: bigint): string {
 const sign = value < 0n ? '-' : ''; const amount = value < 0n ? -value : value;
 return `${sign}${amount / 100n}.${(amount % 100n).toString().padStart(2, '0')}`;
}
export function formatMinor(value: bigint): string {
 const negative = value < 0n; const amount = negative ? -value : value;
 return `${negative ? '-' : ''}₹${new Intl.NumberFormat('en-IN').format(amount / 100n)}.${(amount % 100n).toString().padStart(2, '0')}`;
}
export function entryAmount(line: VoucherLine, side: EntrySide): string { return side === 'DR' ? line.debit : line.credit; }
export function changeSide(line: VoucherLine, previous: EntrySide, next: EntrySide): VoucherLine {
 const amount = entryAmount(line, previous);
 return { ...line, debit: next === 'DR' ? amount : '0.00', credit: next === 'CR' ? amount : '0.00' };
}
export function totals(lines: VoucherLine[]): { debit: bigint; credit: bigint; difference: bigint } | null {
 try { let debit = 0n; let credit = 0n; for (const line of lines) { debit += toMinor(line.debit); credit += toMinor(line.credit); } return { debit, credit, difference: debit - credit }; }
 catch { return null; }
}
