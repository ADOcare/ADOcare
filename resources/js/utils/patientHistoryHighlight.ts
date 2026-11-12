export const patientHistoryHighlightStyle = [
    'background-color: var(--c-tag-3) !important',
].join('; ')

export function patientHistoryHighlightPt(highlighted: boolean) {
    return highlighted
        ? { root: { style: patientHistoryHighlightStyle } }
        : undefined
}

export function patientHistoryAutocompleteHighlightPt(highlighted: boolean) {
    return highlighted
        ? { pcInputText: { root: { style: patientHistoryHighlightStyle } } }
        : undefined
}
