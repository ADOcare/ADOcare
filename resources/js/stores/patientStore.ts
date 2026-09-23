import { defineStore } from 'pinia'
import type { Doctor, InsuranceCompany, Patient } from '@/types/models'
import api from '@/services/api'
import useAuthStore from './auth'

const STORAGE_KEY = 'selected-patient'

type PatientCoverage = {
    id?: number
    regime: 'domestic' | 'eu' | 'special' | 'unclassified' | null
    insurance_company_id: number | null
    member_state_code: string | null
    foreign_insured_id: string | null
    special_category: string | null
    entitlement_document_type: string | null
    entitlement_document_number: string | null
    valid_from: string | null
    valid_to: string | null
    is_verified: boolean
}

type PatientWithCoverage = Patient & {
    coverage?: PatientCoverage | null
}

function serializeCoverage(patient: PatientWithCoverage): PatientCoverage | null {
    if (!patient.coverage) {
        return null
    }

    return {
        id: patient.coverage.id,
        regime: patient.coverage.regime, 
        insurance_company_id: patient.coverage.insurance_company_id,
        member_state_code: patient.coverage.member_state_code,
        foreign_insured_id: patient.coverage.foreign_insured_id,
        special_category: patient.coverage.special_category,
        entitlement_document_type: patient.coverage.entitlement_document_type,
        entitlement_document_number: patient.coverage.entitlement_document_number,
        valid_from: patient.coverage.valid_from,
        valid_to: patient.coverage.valid_to,
        is_verified: patient.coverage.is_verified,
    }
}

function serializePatient(patient: PatientWithCoverage) {
    return {
        first_name: patient.first_name,
        last_name: patient.last_name,
        title: patient.title,
        personal_number: patient.personal_number,
        sex: patient.sex,
        contact: patient.contact,
        doctor_id: patient.doctor_id,
        address: patient.address,
        city: patient.city,
        zip: patient.zip,
        latitude: patient.latitude,
        longitude: patient.longitude,
        reference_date: patient.reference_date,
        death_date: patient.death_date,
        dekurz_number: patient.dekurz_number,
        coverage: serializeCoverage(patient),
    }
}

export const usePatientStore = defineStore('patient', {
    state: () => ({
        current: null as PatientWithCoverage | null,
    }),

    actions: {
        setPatient(patient: PatientWithCoverage) {
            if (useAuthStore().isManager) {
                return
            }

            this.current = patient
            localStorage.setItem(STORAGE_KEY, JSON.stringify(patient))
        },

        loadFromStorage() {
            const raw = localStorage.getItem(STORAGE_KEY)

            if (!raw) {
                return
            }

            try {
                const patient = JSON.parse(raw) as PatientWithCoverage
                this.current = patient
            } catch (error) {
                console.error('Failed to parse stored patient', error)
                localStorage.removeItem(STORAGE_KEY)
            }
        },

        async fetchPatient(patientId: number) {
            try {
                const response = await api.get(`/v1/patients/${patientId}`)
                const patient = response.data.data as PatientWithCoverage

                this.setPatient(patient)

                return patient
            } catch (error) {
                throw new Error('Failed to fetch patient: ' + error)
            }
        },

        async persistPatientData(patient: PatientWithCoverage) {
            try {
                const isNew = !patient.id

                if (isNew) {
                    const payload = {
                        ...serializePatient(patient),
                        dekurz_number: patient.dekurz_number || 1,
                        branch_id: patient.branch_id,
                        nurse_id: patient.nurse_id,
                    }
                    const response = await api.post('/v1/patients', payload)

                    return response.data.data as PatientWithCoverage
                }

                if (useAuthStore().isManager) {
                    const payload = {
                        branch_id: patient.branch_id,
                        nurse_id: patient.nurse_id,
                        death_date: patient.death_date,
                    }
                    const response = await api.put(`/v1/patients/${patient.id}`, payload)

                    return response.data.data as PatientWithCoverage
                }

                const response = await api.put(
                    `/v1/patients/${patient.id}`,
                    serializePatient(patient),
                )
                const updated = response.data.data as PatientWithCoverage

                this.setPatient(updated)

                return updated
            } catch (error) {
                throw new Error('Failed to save patient: ' + error)
            }
        },

        async createPatient(patient: PatientWithCoverage, branchId: number) {
            try {
                const payload = {
                    ...serializePatient(patient),
                    dekurz_number: 1,
                }
                const response = await api.post(`/v1/branches/${branchId}/patients`, payload)

                return response.data.data as PatientWithCoverage
            } catch (error) {
                throw new Error('Failed to create patient: ' + error)
            }
        },

        async fetchDoctor(patientId: number) {
            try {
                const response = await api.get(`/v1/patients/${patientId}/doctor`)

                return response.data.data as Doctor
            } catch (error) {
                throw new Error('Failed to fetch doctor: ' + error)
            }
        },

        async fetchInsuranceCompany(patientId: number) {
            try {
                const response = await api.get(`/v1/patients/${patientId}/insurance-company`)

                return response.data.data as InsuranceCompany
            } catch (error) {
                throw new Error('Failed to fetch insurance company: ' + error)
            }
        },

        async checkPatientDeath(patientId: number) {
            console.debug('[UDZS] Store: checkPatientDeath called', { patientId })

            try {
                const response = await api.get(`/v1/patients/${patientId}/death-check`)
                console.debug('[UDZS] Store: API response', { data: response.data })

                return response.data.data as {
                    status: 'alive' | 'dead' | 'unknown'
                    data: any
                    reason?: string
                    http_status?: number
                }
            } catch (error) {
                console.error('[UDZS] Store: API error', error)
                throw new Error('Failed to check patient death status: ' + error)
            }
        },

        async softDeletePatient(patientId: number) {
            try {
                await api.delete(`/v1/patients/${patientId}`)

                if (this.current?.id === patientId) {
                    this.clear()
                }
            } catch (error) {
                throw new Error('Failed to delete patient: ' + error)
            }
        },

        clear() {
            this.current = null
            localStorage.removeItem(STORAGE_KEY)
        },
    },
})
