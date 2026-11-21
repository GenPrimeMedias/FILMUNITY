import { Link } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';
import styles from './filmunity-auth.module.css';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <main
            className={styles.cover}
            style={
                { '--filmunity-cover': "url('/brand/filmunity-cover.png')" } as CSSProperties
            }
        >
            <section className={styles.panel} aria-labelledby="auth-title">
                <Link href={home()} className={styles.brand}>
                    <img
                        src="/brand/filmunity-logo-transparent.png"
                        alt="FILMUNITY home"
                        width={160}
                    />
                </Link>
                <header className="space-y-2">
                    <h1 id="auth-title" className="text-2xl font-semibold">
                        {title}
                    </h1>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </header>
                {children}
            </section>
        </main>
    );
}
