import type { Branch, User } from "@/types/models";

export function formatBirthNumber(value?: string) {
    const digits = (value || '').replace(/\D/g, '');
    const first = digits.slice(0, 6);
    const last = digits.slice(6, 10);
    return last.length ? `${first}/${last}` : first;
}

export function capitalize(text: string): string {
    if (!text) return text;
    return text.charAt(0).toUpperCase() + text.slice(1);
}

export function formatUserFullName(user: User): string {
    if (!user) return '-';
    const first = user.first_name ? user.first_name.trim() : '';
    const last = user.last_name ? user.last_name.trim() : '';
    const title = user.title ? user.title.trim() : '';
    return `${title} ${first} ${last}`.trim();
}

export function formatBranchFullName(branch: Branch): string {
    if (!branch) return '-';
    const address = [branch.address, branch.city]
        .map((part) => part?.trim())
        .filter(Boolean)
        .join(', ');
    return address || branch.identificator || branch.code || '-';
}

export function mergeAddressParts(street?: string | null, city?: string | null, psc?: string | null) {
    const s = (street ?? '').trim()
    const c = (city ?? '').trim()
    const z = (psc ?? '').trim()
    if (!s && !c && !z) return ''
    // avoid duplicating city if already present in street
    let addr = s
    if (c && !addr.toLowerCase().includes(c.toLowerCase())) {
        addr = `${addr}${addr ? ', ' : ''}${c}`.trim()
    }
    if (z && !addr.includes(z)) {
        addr = `${addr}${addr ? ' ' : ''}${z}`.trim()
    }
    return addr
}
