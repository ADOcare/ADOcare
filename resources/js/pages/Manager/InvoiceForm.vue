<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import {
    automaticInvoiceDates,
    defaultInvoiceDueDate,
    formatSlovakDate,
    latestInvoiceIssueDate,
    parseApiDate,
    serviceDeliveryDate,
    toApiDate,
    validateInvoiceDates,
} from '@/utils/invoiceDates'

type InsuranceCompanyOption = {
    id: number
    name: string
}

type InvoicePayload = {
    id?: number
    insurance_company_id: number | null
    period: Date | null
    type: string | null
    total?: number
    amount: number | null
    related_invoice_id: number | null
    issued_at: Date | null
    sent_at: Date | null
    due_date: Date | null
}

type RelatedInvoiceOption = {
    id: number
    invoice_number: string
    period: string | null
    insurance_company_id: number | null
}

const invoiceTypes = [
    { id: 'procedures', name: 'Výkonová' },
    { id: 'transport', name: 'Dopravná' },
    { id: 'credit_note', name: 'Dobropis' },
    { id: 'debit_note', name: 'Ťarchopis' },
]

const props = defineProps<{ invoice?: Partial<InvoicePayload> | null; modalResolve?: (value?: any) => void }>()
const emits = defineEmits(['save', 'close'])

const toast = useToast()

const insuranceCompanies = ref<InsuranceCompanyOption[]>([])
const relatedInvoices = ref<RelatedInvoiceOption[]>([])
const saving = ref(false)
const loadingOptions = ref(false)
const today = new Date()
const todayDate = new Date(today.getFullYear(), today.getMonth(), today.getDate())

const local = ref<InvoicePayload>({
    insurance_company_id: null,
    period: null,
    type: null,
    amount: null,
    related_invoice_id: null,
    issued_at: todayDate,
    sent_at: todayDate,
    due_date: null,
})

const isCreditNote = computed(() => local.value.type === 'credit_note')
const isDebitNote = computed(() => local.value.type === 'debit_note')
const isNoteType = computed(() => isCreditNote.value || isDebitNote.value)
const isMonthlyService = computed(() => ['procedures', 'transport'].includes(local.value.type ?? ''))
const deliveryDate = computed(() => serviceDeliveryDate(local.value.period))
const latestIssueDate = computed(() => latestInvoiceIssueDate(local.value.period))

const selectedRelatedInvoice = computed(() => {
    if (!local.value.related_invoice_id) return null

    return relatedInvoices.value.find((item) => item.id === local.value.related_invoice_id) ?? null
})

const selectedPeriodStr = computed(() => {
    return toApiMonth(local.value.period)
})

const filteredRelatedInvoices = computed(() => {
    if (!selectedPeriodStr.value) return []

    return relatedInvoices.value.filter((item) => item.period === selectedPeriodStr.value)
})

watch(
    () => props.invoice,
    (v) => {
        local.value = {
            id: v?.id,
            insurance_company_id: v?.insurance_company_id ?? null,
            period: v?.period ? parsePeriod(v.period) : null,
            type: v?.type ?? null,
            amount: typeof v?.total === 'number' ? Math.abs(v.total) : null,
            related_invoice_id: (v as any)?.related_invoice_id ?? null,
            issued_at: parseApiDate((v as any)?.issued_at) ?? todayDate,
            sent_at: parseApiDate((v as any)?.sent_at) ?? todayDate,
            due_date: parseApiDate((v as any)?.due_date),
        }
    },
    { immediate: true }
)

void loadInsuranceCompanies()
void loadRelatedInvoices()

function parsePeriod(value: unknown): Date | null {
    if (typeof value !== 'string') return null

    const [year, month] = value.split('-').map(Number)

    if (!year || !month) return null

    return new Date(year, month - 1, 1)
}

function toApiMonth(date: Date | null): string | null {
    if (!date) return null

    const year = date.getFullYear()
    const month = `${date.getMonth() + 1}`.padStart(2, '0')

    return `${year}-${month}`
}

async function loadInsuranceCompanies() {
    try {
        loadingOptions.value = true

        const response = await api.get('/v1/insurance-companies', {
            params: { paginate: 0 },
        })

        const payload = response.data?.data

        insuranceCompanies.value = (payload?.items ?? payload ?? []) as InsuranceCompanyOption[]
    } catch (err) {
        console.error('Failed to load insurance companies', err)
        insuranceCompanies.value = []
    } finally {
        loadingOptions.value = false
    }
}

