export function parseApiDate(value: unknown): Date | null {
    if (typeof value !== 'string') {
        return null
    }

    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/)

    if (!match) {
        return null
    }

    const year = Number(match[1])
    const month = Number(match[2])
    const day = Number(match[3])
    const date = new Date(year, month - 1, day)

    if (
        date.getFullYear() !== year ||
        date.getMonth() !== month - 1 ||
        date.getDate() !== day
    ) {
        return null
    }

    return date
}

export function toApiDate(date: Date | null): string | null {
    if (!date) {
        return null
    }

    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')

    return `${year}-${month}-${day}`
}

export function serviceDeliveryDate(period: Date | null): Date | null {
    if (!period) {
        return null
    }

    return new Date(period.getFullYear(), period.getMonth() + 1, 0)
}

export function latestInvoiceIssueDate(period: Date | null): Date | null {
    if (!period) {
        return null
    }

    return new Date(period.getFullYear(), period.getMonth() + 1, 15)
}

export function formatSlovakDate(date: Date | null): string {
    return date?.toLocaleDateString('sk-SK') ?? ''
}

export function automaticInvoiceDates(
    period: Date | null,
    referenceDate = new Date(),
    dueDays = 30,
): { issuedAt: Date | null; sentAt: Date | null; dueDate: Date | null } {
    const deliveryDate = serviceDeliveryDate(period)
    const latestIssueDate = latestInvoiceIssueDate(period)

    if (!deliveryDate || !latestIssueDate) {
        return { issuedAt: null, sentAt: null, dueDate: null }
    }

    const reference = new Date(
        referenceDate.getFullYear(),
        referenceDate.getMonth(),
        referenceDate.getDate(),
    )

    const issuedAt = new Date(
        Math.min(
            Math.max(reference.getTime(), deliveryDate.getTime()),
            latestIssueDate.getTime(),
        ),
    )
    const sentAt = new Date(Math.max(reference.getTime(), issuedAt.getTime()))
    const dueDate = new Date(sentAt)
    dueDate.setDate(dueDate.getDate() + dueDays)

    return { issuedAt, sentAt, dueDate }
}

export function defaultInvoiceDueDate(sentAt: Date | null, dueDays = 30): Date | null {
    if (!sentAt) {
        return null
    }

    const dueDate = new Date(sentAt)
    dueDate.setDate(dueDate.getDate() + dueDays)

    return dueDate
}

export function validateInvoiceDates(
    period: Date | null,
    type: string | null,
    issuedAt: Date | null,
    sentAt: Date | null,
    dueDate: Date | null,
): string | null {
    if (!issuedAt) {
        return 'Vyberte dátum vystavenia.'
    }

    if (!sentAt) {
        return 'Vyberte dátum odoslania.'
    }

    if (!dueDate) {
        return 'Vyberte presný dátum splatnosti.'
    }

    if (sentAt.getTime() < issuedAt.getTime()) {
        return 'Dátum odoslania nemôže byť skorší ako dátum vystavenia.'
    }

    if (dueDate.getTime() < issuedAt.getTime()) {
        return 'Dátum splatnosti nemôže byť skorší ako dátum vystavenia.'
    }

    if (!['procedures', 'transport'].includes(type ?? '')) {
        return null
    }

    const deliveryDate = serviceDeliveryDate(period)
    const latestIssueDate = latestInvoiceIssueDate(period)

    if (!deliveryDate || !latestIssueDate) {
        return 'Vyberte obdobie faktúry.'
    }

    if (issuedAt.getTime() < deliveryDate.getTime()) {
        return `Dátum vystavenia nemôže byť skorší ako dátum dodania ${formatSlovakDate(deliveryDate)}.`
    }

    if (issuedAt.getTime() > latestIssueDate.getTime()) {
        return `Faktúra musí byť vystavená najneskôr ${formatSlovakDate(latestIssueDate)}.`
    }

    return null
}
