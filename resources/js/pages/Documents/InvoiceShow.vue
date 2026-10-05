<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useUiOverlayStore } from '@/stores/uiOverlay'
import { usePublicDocument, type PublicDocumentProps } from '@/composables/usePublicDocument'

type AssociatedDocument = {
    branch_code: string
    user_code: string
    user_initials: string
    amount: number
}

type InvoicePayload = {
    invoice_id: number
    invoice_number: string
    company_name: string
    company_address: string
    company_city: string
    company_zip: string
    company_ico: string
    company_dic: string
    company_ic_dph: string | null
    company_iban: string | null
    company_bic: string | null
    company_register: string | null
    constant_symbol: string
    due_date: string
    payment_method: string
    invoice_created_at: string
    invoice_sent_at: string
    services_delivered_at: string
    insurance_company_id: number
    insurance_company_name: string
    insurance_company_address: string
    insurance_company_city: string
    insurance_company_zip: string
    insurance_company_ico: string
    insurance_company_dic: string
    insurance_company_ic_dph: string | null
    insurance_company_register: string | null
    period: string
    type: string
    total: number
    related_invoice_id: number | null
    related_invoice_number: string | null
    associated_documents: AssociatedDocument[]
    created_by_user_id: number
    created_by_user: string
    created_at: string
    updated_at: string
}

const props = defineProps<PublicDocumentProps>()
const route = useRoute()
const uiOverlayStore = useUiOverlayStore()

const { data, loading } = usePublicDocument(props, {
    privateDataUrl: `/v1/invoices/${route.params.documentId}`
})

const invoice = computed(() => data.value as InvoicePayload | null)
const documentId = computed(() => Number(route.params.documentId))

