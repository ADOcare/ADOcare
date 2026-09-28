import { ref } from 'vue';
import type { Patient } from '@/types/models';
import useAuthStore from '@/stores/auth';

type PatientCoverageForm = {
    regime?: 'domestic' | 'eu' | 'special' | 'unclassified' | null;
    category?: 'domestic' | 'eu' | 'non_eu' | 'homeless' | 'other' | null;
    identification_method?: 'slovak_identifier' | 'foreign_triad' | 'incomplete' | null;
    insurance_company_id?: number | null;
    member_state_code?: string | null;
    foreign_insured_id?: string | null;
    special_category?: string | null;
    other_subtype?: string | null;
    legal_basis?: string | null;
    entitlement_confirmed?: boolean;
    valid_from?: string | null;
    valid_to?: string | null;
};

type PatientWithCoverage = Patient & {
    coverage?: PatientCoverageForm | null;
};

export default function usePatientFormValidation(patient: { value: PatientWithCoverage | any }) {
    const submitted = ref(false);
    const errors = ref<{ [key: string]: string }>({});

    function sanitizeZip(value: any) {
        return String(value ?? '').replace(/\D/g, '').slice(0, 5);
    }

    function validateForm() {
        const e: { [k: string]: string } = {};
        const p = patient.value ?? {};

        if (useAuthStore().isManager) {
            if (!p.branch_id) e.branch_id = 'Pobočka je povinná.';
            if (!p.nurse_id) e.nurse_id = 'Sestra je povinná.';
        }

        if (!p.first_name?.trim()) e.first_name = 'Meno je povinné.';
        if (!p.last_name?.trim()) e.last_name = 'Priezvisko je povinné.';
        if (!p.sex) e.sex = 'Pohlavie je povinné.';
        if (!p.doctor_id) e.doctor_id = 'Lekár je povinný.';
        const coverage = p.coverage ?? {};

        if (!coverage.category) {
            e['coverage.category'] = 'Vyberte kategóriu poistenca.';
        }

        if (!coverage.regime) {
            e['coverage.regime'] = 'Vyberte kategóriu poistenca.';
        }

        if (
            coverage.regime !== 'unclassified'
            && !coverage.insurance_company_id
        ) {
            e['coverage.insurance_company_id'] = 'Poisťovňa je povinná.';
        }

        if (
            coverage.identification_method === 'slovak_identifier'
            && !p.personal_number?.trim()
        ) {
            e.personal_number = 'Rodné číslo alebo pridelený BIČ je povinný.';
        }

        if (coverage.regime === 'eu') {
            if (!coverage.member_state_code?.trim()) {
                e['coverage.member_state_code'] = 'Štát poistenia je povinný.';
            }

            if (!coverage.foreign_insured_id?.trim()) {
                e['coverage.foreign_insured_id'] = 'Identifikačné číslo poistenca je povinné.';
            }
        }

        if (coverage.regime === 'special' && !coverage.special_category) {
            e['coverage.special_category'] = 'Kategória osobitného nároku je povinná.';
        }

        if (coverage.category === 'other' && !coverage.other_subtype?.trim()) {
            e['coverage.other_subtype'] = 'Vyberte konkrétnu situáciu pacienta.';
        }

        if (
            ['non_eu', 'homeless', 'other'].includes(coverage.category ?? '')
            && !coverage.legal_basis?.trim()
        ) {
            e['coverage.legal_basis'] = 'Chýba určenie právneho nároku pacienta.';
        }

        if (
            coverage.valid_from
            && coverage.valid_to
            && coverage.valid_to < coverage.valid_from
        ) {
            e['coverage.valid_to'] = 'Koniec platnosti nemôže byť pred začiatkom platnosti.';
        }

        if (!p.city?.trim()) e.city = 'Mesto je povinné.';

        const zip = sanitizeZip(p.zip);
        if (!zip) e.zip = 'PSČ je povinné.';
        else if (!/^\d{5}$/.test(zip)) e.zip = 'PSČ musí mať presne 5 číslic.';
        // write normalized zip back to patient.value if available
        if (patient && patient.value) patient.value.zip = zip;

        if (p.latitude == null || p.longitude == null) {
            e.coordinates = 'Vyberte adresu zo zoznamu, aby sa uložila poloha.';
        }

        errors.value = e;
        return Object.keys(e).length === 0;
    }

    function clearError(key: string) {
        if (errors.value && key && errors.value[key]) delete errors.value[key];
    }

    return {
        submitted,
        errors,
        validateForm,
        sanitizeZip,
        clearError,
    };
}
