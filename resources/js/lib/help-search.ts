export function filterHelpArticles<T extends object>(
    articles: T[],
    query: string,
): T[] {
    const term = query.trim().toLowerCase();

    return articles.filter((article) => {
        const content = Object.fromEntries(
            Object.entries(article).filter(([key]) => key !== 'related'),
        );

        return JSON.stringify(content).toLowerCase().includes(term);
    });
}
