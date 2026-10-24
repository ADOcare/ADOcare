export function documentUrlForNewTab(url: string): string {
    const target = new URL(url, window.location.origin)

    target.searchParams.set('standalone', '1')

    return target.origin === window.location.origin
        ? `${target.pathname}${target.search}${target.hash}`
        : target.toString()
}

export function openDocumentInNewTab(url: string): void {
    window.open(
        documentUrlForNewTab(url),
        '_blank',
        'noopener,noreferrer',
    )
}