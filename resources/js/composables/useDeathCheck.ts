import { onMounted, ref, watch, type Ref } from 'vue'
import type { Router } from 'vue-router'
import type { Patient } from '@/types/models'

type PatientDeathCheckResult = {
    status: 'alive' | 'dead' | 'unknown'
    data: any
    reason?: string
    http_status?: number
}

type AuthStore = {
    isAuthenticated: boolean
    waitUntilInitialized(): Promise<void>
}

type PatientStore = {
    checkPatientDeath(patientId: number): Promise<PatientDeathCheckResult>
    persistPatientData(patient: Patient, selected?: boolean): Promise<Patient>
}

type UseDeathCheckOptions = {
    router: Router
    auth: AuthStore
    patientStore: PatientStore
    currentPatient: Ref<Patient | null>
    toast: {
        add(payload: { severity: string; summary: string; detail: string }): void
    }
}

const DEATH_CHECK_STORAGE_KEY = 'death-check-last-run'
const deathCheckRequestId = ref(0)
const deathUpdateInProgress = ref(false)
const lastDeathToastKey = ref('')

function todayIso() {
    return new Date().toISOString().slice(0, 10)
}

function wasDeathCheckedToday(patientId: number): boolean {
    try {
        const raw = localStorage.getItem(DEATH_CHECK_STORAGE_KEY)
        if (!raw) return false
        const map = JSON.parse(raw) as Record<string, string>
        return map[String(patientId)] === todayIso()
    } catch {
        return false
    }
}

function markDeathCheckedToday(patientId: number) {
    try {
        const raw = localStorage.getItem(DEATH_CHECK_STORAGE_KEY)
        const map = raw ? (JSON.parse(raw) as Record<string, string>) : {}
        map[String(patientId)] = todayIso()
        localStorage.setItem(DEATH_CHECK_STORAGE_KEY, JSON.stringify(map))
    } catch {
        // no-op
    }
}

export default function useDeathCheck(options: UseDeathCheckOptions) {
    const { router, auth, patientStore, currentPatient, toast } = options

    onMounted(() => {
        window.addEventListener('unauthenticated', () => {
            router.push({ name: 'login' })
        })
    })

    watch(
        () => currentPatient.value?.id,
        async (patientId) => {
            await auth.waitUntilInitialized()

            if (!auth.isAuthenticated || typeof patientId !== 'number' || deathUpdateInProgress.value) {
                console.debug('[UDZS] Watcher: Not authenticated or no patient', {
                    isAuthenticated: auth.isAuthenticated,
                    patientId,
                })
                return
            }

            if (wasDeathCheckedToday(patientId)) {
                console.debug('[UDZS] Watcher: Already checked today, skipping', { patientId })
                return
            }

            const patient = currentPatient.value
            if (!patient) return

            const requestId = ++deathCheckRequestId.value
            console.debug('[UDZS] Watcher: Checking patient death', { patientId, requestId })

            try {
                const result = await patientStore.checkPatientDeath(patientId)
                console.debug('[UDZS] Watcher: Death check result', {
                    result,
                    requestId,
                    currentRequestId: deathCheckRequestId.value,
                })

                markDeathCheckedToday(patientId)

                if (requestId !== deathCheckRequestId.value || result.status !== 'dead') {
                    console.debug('[UDZS] Watcher: Skipping toast', {
                        requestId,
                        currentRequestId: deathCheckRequestId.value,
                        status: result.status,
                    })
                    return
                }

                const details = result.data ?? {}
                const fullName = [details?.meno, details?.priezvisko]
                    .filter(Boolean)
                    .join(' ')
                    .trim()
                const deathDate = typeof details?.datumUmrtia === 'string' ? details.datumUmrtia.slice(0, 10) : null

                const dateLabel = deathDate
                    ? new Date(`${deathDate}T00:00:00`).toLocaleDateString('sk-SK')
                    : ''
                const toastKey = `${patientId}:${deathDate ?? 'unknown'}`

                if (deathDate && currentPatient.value && currentPatient.value.death_date !== deathDate) {
                    deathUpdateInProgress.value = true
                    try {
                        currentPatient.value.death_date = deathDate
                        await patientStore.persistPatientData(currentPatient.value, false)
                    } finally {
                        deathUpdateInProgress.value = false
                    }
                }

                if (lastDeathToastKey.value === toastKey) {
                    console.debug('[UDZS] Watcher: Duplicate death toast prevented', { toastKey })
                    return
                }

                lastDeathToastKey.value = toastKey

                const detailParts = [
                    fullName ? `Pacient: ${fullName}` : null,
                    dateLabel ? `Dátum úmrtia: ${dateLabel}` : null,
                ].filter(Boolean)

                console.debug('[UDZS] Watcher: Showing toast', { detailParts })
                toast.add({
                    severity: 'warn',
                    summary: 'Pacient je zosnulý',
                    detail: detailParts.join(' \n ') || 'Pacient je evidovaný ako zosnulý.',
                })
            } catch (error) {
                console.error('[UDZS] Watcher: Failed to check patient death status', error)
            }
        },
        { immediate: true },
    )
}
