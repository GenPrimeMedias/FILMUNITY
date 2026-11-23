import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { AccountingKind } from '@/hooks/use-accounting-hotkeys';
const entries: { key: string; title: string; kind: AccountingKind; hint: string }[] = [
 { key: 'F4', title: 'Contra', kind: 'transfer', hint: 'Transfer between cash and bank' },
 { key: 'F5', title: 'Payment', kind: 'payment', hint: 'Pay an expense, asset or liability' },
 { key: 'F6', title: 'Receipt', kind: 'receipt', hint: 'Record money received' },
 { key: 'F7', title: 'Journal', kind: 'journal', hint: 'Adjust or allocate accounts' },
 { key: 'F8', title: 'Member invoice', kind: 'invoice', hint: 'Record member dues with a due date' },
 { key: 'F9', title: 'Vendor bill', kind: 'bill', hint: 'Record a supplier payable' },
];
export function AccountingGateway({ openVoucher, navigate, lockedThrough }: { openVoucher: (kind: AccountingKind) => void; navigate: (tab: string) => void; lockedThrough: string | null }) {
 return <section className="grid gap-5 rounded-xl border border-t-4 border-t-[#b9d66b] bg-card p-5 text-card-foreground">
  <header className="flex flex-wrap justify-between gap-3"><div><h2 className="text-lg font-semibold">Accounting Gateway</h2><p className="mt-1 text-sm text-muted-foreground">Familiar voucher entry, using your existing FILMUNITY books.</p></div><Button variant="outline" asChild><Link href="/association-help/finance?topic=start" target="_blank" rel="noopener noreferrer">Accounting help</Link></Button></header>
  <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{entries.map((item) => <button key={item.key} className="grid gap-2 rounded-lg border p-4 text-left transition-colors hover:border-primary hover:bg-primary/10 focus-visible:outline-2 focus-visible:outline-primary" onClick={() => openVoucher(item.kind)} aria-keyshortcuts={item.key}><span className="flex items-center justify-between gap-2 font-semibold">{item.title}<kbd className="rounded border bg-background px-2 py-1 text-xs">{item.key}</kbd></span><span className="text-sm text-muted-foreground">{item.hint}</span></button>)}</div>
  <nav className="flex flex-wrap gap-2" aria-label="Accounting quick navigation">{[['vouchers', 'Day-to-day vouchers'], ['accounts', 'Ledgers / accounts'], ['ledger', 'Ledger statement'], ['reports', 'Financial reports'], ['settings', 'Book settings']].map(([tab, title]) => <Button key={tab} variant="outline" onClick={() => navigate(tab)}>{title}</Button>)}</nav>
  <p className="text-xs text-muted-foreground">{lockedThrough ? `Books are locked through ${lockedThrough}.` : 'Use the configured books start date.'} Saving creates a draft; submission and posting still require the existing review actions. F8/F9 open the invoice/bill screen. Use Invoices and settlements for allocations and advances; tax automation is a later phase.</p>
 </section>;
}
