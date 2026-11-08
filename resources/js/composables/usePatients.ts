import { computed, onBeforeUnmount, ref } from 'vue'
import type { VirtualScrollerLazyEvent } from 'primevue/virtualscroller'

import api from '@/services/api'

type PatientSelectorItem = {
    id: number
    first_name: string | null
    last_name: string | null
    personal_number: string | null
    branch_id: number | null
    nurse_id: number | null
    branch_label: string | null
    nurse_label: string | null
    selectable: boolean
}

type PatientSelectorResponse = {
    items: PatientSelectorItem[]
    next_cursor: string | null
    has_more: boolean
}

export type PatientOption = {
    id: number
    name: string
    personalNumber: string
    branchLabel: string
    nurseLabel: string
    selectable: boolean
    disabled: boolean
}

const SEARCH_DEBOUNCE_MS = 250

export default function usePatients() {
    const patientOptions = ref<PatientOption[]>([])
    const selectedPatient = ref<PatientOption | null>(null)
    const patientsLoading = ref(false)
    const patientsLoadError = ref('')
    const patientFilterString = ref('')
    const branchId = ref<number | null>(null)
    const nextCursor = ref<string | null>(null)
    const hasMorePatients = ref(false)
    const searchCompleted = ref(false)

    let requestVersion = 0
    let filterTimer: number | null = null
    let loadedKey = ''

    const fetchPatientsURL = computed(() => {
        if (!branchId.value) return null
        return `/v1/branches/${branchId.value}/patient-selector`
    })
    const normalizedFilter = computed(() => patientFilterString.value.trim())
    const showCreatePatientAction = computed(() => (
        normalizedFilter.value.length >= 2
        && searchCompleted.value
        && !patientsLoading.value
        && !patientsLoadError.value
        && patientOptions.value.length === 0
    ))

    function setBranchId(id: number | null) {
        if (branchId.value === id) return

        branchId.value = id
        requestVersion++
        patientOptions.value = []
        nextCursor.value = null
        hasMorePatients.value = false
        searchCompleted.value = false
        patientsLoading.value = false
        patientsLoadError.value = ''
        loadedKey = ''
    }

    function transformPatient(item: PatientSelectorItem): PatientOption {
        return {
            id: item.id,
            name: `${item.first_name ?? ''} ${item.last_name ?? ''}`.trim(),
            personalNumber: item.personal_number ?? '',
            branchLabel: item.branch_label ?? '',
            nurseLabel: item.nurse_label ?? '',
            selectable: item.selectable,
            disabled: !item.selectable,
        }
    }

    async function fetchPatients(
        cursor: string | null,
        version: number,
    ): Promise<PatientSelectorResponse | null> {
        const url = fetchPatientsURL.value
        if (!url) return null

        try {
            const response = await api.get(url, {
                params: {
                    q: normalizedFilter.value || undefined,
                    cursor: cursor || undefined,
                },
            })

            if (version !== requestVersion) return null

            return response.data.data as PatientSelectorResponse
        } catch (error) {
            if (version === requestVersion) {
                console.error('Failed to load patient selector options', error)
            }
            return null
        }
    }

    async function loadPatients(force = false) {
        const url = fetchPatientsURL.value
        if (!url) return

        const requestKey = `${url}|${normalizedFilter.value}`
        if (
            !force
            && loadedKey === requestKey
            && searchCompleted.value
            && !patientsLoadError.value
        ) {
            return
        }

        const version = ++requestVersion
        patientsLoading.value = true
        patientsLoadError.value = ''
        searchCompleted.value = false
        nextCursor.value = null
        hasMorePatients.value = false

        const result = await fetchPatients(null, version)
        if (version !== requestVersion) return

        if (result) {
            patientOptions.value = result.items.map(transformPatient)
            nextCursor.value = result.next_cursor
            hasMorePatients.value = result.has_more
            loadedKey = requestKey
        } else {
            patientOptions.value = []
            patientsLoadError.value = 'Pacientov sa nepodarilo načítať.'
            loadedKey = ''
        }

        searchCompleted.value = true
        patientsLoading.value = false
    }

    async function onLazyLoadPatients(event: VirtualScrollerLazyEvent) {
        if (
            patientsLoading.value
            || !hasMorePatients.value
            || !nextCursor.value
            || event.last < patientOptions.value.length - 5
        ) {
            return
        }

        const version = requestVersion
        patientsLoading.value = true
        const result = await fetchPatients(nextCursor.value, version)

        if (version !== requestVersion) return

        if (result) {
            const existingIds = new Set(patientOptions.value.map((option) => option.id))
            const newOptions = result.items
                .filter((item) => !existingIds.has(item.id))
                .map(transformPatient)

            patientOptions.value = [...patientOptions.value, ...newOptions]
            nextCursor.value = result.next_cursor
            hasMorePatients.value = result.has_more
        } else {
            patientsLoadError.value = 'Ďalších pacientov sa nepodarilo načítať.'
        }

        patientsLoading.value = false
    }

    function onFilterPatients() {
        requestVersion++
        searchCompleted.value = false

        if (filterTimer !== null) {
            window.clearTimeout(filterTimer)
        }

        filterTimer = window.setTimeout(() => {
            filterTimer = null
            void loadPatients()
        }, SEARCH_DEBOUNCE_MS)
    }

    onBeforeUnmount(() => {
        if (filterTimer !== null) {
            window.clearTimeout(filterTimer)
        }
        requestVersion++
    })

    return {
        patientOptions,
        selectedPatient,
        patientsLoading,
        patientsLoadError,
        patientFilterString,
        showCreatePatientAction,
        loadPatients,
        onLazyLoadPatients,
        onFilterPatients,
        setBranchId,
    }
}
