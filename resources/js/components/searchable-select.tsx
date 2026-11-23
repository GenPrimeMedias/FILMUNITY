import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type InputHTMLAttributes, type KeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown, X } from 'lucide-react';
import { cn } from '@/lib/utils';

export type SearchOption = { value: string; label: string; keywords?: string; disabled?: boolean };
type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'defaultValue' | 'onChange' | 'children'> & {
 value: string; onValueChange: (value: string) => void; options: SearchOption[];
 searchPlaceholder?: string; clearable?: boolean; emptyLabel?: string;
};
const normalize = (value: string) => value.normalize('NFKC').toLocaleLowerCase().trim();
export function matchSearchOptions(options: SearchOption[], query: string): SearchOption[] {
 const terms = normalize(query).split(/\s+/u).filter(Boolean);
 return options.filter((option) => { const haystack = normalize(`${option.label} ${option.keywords ?? ''}`); return terms.every((term) => haystack.includes(term)); });
}
/** Search changes only the menu query. The submitted value remains an explicit option ID. */
export function SearchableSelect({ value, onValueChange, options, placeholder = 'Choose an option', searchPlaceholder = 'Search by code or name…', clearable = true, emptyLabel = 'No matching options', className, style, required, disabled, name, id, onKeyDown, onFocus, onBlur, ...attributes }: Props) {
 const generated = useId(); const listId = `${generated}-list`;
 const [open, setOpen] = useState(false); const [query, setQuery] = useState(''); const [active, setActive] = useState(0);
 const [portal, setPortal] = useState<HTMLElement | null>(null);
 const [position, setPosition] = useState<{ left: number; top: number; width: number; maxHeight: number; fixed: boolean } | null>(null);
 const root = useRef<HTMLDivElement>(null); const field = useRef<HTMLInputElement>(null); const menu = useRef<HTMLDivElement>(null);
 const selected = options.find((option) => option.value === value);
 const matches = useMemo(() => matchSearchOptions(options, query), [options, query]);
 const shown = matches.slice(0, 100);
 const choose = (option: SearchOption) => { if (disabled || option.disabled) return; onValueChange(option.value); setOpen(false); setQuery(''); field.current?.focus(); field.current?.select(); };
 const reveal = () => { if (!disabled) { setQuery(''); setActive(0); setOpen(true); } };
 useLayoutEffect(() => {
  if (!open || disabled || !field.current) return;
  const host = field.current.closest<HTMLElement>('[role="dialog"],[role="alertdialog"]') ?? document.body;
  setPortal(host);
  const place = () => {
   if (!field.current) return;
   const rect = field.current.getBoundingClientRect(); const base = host.getBoundingClientRect(); const modal = host !== document.body;
   const ceiling = modal ? Math.max(0, base.top + host.clientTop) : 0;
   const floor = modal ? Math.min(window.innerHeight, base.bottom) : window.innerHeight;
   const below = Math.max(0, floor - rect.bottom - 8), above = Math.max(0, rect.top - ceiling - 8);
   const height = Math.min(320, Math.max(below, above)); const down = below >= Math.min(240, height) || below >= above;
   const maxHeight = Math.min(320, down ? below : above);
   const top = down ? rect.bottom + 4 : rect.top - Math.min(maxHeight, shown.length * 40 + 40) - 4;
   setPosition({ left: rect.left - (modal ? base.left + host.clientLeft - host.scrollLeft : 0), top: top - (modal ? base.top + host.clientTop - host.scrollTop : 0), width: rect.width, maxHeight, fixed: !modal });
  };
  place(); const scroll = (event: Event) => { if (!(event.target instanceof Node && menu.current?.contains(event.target))) place(); };
  window.addEventListener('resize', place); window.addEventListener('scroll', scroll, true);
  return () => { window.removeEventListener('resize', place); window.removeEventListener('scroll', scroll, true); };
 }, [open, disabled, shown.length]);
 useEffect(() => {
  if (!open) return;
  const outside = (event: PointerEvent) => { if (event.target instanceof Node && !root.current?.contains(event.target) && !menu.current?.contains(event.target)) setOpen(false); };
  document.addEventListener('pointerdown', outside, true);
  return () => document.removeEventListener('pointerdown', outside, true);
 }, [open]);
 useEffect(() => { if (disabled) setOpen(false); }, [disabled]);
 useLayoutEffect(() => { if (!open && document.activeElement === field.current) field.current?.select(); }, [open, selected?.label]);
 useEffect(() => { if (open) menu.current?.querySelector<HTMLElement>(`[data-option-index="${active}"]`)?.scrollIntoView?.({ block: 'nearest' }); }, [active, open, query]);
 const keydown = (event: KeyboardEvent<HTMLInputElement>) => {
  onKeyDown?.(event); if (event.defaultPrevented || event.nativeEvent.isComposing) return;
  if (open && event.ctrlKey && event.key.toLowerCase() === 'a') { event.stopPropagation(); return; }
  if (event.altKey || event.ctrlKey || event.metaKey) return;
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
   event.preventDefault(); event.stopPropagation();
   const step = event.key === 'ArrowDown' ? 1 : -1;
   if (!open) { reveal(); setActive(step > 0 ? 0 : Math.max(0, shown.length - 1)); }
   else { let next = active; for (let i = 0; i < shown.length; i++) { next = (next + step + shown.length) % shown.length; if (!shown[next]?.disabled) break; } setActive(next); }
  } else if (event.key === 'Enter' && (open || !selected)) {
   event.preventDefault(); event.stopPropagation(); if (!open) reveal(); else if (shown[active]) choose(shown[active]);
  } else if (event.key === 'Escape' && open) { event.preventDefault(); event.stopPropagation(); setOpen(false); setQuery(''); }
  else if (event.key === 'Tab') { setOpen(false); setQuery(''); }
  else if (open && (event.key === 'Home' || event.key === 'End')) { event.preventDefault(); setActive(event.key === 'Home' ? 0 : Math.max(0, shown.length - 1)); }
 };
 return <div ref={root} className="relative min-w-0">
  <input {...attributes} id={id} ref={field} role="combobox" aria-autocomplete="list" aria-controls={listId} aria-expanded={open} aria-required={required || undefined} aria-activedescendant={open && shown[active] ? `${listId}-${active}` : undefined}
   disabled={disabled} title={open ? undefined : selected?.label} autoComplete="off" className={cn('w-full rounded-lg border bg-background px-3 py-2 pr-16 text-sm focus-visible:outline-2 focus-visible:outline-ring', className, 'pr-16')} style={{ ...style, paddingRight: '4rem' }}
   value={open ? query : (selected?.label ?? '')} placeholder={open ? searchPlaceholder : placeholder}
   onFocus={(event) => { if (!open) event.currentTarget.select(); onFocus?.(event); }}
   onClick={() => { if (!open) reveal(); }}
   onChange={(event) => { setOpen(true); setQuery(event.target.value); setActive(0); if (event.target.value === '' && clearable) onValueChange(''); }}
   onKeyDown={keydown} onBlur={(event) => { if (!(event.relatedTarget instanceof Node && (root.current?.contains(event.relatedTarget) || menu.current?.contains(event.relatedTarget)))) { setOpen(false); setQuery(''); } onBlur?.(event); }} />
  <div className="absolute inset-y-0 right-2 flex items-center gap-1">
   {value && clearable && !disabled && <button type="button" tabIndex={-1} aria-label="Clear selection" className="rounded p-1 text-muted-foreground hover:text-foreground" onPointerDown={(event) => event.preventDefault()} onClick={() => { onValueChange(''); setQuery(''); setActive(0); field.current?.focus(); }}><X className="size-3.5" /></button>}
   <button type="button" tabIndex={-1} disabled={disabled} aria-label="Show options" className="rounded p-1 text-muted-foreground" onPointerDown={(event) => event.preventDefault()} onClick={() => { if (open) setOpen(false); else reveal(); field.current?.focus(); }}><ChevronDown className="size-4" /></button>
  </div>
  <input name={name} tabIndex={-1} aria-hidden="true" className="pointer-events-none sr-only" value={selected && !selected.disabled ? value : ''} disabled={disabled} required={required} onChange={() => {}} onInvalid={(event) => { event.preventDefault(); reveal(); field.current?.focus(); }} />
  {open && portal && position && createPortal(<div ref={menu} id={listId} role="listbox" aria-label="Matching options" style={{ position: position.fixed ? 'fixed' : 'absolute', left: position.left, top: position.top, width: position.width, maxHeight: position.maxHeight, zIndex: 1000 }} className="overflow-y-auto overscroll-contain rounded-lg border bg-popover p-1 text-popover-foreground shadow-xl">
   {shown.map((option, index) => <div key={option.value} id={`${listId}-${index}`} role="option" aria-selected={option.value === value} aria-disabled={option.disabled || undefined} data-option-index={index}
    className={cn('flex min-h-10 cursor-pointer items-center justify-between gap-3 rounded-md px-3 py-2 text-sm break-words', index === active ? 'bg-primary/20' : 'hover:bg-muted', option.disabled && 'cursor-not-allowed opacity-50')}
    onPointerDown={(event) => event.preventDefault()} onMouseMove={() => setActive(index)} onClick={() => choose(option)}><span>{option.label}</span>{option.value === value && <Check className="size-4 shrink-0" />}</div>)}
   {!shown.length && <p className="p-3 text-sm text-muted-foreground">{emptyLabel}</p>}
   {matches.length > shown.length && <p role="status" className="border-t p-2 text-xs text-muted-foreground">Showing {shown.length} of {matches.length}. Refine your search.</p>}
  </div>, portal)}
 </div>;
}
