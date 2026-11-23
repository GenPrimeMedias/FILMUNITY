import * as React from 'react';
import { SidebarInset } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = React.ComponentProps<'main'> & {
    variant?: AppVariant;
};

export function AppContent({ variant = 'sidebar', children, ...props }: Props) {
    if (variant === 'sidebar') {
        const { className, ...sidebarProps } = props;
        return (
            <SidebarInset
                {...sidebarProps}
                className={`!w-full !min-w-0 !max-w-none basis-0 ${className ?? ''}`}
            >
                {children}
            </SidebarInset>
        );
    }

    return (
        <main
            className="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl"
            {...props}
        >
            {children}
        </main>
    );
}
