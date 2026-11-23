import { Link } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { MembershipReminderAction, type ReminderState } from '@/components/membership-reminder-action';
import type { MembershipState } from '@/components/membership-lifecycle';

type Row = MembershipState & { payment_id: string; canEnter: boolean; member_name: string; association_name: string; reminder?: ReminderState | null };
export function MembershipRowActions({ item, admin, renewalQueue }: { item: Row; admin: boolean; renewalQueue: boolean }) {
    const primary = !admin && item.canEnter ? { text: 'Enter workspace', href: `/association-member-workspaces/${item.id}` }
        : item.renewal_payment ? { text: admin ? 'Review payment' : 'Renewal payment', href: `/membership-payments/${item.renewal_payment.id}` }
        : !admin && item.canRenew ? { text: 'Renew membership', href: `/membership-renewals/${item.id}` }
        : { text: 'View details', href: `/membership-renewals/${item.id}` };
    return <div className="flex items-start gap-2 whitespace-nowrap">
        {renewalQueue && admin ? <div className="max-w-52 whitespace-normal"><MembershipReminderAction id={item.id} name={item.member_name} association={item.association_name} reminder={item.reminder} /></div>
            : <Button asChild size="sm" className="shrink-0 whitespace-nowrap"><Link href={primary.href}>{primary.text}</Link></Button>}
        <DropdownMenu><DropdownMenuTrigger asChild><Button type="button" size="sm" variant="outline" className="shrink-0" aria-label={`More actions for ${item.number}`} title="More actions"><MoreHorizontal className="size-4" aria-hidden="true" /></Button></DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64"><DropdownMenuLabel>{item.number}</DropdownMenuLabel><DropdownMenuSeparator />
                {!admin && item.canEnter && <DropdownMenuItem asChild><Link href={`/association-member-workspaces/${item.id}`}>Open association workspace</Link></DropdownMenuItem>}
                <DropdownMenuItem asChild><a href={`/membership-cards/association/${item.id}`}>View digital membership card</a></DropdownMenuItem>
                <DropdownMenuItem asChild><a href={`/membership-cards/association/${item.id}?download=svg`}>Download membership card</a></DropdownMenuItem>
                <DropdownMenuItem asChild><Link href={`/membership-payments/${item.payment_id}`}>Initial payment and receipt</Link></DropdownMenuItem>
                <DropdownMenuItem asChild><Link href={`/membership-renewals/${item.id}`}>Validity and renewal history</Link></DropdownMenuItem>
                {item.renewal_payment && <DropdownMenuItem asChild><Link href={`/membership-payments/${item.renewal_payment.id}`}>{admin ? 'Review renewal payment' : 'Continue renewal payment'}</Link></DropdownMenuItem>}
                {!admin && item.canRenew && !item.renewal_payment && <DropdownMenuItem asChild><Link href={`/membership-renewals/${item.id}`}>Renew yearly membership</Link></DropdownMenuItem>}
            </DropdownMenuContent>
        </DropdownMenu>
    </div>;
}
