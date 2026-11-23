export default function GoogleSignIn({ associationSignup = false }: { associationSignup?: boolean }) {
    return (
        <a
            href={associationSignup ? "/auth/google/redirect?association=1" : "/auth/google/redirect"}
            className="mx-auto inline-flex min-h-11 w-fit max-w-full items-center justify-center gap-2.5 rounded-full border border-[#8e918f] bg-transparent px-4 text-sm font-medium text-[#e3e3e3]! no-underline outline-offset-2 hover:bg-white/5 focus-visible:outline-2 focus-visible:outline-ring"
        >
            <svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 48 48" className="shrink-0">
                <path fill="#4285F4" d="M43.6 24.5c0-1.4-.1-2.7-.4-4H24v7.7h11a9.4 9.4 0 0 1-4.1 6.1v5h6.6c3.8-3.5 6.1-8.7 6.1-14.8z" />
                <path fill="#34A853" d="M24 44c5.5 0 10.1-1.8 13.5-4.9l-6.6-5c-1.8 1.2-4.1 1.9-6.9 1.9-5.3 0-9.8-3.6-11.4-8.4H5.8v5.2A20 20 0 0 0 24 44z" />
                <path fill="#FBBC05" d="M12.6 27.6a12 12 0 0 1 0-7.2v-5.2H5.8a20 20 0 0 0 0 17.6l6.8-5.2z" />
                <path fill="#EA4335" d="M24 12c3 0 5.7 1 7.8 3.1l5.8-5.8C34.1 6 29.5 4 24 4A20 20 0 0 0 5.8 15.2l6.8 5.2C14.2 15.6 18.7 12 24 12z" />
            </svg>
            <span>Sign in with Google</span>
        </a>
    );
}
