<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import {
    automaticInvoiceDates,
    defaultInvoiceDueDate,
    formatSlovakDate,
    latestInvoiceIssueDate,
    serviceDeliveryDate,
    toApiDate,
    validateInvoiceDates,
} from '@/utils/invoiceDates'

type InsuranceCompanyOption = {
  id: number
  name: string
}

const props = defineProps<{ initialPeriod?: Date | null; modalResolve?: (value?: any) => void }>()

const toast = useToast()
const saving = ref(false)
const loadingCompanies = ref(false)
const period = ref<Date | null>(props.initialPeriod ?? null)
const insuranceCompanies = ref<InsuranceCompanyOption[]>([])
const now = new Date()
const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
const issuedAt = ref<Date | null>(today)
const sentAt = ref<Date | null>(today)
const dueDate = ref<Date | null>(null)
const deliveryDate = computed(() => serviceDeliveryDate(period.value))
const latestIssueDate = computed(() => latestInvoiceIssueDate(period.value))

watch(
  period,
  (selectedPeriod) => {
    if (!selectedPeriod) {
      issuedAt.value = null
      sentAt.value = null
      dueDate.value = null
      return
    }

    const automatic = automaticInvoiceDates(selectedPeriod)
    issuedAt.value = automatic.issuedAt
    sentAt.value = automatic.sentAt
    dueDate.value = automatic.dueDate
  },
  { immediate: true },
)

watch(sentAt, (value) => {
  dueDate.value = defaultInvoiceDueDate(value)
})

void loadInsuranceCompanies()

function toApiMonth(date: Date | null): string | null {
  if (!date) return null
  const year = date.getFullYear()
  const month = `${date.getMonth() + 1}`.padStart(2, '0')
  return `${year}-${month}`
}

function close(result?: any) {
  if (props.modalResolve) {
    try {
      props.modalResolve(result)
    } catch {
      // ignore modal resolve issues
    }
  }
}

async function loadInsuranceCompanies() {
  try {
    loadingCompanies.value = true
    const response = await api.get('/v1/insurance-companies', { params: { paginate: 0 } })
    const payload = response.data?.data
    insuranceCompanies.value = (payload?.items ?? payload ?? []) as InsuranceCompanyOption[]
  } catch (err) {
    console.error('Failed to load insurance companies', err)
    insuranceCompanies.value = []
  } finally {
    loadingCompanies.value = false
  }
}

async function submit() {
  const periodValue = toApiMonth(period.value)

  if (!periodValue) {
    toast.add({ severity: 'error', summary: 'Chyba', detail: 'Vyberte obdobie.', life: 3500 })
    return
  }

  if (!insuranceCompanies.value.length) {
    toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nie sú dostupné žiadne poisťovne.', life: 3500 })
    return
  }

  const dateError = validateInvoiceDates(
    period.value,
    'procedures',
    issuedAt.value,
    sentAt.value,
    dueDate.value,
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
    const invoiceTypes: Array<'procedures' | 'transport'> = ['procedures', 'transport']
    let successCount = 0
    let failedCount = 0
    let firstErrorMessage = ''
    let stoppedEarly = false

    for (const company of insuranceCompanies.value) {
      for (const type of invoiceTypes) {
        try {
          await api.post('/v1/invoices', {
            insurance_company_id: company.id,
            period: periodValue,
            type,
            issued_at: toApiDate(issuedAt.value),
            sent_at: toApiDate(sentAt.value),
            due_date: toApiDate(dueDate.value),
          })

          successCount += 1
        } catch (error: any) {
          failedCount += 1

          const responseData = error?.response?.data
          const validationErrors = responseData?.errors
          const validationMessage = validationErrors && typeof validationErrors === 'object'
            ? Object.values(validationErrors).flat().map(String)[0]
            : null

          firstErrorMessage = validationMessage
            ?? responseData?.message
            ?? 'Nepodarilo sa vytvoriť faktúru.'

          console.error('Bulk invoice create failed', {
            companyId: company.id,
            type,
            status: error?.response?.status,
            response: responseData,
          })

          stoppedEarly = true
          break
        }
      }

      if (stoppedEarly) {
        break
      }
    }

    if (failedCount === 0) {
      toast.add({
        severity: 'success',
        summary: 'Uložené',
        detail: `Vytvorených faktúr: ${successCount}.`,
        life: 3500,
      })
    } else {
      toast.add({
        severity: 'error',
        summary: 'Vytváranie bolo zastavené',
        detail: firstErrorMessage,
        life: 15000,
      })
    }

    close({ created: successCount, failed: failedCount })
  } catch (err) {
    console.error('Bulk invoice create failed', err)
    toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa vytvoriť faktúry.', life: 4000 })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="grid grid-cols-12 gap-4 p-2">
    <div class="col-span-12">
      <label class="block text-normal mb-1">Obdobie</label>
      <DatePicker
        v-model="period"
        view="month"
        dateFormat="mm/yy"
        :manualInput="false"
        class="w-full"
        inputClass="w-full!"
      />
    </div>

    <div class="col-span-12">
      <label class="block text-normal mb-1">Dátum dodania služby</label>
      <InputText :model-value="formatSlovakDate(deliveryDate)" disabled fluid />
      <small class="text-muted">Automaticky posledný deň vybraného mesiaca.</small>
    </div>

    <div class="col-span-12 md:col-span-6">
      <label class="block text-normal mb-1">Dátum vystavenia *</label>
      <DatePicker
        v-model="issuedAt"
        dateFormat="dd.mm.yy"
        :manualInput="false"
        :minDate="deliveryDate ?? undefined"
        :maxDate="latestIssueDate ?? undefined"
        class="w-full"
        inputClass="w-full!"
      />
      <small v-if="latestIssueDate" class="text-muted">
        Najneskôr {{ formatSlovakDate(latestIssueDate) }}.
      </small>
    </div>

    <div class="col-span-12 md:col-span-6">
      <label class="block text-normal mb-1">Dátum odoslania *</label>
      <DatePicker
        v-model="sentAt"
        dateFormat="dd.mm.yy"
        :manualInput="false"
        :minDate="issuedAt ?? undefined"
        class="w-full"
        inputClass="w-full!"
      />
    </div>

    <div class="col-span-12">
      <label class="block text-normal mb-1">Dátum splatnosti *</label>
      <DatePicker
        v-model="dueDate"
        dateFormat="dd.mm.yy"
        :manualInput="false"
        :minDate="issuedAt ?? undefined"
        class="w-full"
        inputClass="w-full!"
      />
      <small class="text-muted">
        Automaticky 30 dní od odoslania; upravte podľa zmluvy s poisťovňou.
      </small>
    </div>

    <div class="col-span-12 mt-4 flex items-center justify-end gap-2">
      <Button label="Zrušiť" text @click="close()" class="text-accent! px-2!" />
      <Button
        label="Vytvoriť všetky"
        :loading="saving || loadingCompanies"
        @click="submit"
        class="bg-accent! border-accent! px-2! hover:bg-darkgrey! hover:border-darkgrey! text-white!"
      />
    </div>
  </div>
</template>
