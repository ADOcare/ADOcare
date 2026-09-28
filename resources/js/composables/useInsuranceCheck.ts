import { watch, type Ref } from 'vue'
import type { Patient } from '@/types/models'
import type {
    PatientInsuranceCheckResult,
    usePatientStore,
} from '@/stores/patientStore'

type AuthStore = {
    isAuthenticated: boolean
    waitUntilInitialized(): Promise<void>
}

type UseInsuranceCheckOptions = {
    auth: AuthStore
    patientStore: ReturnType<typeof usePatientStore>
    currentPatient: Ref<Patient | null>
    selectionVersion: Ref<number>
    toast: {
        add(payload: {
            severity: string
            summary: string
            detail: string
            life?: number
        }): void
    }
}

const insuranceCheckRequestId = { value: 0 }

function companyLabel(company: PatientInsuranceCheckResult['registered_insurance_company']) {
    if (!company) return 'nezistená poisťovňa'

    return [company.name, company.code ? `(${company.code})` : null]
        .filter(Boolean)
        .join(' ')
}

export default function useInsuranceCheck(options: UseInsuranceCheckOptions) {
    const { auth, patientStore, currentPatient, selectionVersion, toast } = options

    watch(
        () => [currentPatient.value?.id, selectionVersion.value] as const,
        async ([patientId]) => {
            await auth.waitUntilInitialized()

            if (!auth.isAuthenticated || typeof patientId !== 'number') {
                return
            }

            const requestId = ++insuranceCheckRequestId.value

            try {
                const result = await patientStore.checkPatientInsurance(patientId)

                if (requestId !== insuranceCheckRequestId.value) {
                    return
                }

                if (result.status === 'mismatch') {
                    const registeredCompany = companyLabel(result.registered_insurance_company)

                    toast.add({
                        severity: 'warn',
                        summary: 'Poisťovňa pacienta sa nezhoduje',
                        detail: `V ÚDZS je evidovaná ${registeredCompany}. Uložené údaje pacienta je potrebné skontrolovať.`,
                        life: 8000,
                    })
                } else if (result.status === 'not_insured') {
                    toast.add({
                        severity: 'warn',
                        summary: 'Poistný vzťah nebol potvrdený',
                        detail: 'ÚDZS pre pacienta nepotvrdil aktuálny poistný vzťah.',
                        life: 8000,
                    })
                } else if (result.status === 'duplicity') {
                    toast.add({
                        severity: 'warn',
                        summary: 'Duplicitný poistný vzťah',
                        detail: 'ÚDZS vrátil duplicitné údaje o poistení. Záznam pacienta je potrebné skontrolovať.',
                        life: 8000,
                    })
                } else if (result.status === 'identity_mismatch') {
                    const registeredName = result.patient_name ?? 'iného pacienta'

                    toast.add({
                        severity: 'warn',
                        summary: 'Meno pacienta sa nezhoduje',
                        detail: `Pre uložené rodné číslo ÚDZS vrátil meno ${registeredName}. Skontrolujte rodné číslo a osobné údaje.`,
                        life: 8000,
                    })
                }
            } catch (error) {
                console.error('[EOVERENIE] Automatic insurance check failed', error)
            }
        },
        { immediate: true },
    )
}
