<script setup lang="ts">
import { computed, markRaw, ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'
import InvoiceForm from './InvoiceForm.vue'
import InvoiceNumberForm from './InvoiceNumberForm.vue'
import InvoiceBulkCreateForm from './InvoiceBulkCreateForm.vue'
import useModal from '@/composables/useModal'
import useEmailDocumentsDialog from '@/composables/useEmailDocumentsDialog'
import type { DataTableOptions } from '@/types/datatable'

type InvoiceRow = {
  id: number
  name: string
  mime_type?: string
  path?: string
  created_at?: string
  updated_at?: string
  created_by_user?: string
  insurance_company_name?: string
  period?: string
  total?: number
  invoice_number?: string
  issued_at?: string | null
  sent_at?: string | null
  due_date?: string | null
  type?: string
  related_invoice_number?: string
}

const toast = useToast()
const { openModal } = useModal()
const { openEmailDocumentsDialog } = useEmailDocumentsDialog()
const actionRemote = ref<any>(null)

const now = new Date()
const dates = ref<Date | null>(new Date(now.getFullYear(), now.getMonth() - 1, 1))

const getInvoiceUrl = (invoice: { id: number }) => {
  if (!invoice.id) return null
  return `/documents/invoices/${invoice.id}`
}

const openInvoiceInNewTab = (invoice: InvoiceRow) => {
  const url = getInvoiceUrl(invoice)
  if (!url) return
  window.open(url, '_blank', 'noopener,noreferrer')
}

const formatDateWithTime = (dateStr?: string) => {
  if (!dateStr) return '-'
  const date = new Date(dateStr)
  if (Number.isNaN(date.getTime())) return '-'

  const datePart = date.toLocaleDateString('sk-SK')
  const timePart = date.toLocaleTimeString('sk-SK', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })

  return `${datePart} ${timePart}`
}

const formatAmount = (amount?: number) => {
  if (amount === null || amount === undefined) return '-'
  return `${amount.toLocaleString('sk-SK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`
}

const formatPeriod = (period?: string) => {
  if (!period) return '-'

  const [year, month] = period.split('-').map(Number)
  if (!year || !month) return period

  const date = new Date(year, month - 1, 1)
  return date.toLocaleDateString('sk-SK', {
    month: '2-digit',
    year: 'numeric',
  })
}

const formatInvoiceType = (type?: string) => {
  if (type === 'procedures') return 'Výkonová'
  if (type === 'transport') return 'Dopravná'
  if (type === 'credit_note') return 'Dobropis'
  if (type === 'debit_note') return 'Ťarchopis'
  return '-'
}

async function openCreate() {
  try {
    await openModal(markRaw(InvoiceForm), { invoice: null }, { header: 'Nová faktúra', style: { width: '720px' }, closable: true })
  } finally {
    if (actionRemote.value?.loadPage) {
      await actionRemote.value.loadPage(1)
    }
  }
}

async function openCreateBulk() {
  try {
    await openModal(
      markRaw(InvoiceBulkCreateForm),
      { initialPeriod: dates.value },
      { header: 'Hromadné vytvorenie faktúr', style: { width: '560px' }, closable: true }
    )
  } finally {
    if (actionRemote.value?.loadPage) {
      await actionRemote.value.loadPage(1)
    }
  }
}

async function openEdit(invoice: InvoiceRow) {
  try {
    await openModal(markRaw(InvoiceForm), { invoice }, { header: 'Upraviť faktúru', style: { width: '720px' }, closable: true })
  } finally {
    if (actionRemote.value?.loadPage) {
      await actionRemote.value.loadPage(actionRemote.value.page.value)
    }
  }
}

async function openAssignNumber(invoice: InvoiceRow) {
  try {
    await openModal(markRaw(InvoiceNumberForm), { invoice }, { header: 'Priradiť číslo faktúry', style: { width: '520px' }, closable: true })
  } finally {
    if (actionRemote.value?.loadPage) {
      await actionRemote.value.loadPage(actionRemote.value.page.value)
    }
  }
}

