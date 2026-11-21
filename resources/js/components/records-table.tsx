import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

export function RecordsTable({ children }: { children: ReactNode }) {
    return <div className="overflow-x-auto rounded-xl border border-t-4 border-t-[#b9d66b] bg-card text-card-foreground">
        <table className="w-full min-w-[48rem] text-left text-sm">{children}</table>
    </div>;
}

type PaginationPage = {
    total: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export function RecordsPagination({ page, label, singular = 'record', plural = 'records' }: {
    page: PaginationPage;
    label: string;
    singular?: string;
    plural?: string;
}) {
    return <nav className="flex flex-wrap items-center gap-2" aria-label={label}>
        <span className="text-sm text-muted-foreground">
            {page.total} {page.total === 1 ? singular : plural} · Page {page.current_page} of {page.last_page}
        </span>
        {page.prev_page_url && <Button variant="outline" asChild><Link href={page.prev_page_url} preserveScroll>Previous</Link></Button>}
        {page.next_page_url && <Button variant="outline" asChild><Link href={page.next_page_url} preserveScroll>Next</Link></Button>}
    </nav>;
}
