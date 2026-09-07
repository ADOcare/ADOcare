<template>
  <div
    v-if="visible"
    class="flex flex-wrap items-center justify-between gap-3 rounded-md bg-warning px-4 py-3 text-normal text-white"
  >
    <div class="flex items-center gap-3">
      <i class="pi pi-exclamation-triangle text-white" />
      <div>
        <div class="font-semibold">Problém s platbou</div>
        <div class="text-mini opacity-90">
          Vašu poslednú platbu sa nepodarilo spracovať. Aktualizujte prosím platobnú metódu,
          aby vaše predplatné zostalo aktívne.<span v-if="graceEndsAt">
            Prístup zostáva plne funkčný do {{ graceEndsAt }}.</span>
        </div>
      </div>
    </div>

    <Button
      label="Aktualizovať platobnú metódu"
      size="small"
      severity="contrast"
      :loading="opening"
      @click="openPortal"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import { useAccessStore } from '@/stores/access'
import { useAuthStore } from '@/stores/auth'

const access = useAccessStore()
const auth = useAuthStore()
const route = useRoute()
const toast = useToast()

const opening = ref(false)

// Only while the backend says access is still FULL - once grace expires the read-only
// banner takes over instead.
const visible = computed(() => auth.isAuthenticated && access.isInPaymentGracePeriod)

const graceEndsAt = computed(() => {
  const value = access.payment?.grace_period_ends_at
  if (!value) return null

  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : new Intl.DateTimeFormat('sk-SK').format(date)
})

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
