import { ProtectedFields, WorkspaceEditProtection } from '@/components/workspace-edit-protection';
import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
type Account = { id: string; code: string; name: string };
export function QuickLedgerDialog({ open, base, onClose, onCreated }: { open: boolean; base: string; onClose: () => void; onCreated: (id: string) => void }) {
 const form = useForm({ code: '', name: '', type: 'expense', is_money: false });
 return <Dialog open={open} onOpenChange={(value) => { if (!value) onClose(); }}><DialogContent className="sm:max-w-xl"><DialogHeader><DialogTitle>Create ledger / account</DialogTitle><DialogDescription>Your voucher stays open with its existing values. Create the account, then continue on the same entry line.</DialogDescription></DialogHeader>
  <WorkspaceEditProtection association={base.split('/')[2]} resource="masters" dirty={form.isDirty} onDiscard={() => { form.reset(); }}><ProtectedFields><form className="grid gap-4 rounded-xl border border-t-4 border-t-primary p-4" onSubmit={(event) => { event.preventDefault(); const code = form.data.code; form.post(`${base}/account`, { preserveScroll: true, preserveState: true, onSuccess: (page) => { const accounts = page.props.accounts as Account[] | undefined; const account = accounts?.find((item) => item.code === code); if (account) { onCreated(account.id); form.reset(); } else { form.setError('name', 'Account saved, but the refreshed account list was unavailable. Close this window and select it from the voucher list.'); } } }); }}>
   <label className="grid gap-1 text-sm">Unique code<input autoFocus className="rounded-lg border bg-background px-3 py-2" required maxLength={20} pattern="[A-Z0-9_-]{1,20}" value={form.data.code} onChange={(event) => form.setData('code', event.target.value.toUpperCase())} /></label>
   <label className="grid gap-1 text-sm">Ledger name<input className="rounded-lg border bg-background px-3 py-2" required maxLength={120} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></label>
   <label className="grid gap-1 text-sm">Classification<select className="rounded-lg border bg-background px-3 py-2" value={form.data.type} onChange={(event) => form.setData({ ...form.data, type: event.target.value, is_money: false })}>{['asset', 'liability', 'fund', 'income', 'expense'].map((type) => <option key={type}>{type}</option>)}</select></label>
   {form.data.type === 'asset' && <label className="flex gap-2 text-sm"><input type="checkbox" checked={form.data.is_money} onChange={(event) => form.setData('is_money', event.target.checked)} />This is a cash or bank account</label>}
   <p className="text-xs text-muted-foreground">Classification cannot be edited later in the current account master. Check the appropriate category before saving.</p>
   {Object.entries(form.errors).map(([key, value]) => <p key={key} role="alert" className="text-sm text-destructive">{value}</p>)}
   <div className="flex justify-end gap-2"><Button type="button" data-workspace-discard variant="outline" onClick={onClose}>Back to voucher</Button><Button disabled={form.processing}>{form.processing ? 'Creating…' : 'Create and select'}</Button></div>
  </form></ProtectedFields></WorkspaceEditProtection>
 </DialogContent></Dialog>;
}