async function loadRelatedInvoices() {
    try {
        const response = await api.get('/v1/invoices', {
            params: { paginate: 0 },
        })

        const payload = response.data?.data
        const items = (payload?.items ?? payload ?? []) as any[]

        relatedInvoices.value = items
            .filter((item) => item?.id && !['credit_note', 'debit_note'].includes(item?.type) && item?.invoice_number)
            .sort((a, b) => Number(b.id) - Number(a.id))
            .map((item) => ({
                id: Number(item.id),
                invoice_number: item.invoice_number ?? `#${item.id}`,
                period: item.period ?? null,
                insurance_company_id: item.insurance_company_id ?? null,
            }))
    } catch (err) {
        console.error('Failed to load related invoices', err)
        relatedInvoices.value = []
    }
}

watch(
    () => [local.value.type, local.value.amount],
    () => {
        // Keep the editable amount positive in the input.
        // The real sign is applied only when saving:
        // credit_note = negative
        // debit_note = positive
        if (isNoteType.value && local.value.amount != null && local.value.amount < 0) {
            local.value.amount = Math.abs(local.value.amount)
        }
    },
    { immediate: true }
)

watch(
    () => [local.value.type, selectedPeriodStr.value],
    () => {
        local.value.related_invoice_id = null
    }
)

watch(
    () => local.value.period,
    (period) => {
        if (local.value.id || !period) {
            return
        }

        const automatic = automaticInvoiceDates(period)
        local.value.issued_at = automatic.issuedAt
        local.value.sent_at = automatic.sentAt
        local.value.due_date = automatic.dueDate
    },
)

watch(
    () => local.value.sent_at,
    (sentAt) => {
        if (!local.value.id) {
            local.value.due_date = defaultInvoiceDueDate(sentAt)
        }
    },
)

watch(
    () => local.value.related_invoice_id,
    () => {
        const related = selectedRelatedInvoice.value

        if (!related?.insurance_company_id) return

        local.value.insurance_company_id = related.insurance_company_id
    }
)

function close() {
    if (props.modalResolve) {
        try {
            props.modalResolve(undefined)
        } catch {
            // ignore modal resolve issues
        }
    } else {
        emits('close')
    }
}