const formattedTotal = computed(() => {
    const value = Number(invoice.value?.total ?? 0)
    return value.toLocaleString('sk-SK', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' €'
})

const issueDate = computed(() => formatDate(invoice.value?.invoice_created_at ?? ''))
const deliveryDate = computed(() => formatDate(invoice.value?.services_delivered_at ?? ''))
const sentDate = computed(() => formatDate(invoice.value?.invoice_sent_at ?? ''))
const dueDate = computed(() => formatDate(invoice.value?.due_date ?? ''))

const periodLabel = computed(() => {
    const period = invoice.value?.period ?? ''
    if (!period) return ''

    const [year, month] = period.split('-')
    if (!year || !month) return period

    return `${month}.${year}`
})

const invoiceText = computed(() => {
    if (invoice.value?.type === 'credit_note') {
        return `Dobropis k faktúre č. ${invoice.value.related_invoice_number || ''}.`
    }

    if (invoice.value?.type === 'debit_note') {
        return `Ťarchopis k faktúre č. ${invoice.value.related_invoice_number || ''}.`
    }

    if (invoice.value?.type === 'transport') {
        return `Fakturujeme Vám za poskytnutie nákladov na dopravu pre Vašich poistencov za obdobie ${periodLabel.value} na základe Zmluvy o poskytovaní a úhrade zdravotnej starostlivosti.`
    }

    if (invoice.value?.type === 'procedures') {
        return `Fakturujeme Vám za poskytnutie ošetrovateľskej starostlivosti pre Vašich poistencov za obdobie ${periodLabel.value} na základe Zmluvy o poskytovaní a úhrade zdravotnej starostlivosti.`
    }

    return 'Faktúra'
})

const attachmentsText = computed(() => {
    if (invoice.value?.type === 'credit_note') {
        return 'dobropis'
    }

    if (invoice.value?.type === 'debit_note') {
        return 'ťarchopis'
    }

    if (invoice.value?.type === 'transport') {
        return `x dávka 793n - dopravná dávka`
    }

    if (invoice.value?.type === 'procedures') {
        return `x dávka 753n - výkonová dávka`
    }

    return 'Faktúra'
})

const nonZeroDocuments = computed(() => {
    return (invoice.value?.associated_documents ?? []).filter(doc => Number(doc.amount ?? 0) > 0)
})

function formatDate(dateStr: string) {
    if (!dateStr) return ''

    const date = new Date(dateStr)
    if (Number.isNaN(date.getTime())) return dateStr

    return date.toLocaleDateString('sk-SK')
}

function formatCurrency(value: number) {
    return Number(value ?? 0).toLocaleString('sk-SK', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' €'
}

async function loadInvoice() {
    // Handled by composable
}

function printPage() {
    window.print()
}

watchEffect(() => {
    uiOverlayStore.setContentLoading(loading.value)
})
</script>

<template>
    <div class="flex flex-col gap-4 invoice-page">
        <div class="flex flex-col gap-4">
            <Toolbar
                class="bg-transparent! border-0! p-0! py-3! shadow-none! flex items-center justify-between no-print">
                <template #start>
                    <span class="text-heading-accent">
                        Faktúra
                    </span>
                </template>

                <template #end>
                    <Button icon="bi bi-printer"
                        class="bg-accent! border-accent! hover:bg-darkgrey! hover:border-darkgrey! h-7!"
                        :disabled="loading || !invoice" @click="printPage" />
                </template>
            </Toolbar>

            <div class="invoice-wrapper">
                <div id="invoice-sheet">
                    <template v-if="loading">
                        <div class="mt-4 text-center text-sm text-gray-500">
                            Načítavam faktúru...
                        </div>
                    </template>

                    <template v-else-if="!invoice">
                        <div class="mt-4 text-center text-sm text-danger">
                            Faktúru sa nepodarilo načítať.
                        </div>
                    </template>

                    <template v-else>
                        <div class="flex justify-between items-start mb-8">
                            <div>
                                <div class="text-2xl font-bold uppercase mb-2">
                                    {{ invoice.type === 'credit_note' ? 'Dobropis' : (invoice.type === 'debit_note' ?
                                        'Ťarchopis' : 'Faktúra') }}
                                </div>
                                <div class=" mb-1">
                                    Faktúra číslo: <strong>{{ invoice.invoice_number || '' }}</strong>
                                </div>
                                <div v-if="invoice.related_invoice_number" class="mb-1 text-sm">
                                    K faktúre: <strong>{{ invoice.related_invoice_number }}</strong>
                                </div>
                            </div>

                            <div class="text-sm text-right">
                                <div><strong>Konštatný symbol: </strong> {{ invoice.constant_symbol || '' }}</div>
                                <div><strong>Spôsob úhrady:</strong> {{ invoice.payment_method || '' }}</div>
                                <div><strong>Dátum splatnosti:</strong> {{ dueDate || '' }}</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 mb-2 gap-2">
                            <div class="border border-black p-4">
                                <div class="font-bold mb-2">Dodávateľ</div>

                                <table class="w-full text-sm">
                                    <tbody>
                                        <tr>
                                            <!-- LEFT -->
                                            <td class="align-top pr-4 w-1/2">
                                                <div><strong>{{ invoice.company_name }}</strong></div>
                                                <div>{{ invoice.company_address }}</div>
                                                <div>{{ invoice.company_zip }}, {{ invoice.company_city }}</div>
                                            </td>

                                            <!-- RIGHT -->
                                            <td class="align-top w-1/2">
                                                <div>IČO: {{ invoice.company_ico || '' }}</div>
                                                <div>DIČ: {{ invoice.company_dic || '' }}</div>
                                                <div>IČ DPH: {{ invoice.company_ic_dph || '' }}</div>
                                                <div>IBAN: {{ invoice.company_iban || '' }}</div>
                                                <div>BIC: {{ invoice.company_bic || '' }}</div>
                                                <div v-if="invoice.company_register" class="mt-2 text-xs">
                                                    {{ invoice.company_register }}
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Odberateľ -->
                            <div class="border border-black p-4">
                                <div class="font-bold mb-2">Odberateľ</div>

                                <table class="w-full text-sm">
                                    <tbody>
                                        <tr>
                                            <!-- LEFT -->
                                            <td class="align-top pr-4 w-1/2">
                                                <div><strong>{{ invoice.insurance_company_name }}</strong></div>
                                                <div>{{ invoice.insurance_company_address }}</div>
                                                <div>{{ invoice.insurance_company_zip }} {{
                                                    invoice.insurance_company_city }}</div>
                                            </td>

                                            <!-- RIGHT -->
                                            <td class="align-top w-1/2">
                                                <div>IČO: {{ invoice.insurance_company_ico || '' }}</div>
                                                <div>DIČ: {{ invoice.insurance_company_dic || '' }}</div>
                                                <div>IČ DPH: {{ invoice.insurance_company_ic_dph || '' }}</div>
                                                <div v-if="invoice.insurance_company_register" class="mt-2 text-xs">
                                                    {{ invoice.insurance_company_register }}
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <table class="w-full border-collapse text-sm mb-2">
                            <tbody>
                                <tr>
                                    <td class="border border-black p-3 w-1/3">
                                        <strong>Dátum vystavenia</strong><br />
                                        {{ issueDate || '' }}
                                    </td>
                                    <td class="border border-black p-3 w-1/3">
                                        <strong>Dátum odoslania</strong><br />
                                        {{ sentDate || '' }}
                                    </td>
                                    <td class="border border-black p-3 w-1/3"
                                        v-if="invoice.type === 'transport' || invoice.type === 'procedures'">
                                        <strong>Dátum dodania služby</strong><br />
                                        {{ deliveryDate || '' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="my-6 text-normal">
                            {{ invoiceText }}
                        </div>

                        <table class="w-full border-collapse text-sm mb-6">
                            <thead v-if="invoice.type === 'transport' || invoice.type === 'procedures'">
                                <tr>
                                    <th class="border border-black p-3 text-left"></th>
                                    <th class="border border-black p-3 text-left">Kód PZS</th>
                                    <th class="border border-black p-3 text-left">Kód zdravotníka</th>
                                    <th class="border border-black p-3 text-right">Suma</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr v-for="(doc, index) in nonZeroDocuments" :key="index">
                                    <td class="border border-black p-3">{{ index + 1 }}</td>
                                    <td class="border border-black p-3">{{ doc.branch_code || '' }}</td>
                                    <td class="border border-black p-3">{{ doc.user_code || '' }} {{ doc.user_initials
                                        || '' }}</td>
                                    <td class="border border-black p-3 text-right">{{ formatCurrency(doc.amount) }}</td>
                                </tr>

                                <tr>
                                    <td colspan="3" class="border border-black p-3 text-right font-bold">
                                        K úhrade
                                    </td>
                                    <td class="border border-black p-3 text-right font-bold">
                                        {{ formattedTotal }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="mt-10 text-sm" v-if="invoice.type === 'transport' || invoice.type === 'procedures'">
                            <div><strong>Prílohy:</strong><br />{{ nonZeroDocuments.length }} {{ attachmentsText }}
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
#invoice-sheet {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    background: white;
    box-sizing: border-box;
    padding: 20mm;
    box-shadow: 0 0 8px rgba(0, 0, 0, 0.15);
}

.invoice-wrapper {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 2rem;
    overflow: auto;
    background: transparent;
}

@page {
    size: A4;
    margin: 0;
}

@media print {
    body {
        margin: 0;
        padding: 0;
    }

    body * {
        visibility: hidden !important;
    }

    #invoice-sheet,
    #invoice-sheet * {
        visibility: visible !important;
    }

    #invoice-sheet {
        position: fixed !important;
        inset: 0 !important;
        margin: 0 auto !important;
        box-shadow: none !important;
    }

    .no-print,
    .p-toolbar {
        display: none !important;
    }
}
</style>
