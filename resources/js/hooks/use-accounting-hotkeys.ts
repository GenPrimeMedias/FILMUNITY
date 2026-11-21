import { useEffect, useRef } from 'react';
import type { RefObject } from 'react';
export type AccountingKind = 'transfer' | 'payment' | 'receipt' | 'journal' | 'invoice' | 'bill';
export const voucherShortcuts: Record<string, AccountingKind> = { F4: 'transfer', F5: 'payment', F6: 'receipt', F7: 'journal', F8: 'invoice', F9: 'bill' };
type Options = {
 enabled: boolean; voucherOpen: boolean; processing: boolean;
 formRef: RefObject<HTMLFormElement | null>;
 openVoucher: (kind: AccountingKind) => void;
 createLedger: (line: number) => void;
};
export function useAccountingHotkeys(options: Options) {
 const current = useRef(options);
 useEffect(() => { current.current = options; });
 useEffect(() => {
  const handle = (event: KeyboardEvent) => {
   const state = current.current;
   if (!state.enabled || state.processing || event.defaultPrevented || event.isComposing || event.repeat) return;
   const element = event.target instanceof HTMLElement ? event.target : null;
   if (!element) return;
   if (!event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey && voucherShortcuts[event.key]) {
    event.preventDefault(); state.openVoucher(voucherShortcuts[event.key]); return;
   }
   const form = state.formRef.current;
   if (!state.voucherOpen || !form || !form.contains(element)) return;
   if (event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'a') {
    event.preventDefault(); form.requestSubmit(); return;
   }
   const picker = element.closest<HTMLElement>('[data-accounting-ledger]');
   if (event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.key.toLowerCase() === 'c' && picker) {
    const row = Number(picker.dataset.accountingLedger);
    if (Number.isSafeInteger(row) && row >= 0) { event.preventDefault(); state.createLedger(row); } return;
   }
   if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey) return;
   if (!element.matches('[data-accounting-order]') || element.getAttribute('aria-expanded') === 'true' || element.tagName === 'BUTTON') return;
   const fields = Array.from(form.querySelectorAll<HTMLElement>('[data-accounting-order]')).filter((field) => !field.matches(':disabled,[readonly],[aria-hidden="true"]') && field.getClientRects().length > 0).sort((a, b) => Number(a.dataset.accountingOrder) - Number(b.dataset.accountingOrder));
   const index = fields.indexOf(element);
   if (index >= 0 && index < fields.length - 1) { event.preventDefault(); fields[index + 1].focus(); }
  };
  window.addEventListener('keydown', handle);
  return () => window.removeEventListener('keydown', handle);
 }, []);
}
