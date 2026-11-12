import { openModal } from "@/composables/useModal"
import useAuthStore from "@/stores/auth"
import { markRaw } from "vue"
import PatientDocumentsForm from "@/pages/Patients/partials/form/PatientDocumentsForm.vue"
import EditPatientForm from "@/pages/Patients/partials/form/EditPatientForm.vue"
import ScanNewDocumentModal from "@/pages/Patients/Scan/ScanNewDocumentModal.vue"
import CreatePatientForm from "@/pages/Patients/partials/form/CreatePatientForm.vue"

export async function openPatientDocumentsModal(patientId?: number) {
    if (!patientId) return

    // open via modal provider
    return await openModal(markRaw(PatientDocumentsForm), { patientId }, { header: 'Dokumenty pacienta', class: 'w-7xl max-w-[90vw]' })
}

export async function openPatientEditModal(patientId?: number) {
    if (!patientId) return

    const isManagerView = useAuthStore().isManager;
    // open via modal provider
    return await openModal(markRaw(EditPatientForm), { patientId, isManagerView }, {
        header: 'Upraviť pacienta',
        style: { width: '90%' },
    });
}

export async function openPatientCreateModal() {
    return await openModal(markRaw(CreatePatientForm), {}, {
        header: 'Pridať pacienta',
        style: {
            width: '90%',
            maxWidth: 'calc(100vw - 2rem)',
        },
    })
}

export async function openScanDocumentModal(patientId: number, branchId: number) {
    if (!patientId || !branchId) return

    return await openModal(markRaw(ScanNewDocumentModal), { patientId, branchId }, {
        header: 'Naskenujte lekársky nález',
        style: { width: '500px', maxWidth: '95vw' }
    })
}
