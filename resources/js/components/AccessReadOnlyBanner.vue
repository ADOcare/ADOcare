<template>
  <div
    v-if="visible"
    class="flex flex-wrap items-center justify-between gap-3 rounded-md bg-danger px-4 py-3 text-normal text-white"
  >
    <div class="flex items-center gap-3">
      <i class="pi pi-lock text-white" />
      <div>
        <div class="font-semibold">{{ title }}</div>
        <div class="text-mini opacity-90">{{ description }}</div>
      </div>
    </div>

    <div class="flex items-center gap-2">
      <Button
        v-if="causedByPayment"
        label="Aktualizovať platobnú metódu"
        size="small"
        severity="contrast"
        :loading="opening"
        @click="openPortal"
      />
      <Button
        :label="causedByPayment ? 'Zobraziť fakturáciu' : 'Obnoviť predplatné'"
        size="small"
        severity="contrast"
        :outlined="causedByPayment"
        @click="goToBilling"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import { useAccessStore } from '@/stores/access'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const toast = useToast()
const access = useAccessStore()
const auth = useAuthStore()

const opening = ref(false)

const visible = computed(() => auth.isAuthenticated && access.isReadOnly)
const causedByPayment = computed(() => access.isReadOnlyDueToPayment)

const title = computed(() => {
  if (causedByPayment.value) return 'Platba zlyhala'

  return access.restrictionReason === 'trial_expired'
    ? 'Skúšobné obdobie skončilo'
    : 'Vaše predplatné skončilo'
})

const description = computed(() =>
  causedByPayment.value
    ? 'Vašu platbu sa nepodarilo dokončiť a ochranná lehota uplynula. ADOCare je teraz v režime len na čítanie. Vaše dáta sú v bezpečí.'
    : 'ADOCare je momentálne v režime len na čítanie. Vaše dáta sú v bezpečí – zostávajú prístupné, nie je však možné vytvárať ani upravovať záznamy.',
)

function goToBilling() {
  router.push({ name: 'billing' })
}

async function openPortal() {
  opening.value = true
  try {
    await access.openPaymentMethodPortal(typeof route.path === 'string' ? route.path : '/billing')
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: 'Chyba',
      detail: e?.response?.data?.message ?? e?.message ?? 'Nepodarilo sa otvoriť platobnú bránu.',
      life: 6000,
    })
  } finally {
    opening.value = false
  }
}
</script>