const options = computed<DataTableOptions<InvoiceRow>>(() => ({
  rowKey: 'id',
  endpointUrl: 'v1/invoices',
  defaultPageSize: 25,
  pageSizeOptions: [10, 25, 50],
  selectable: true,
  sortBy: 'invoice_number',
  dateRangeFilter: {
    mode: 'single',
    param: 'period',
    placeholder: 'Obdobie',
    view: 'month',
    dateFormat: 'mm/yy',
    value: dates.value,
  },
  afterInit: ({ remote }) => {
    actionRemote.value = remote
  },
  columns: [
    {
      field: 'invoice_number',
      header: 'Číslo faktúry',
      sortable: true,
      slot: 'invoice-number',
    },
    {
      field: 'type',
      header: 'Typ',
      sortable: true,
      render: (v: string | undefined) => formatInvoiceType(v),
    },
    {
      field: 'insurance_company_name',
      header: 'Poisťovňa',
      sortable: true,
      render: (v: string | undefined) => v || '-',
    },
    {
      field: 'period',
      header: 'Obdobie',
      sortable: true,
      width: '8rem',
      render: (v: string | undefined) => formatPeriod(v),
    },
    {
      field: 'total',
      header: 'Suma',
      sortable: true,
      width: '10rem',
      render: (v: number | undefined) => formatAmount(v),
    },
    {
      field: 'updated_at',
      header: 'Naposledy upravené',
      sortable: true,
      render: (v: string | undefined) => formatDateWithTime(v),
    },
    {
      field: 'actions',
      header: '',
      width: '6rem',
      component: ActionButtons,
      componentOptions: [
        {
          icon: 'bi bi-pencil',
          color: 'accent',
          tooltip: 'Upraviť faktúru',
          action: (row: InvoiceRow) => {
            openEdit(row)
          },
        },
        {
          icon: 'bi bi-eye',
          color: 'info',
          tooltip: 'Zobraziť faktúru',
          action: (row: InvoiceRow) => {
            openInvoiceInNewTab(row)
          },
        },
      ],
    },
  ],
  actions: [
    {
      key: 'add-bulk',
      label: 'Hej, Adonis!',
      tooltip: 'Vytvor všetky faktúry za obdobie',
      adonis: true,
      handler: async () => {
        await openCreateBulk()
      },
    },
    {
      key: 'add',
      label: '',
      tooltip: 'Pridať faktúru',
      icon: 'bi bi-plus',
      class: 'bg-accent!',
      handler: async () => {
        await openCreate()
      },
    },
    {
      key: 'email',
      disabled: ({ selectedRows }: { selectedRows: InvoiceRow[] }) => selectedRows.length === 0,
      icon: 'bi bi-send',
      class: 'bg-accent!',
      tooltip: 'Poslať vybrané faktúry emailom',
      handler: async ({ selectedRows, remote }: { selectedRows: InvoiceRow[]; remote: any }) => {
        await openEmailDocumentsDialog({
          documents: selectedRows,
          remote,
          idKey: 'invoice_ids',
          header: 'Odoslať faktúry emailom',
        })
      },
    },
    {
      key: 'delete',
      disabled: ({ selectedRows }: { selectedRows: InvoiceRow[] }) => selectedRows.length === 0,
      icon: 'bi bi-eraser',
      class: 'bg-danger!',
      confirm: 'Naozaj chcete zmazať vybrané faktúry?',
      handler: async ({ selectedRows, remote }: { selectedRows: InvoiceRow[]; remote: any }) => {
        try {
          await api.delete('v1/invoices', {
            data: {
              ids: selectedRows.map((r) => r.id),
            },
          })
          await remote.loadPage(remote.page)
          toast.add({
            severity: 'success',
            summary: 'Úspech',
            detail: 'Faktúry boli vymazané',
            life: 3000,
          })
        } catch (err) {
          console.error('Error deleting invoices:', err)
          toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Nepodarilo sa vymazať faktúry',
            life: 3000,
          })
        }
      },
    },
  ],
}))
</script>

<template>
  <div class="h-full flex flex-col overflow-hidden min-h-0">
    <UniversalDataTable :options="options">
      <template #invoice-number="{ row, value }">
        <div class="flex items-center gap-2">
          <span v-if="value">{{ value }}</span>
          <button
            v-else
            @click.stop="openAssignNumber(row)"
            class="text-accent hover:text-darkgrey text-sm font-medium cursor-pointer underline"
            title="Priradiť číslo faktúry"
          >
            priradiť
          </button>
        </div>
      </template>
      <template #actions="{ row }">
        <button @click.stop="openInvoiceInNewTab(row)" class="btn btn-sm btn-link p-0" title="Otvoriť faktúru">
          <i class="bi bi-eye"></i>
        </button>
      </template>
    </UniversalDataTable>
  </div>
</template>
