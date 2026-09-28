import { ref, computed } from 'vue'
import api from '@/services/api'
import {
    getPatientIdentifier,
    type PatientWithCoverage,
} from '@/stores/patientStore'
import type { VirtualScrollerLazyEvent } from 'primevue/virtualscroller'

export type PatientOption = {
    id: number
    name: string
    personalNumber: string
    raw: PatientWithCoverage
}

export default function usePatients() {
    const patientOptions = ref<PatientOption[]>([])
    const selectedPatient = ref<PatientOption | null>(null)
    const patientsLoading = ref(false)
    const lastLoadedPage = ref(1)
    const patientFilterString = ref('')

    const branchId = ref<number | null>(null)

    function setBranchId(id: number | null) {
        branchId.value = id
    }

    const fetchPatientsURL = computed(() => {
        if (!branchId.value) return null
        return `/v1/branches/${branchId.value}/patients`
    })

    async function fetchPatients(page: number) {
        try {
            if (!fetchPatientsURL.value) return []
            const res = await api.fetchEntitiesPaginated<PatientWithCoverage>(fetchPatientsURL.value, {
                per_page: 20,
                page: page,
                q: patientFilterString.value.trim() || undefined,
            })
            return res.items || []
        } catch (e) {
            console.error('Failed to load patients', e)
        }
        return []
    }

    function transformPatientsToPatientOptions(items: PatientWithCoverage[]): PatientOption[] {
        return items.map((p) => ({
            id: p.id,
            name: `${p.first_name ?? ''} ${p.last_name ?? ''}`.trim(),
            personalNumber: getPatientIdentifier(p),
            raw: p,
        }))
    }

    async function loadPatients() {
        patientOptions.value = []
        if (!fetchPatientsURL.value) return
        patientsLoading.value = true
        lastLoadedPage.value = 1
        const items = await fetchPatients(1)
        patientOptions.value = transformPatientsToPatientOptions(items)
        patientsLoading.value = false
    }

    async function onLazyLoadPatients(event: VirtualScrollerLazyEvent) {
        const page = Math.floor(event.last / 20 + 1)
        if (page <= lastLoadedPage.value) {
            patientsLoading.value = false
            return
        }
        lastLoadedPage.value = page
        patientsLoading.value = true
        const items = await fetchPatients(page)
        const newOptions = transformPatientsToPatientOptions(items)
        patientOptions.value = (page === 1) ? newOptions : [...patientOptions.value, ...newOptions]
        patientsLoading.value = false
    }

    async function onFilterPatients() {
        lastLoadedPage.value = 1
        await loadPatients()
    }

    return {
        patientOptions,
        selectedPatient,
        patientsLoading,
        patientFilterString,
        loadPatients,
        onLazyLoadPatients,
        onFilterPatients,
        setBranchId,
    }
}