async function save() {
    const period = toApiMonth(local.value.period)

    if (!local.value.type) {
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Vyberte typ faktúry.',
            life: 3500,
        })
        return
    }

    if (!period) {
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Vyberte obdobie.',
            life: 3500,
        })
        return
    }

    if (isNoteType.value && local.value.amount == null) {
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Zadajte sumu dokladu.',
            life: 3500,
        })
        return
    }

    if (isNoteType.value && (local.value.amount ?? 0) <= 0) {
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Suma dokladu musí byť väčšia ako 0.',
            life: 3500,
        })
        return
    }

    if (isNoteType.value && !local.value.related_invoice_id) {
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Vyberte súvisiacu faktúru.',
            life: 3500,
        })
        return
    }

    const dateError = validateInvoiceDates(
        local.value.period,
        local.value.type,
        local.value.issued_at,
        local.value.sent_at,
        local.value.due_date,
    )

    if (dateError) {
        toast.add({
            severity: 'error',
            summary: 'Nesprávne dátumy',
            detail: dateError,
            life: 5000,
        })
        return
    }

    saving.value = true

    try {
        const payload: Record<string, unknown> = {
            period,
            type: local.value.type,
            issued_at: toApiDate(local.value.issued_at),
            sent_at: toApiDate(local.value.sent_at),
            due_date: toApiDate(local.value.due_date),
        }

        if (local.value.insurance_company_id) {
            payload.insurance_company_id = local.value.insurance_company_id
        }

        if (isNoteType.value) {
            payload.amount = isCreditNote.value
                ? -Math.abs(local.value.amount ?? 0)
                : Math.abs(local.value.amount ?? 0)

            payload.related_invoice_id = local.value.related_invoice_id
        }

        if (local.value.id) {
            await api.post(`/v1/invoices/${local.value.id}?_method=PUT`, payload)
        } else {
            await api.post('/v1/invoices', payload)
        }

        toast.add({
            severity: 'success',
            summary: 'Uložené',
            detail: 'Faktúra bola uložená.',
            life: 3000,
        })

        if (props.modalResolve) {
            props.modalResolve(local.value)
        } else {
            emits('save', local.value)
        }
    } catch (err) {
        console.error('Save invoice failed', err)

        const responseErrors = (err as any)?.response?.data?.errors
        const firstMessage = responseErrors && typeof responseErrors === 'object'
            ? Object.values(responseErrors).flat().map(String)[0]
            : null

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: firstMessage ?? 'Nepodarilo sa uložiť faktúru.',
            life: 4000,
        })
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div class="grid grid-cols-12 gap-4 p-2">
        <div class="col-span-12">
            <label class="block text-normal mb-1">Poisťovňa</label>
            <Select
                v-model="local.insurance_company_id"
                :options="insuranceCompanies"
                optionLabel="name"
                optionValue="id"
                :loading="loadingOptions"
                fluid
                dropdownIcon="bi bi-chevron-down"
            />
        </div>

        <div class="col-span-12">
            <label class="block text-normal mb-1">Typ</label>
            <Select
                v-model="local.type"
                :options="invoiceTypes"
                optionLabel="name"
                optionValue="id"
                :loading="loadingOptions"
                fluid
                dropdownIcon="bi bi-chevron-down"
            />
        </div>

        <div v-if="isNoteType" class="col-span-12">
            <label class="block text-normal mb-1">Suma</label>
            <InputNumber
                v-model="local.amount"
                mode="decimal"
                :prefix="isCreditNote ? '- ' : undefined"
                :min="0.01"
                :minFractionDigits="2"
                :maxFractionDigits="2"
                :useGrouping="false"
                fluid
            />
        </div>

        <div class="col-span-12">
            <label class="block text-normal mb-1">Obdobie</label>
            <DatePicker
                v-model="local.period"
                view="month"
                dateFormat="mm/yy"
                :manualInput="false"
                class="w-full"
                inputClass="w-full!"
            />
        </div>

        <div v-if="isMonthlyService" class="col-span-12">
            <label class="block text-normal mb-1">Dátum dodania služby</label>
            <InputText
                :model-value="formatSlovakDate(deliveryDate)"
                disabled
                fluid
            />
            <small class="text-muted">
                Automaticky posledný deň vybraného mesiaca.
            </small>
        </div>

        <div class="col-span-12 md:col-span-6">
            <label class="block text-normal mb-1">Dátum vystavenia *</label>
            <DatePicker
                v-model="local.issued_at"
                dateFormat="dd.mm.yy"
                :manualInput="false"
                :minDate="isMonthlyService ? deliveryDate : undefined"
                :maxDate="isMonthlyService ? latestIssueDate : undefined"
                class="w-full"
                inputClass="w-full!"
            />
            <small v-if="isMonthlyService && latestIssueDate" class="text-muted">
                Najneskôr {{ formatSlovakDate(latestIssueDate) }}.
            </small>
        </div>

        <div class="col-span-12 md:col-span-6">
            <label class="block text-normal mb-1">Dátum odoslania *</label>
            <DatePicker
                v-model="local.sent_at"
                dateFormat="dd.mm.yy"
                :manualInput="false"
                :minDate="local.issued_at ?? undefined"
                class="w-full"
                inputClass="w-full!"
            />
        </div>

        <div class="col-span-12">
            <label class="block text-normal mb-1">Dátum splatnosti *</label>
            <DatePicker
                v-model="local.due_date"
                dateFormat="dd.mm.yy"
                :manualInput="false"
                :minDate="local.issued_at ?? undefined"
                class="w-full"
                inputClass="w-full!"
            />
            <small class="text-muted">
                Automaticky 30 dní od odoslania; upravte podľa zmluvy s poisťovňou.
            </small>
        </div>

        <div v-if="isNoteType" class="col-span-12">
            <label class="block text-normal mb-1">K faktúre</label>
            <Select
                v-model="local.related_invoice_id"
                :options="filteredRelatedInvoices"
                optionLabel="invoice_number"
                optionValue="id"
                filter
                filterPlaceholder="Hľadať faktúru"
                :disabled="!selectedPeriodStr"
                fluid
                dropdownIcon="bi bi-chevron-down"
            />
            <small v-if="!selectedPeriodStr" class="text-muted">
                Najprv vyberte obdobie.
            </small>
            <small v-else-if="!filteredRelatedInvoices.length" class="text-danger">
                Pre vybrané obdobie neexistuje žiadna faktúra.
            </small>
        </div>

        <div class="col-span-12 mt-4 flex items-center justify-end gap-2">
            <Button
                label="Zrušiť"
                text
                @click="close"
                class="text-accent! px-2!"
            />

            <Button
                :label="local.id ? 'Upraviť' : 'Vytvoriť'"
                :loading="saving"
                @click="save"
                class="bg-accent! border-accent! px-2! hover:bg-darkgrey! hover:border-darkgrey! text-white!"
            />
        </div>
    </div>
</template>
