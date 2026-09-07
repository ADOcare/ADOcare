<template>
  <div class="flex flex-col gap-5 py-4">
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin">
        <svg class="w-8 h-8 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
          />
        </svg>
      </div>
    </div>

    <div v-else class="space-y-5">
      <div v-if="billingError" class="rounded-md bg-danger p-4 text-normal text-white">
        {{ billingError }}
      </div>

      <!-- PAYMENT ISSUE - shown only when StudioKristian reports a failed payment. Whether
           access is still FULL (grace) or already READ_ONLY is decided by the backend. -->
      <section
        v-if="paymentIssue"
        class="rounded-md p-5"
        :class="accessStore.isReadOnlyDueToPayment ? 'bg-danger' : 'bg-warning'"
      >
        <h3 class="text-sm text-white mb-2">Problém s platbou</h3>
        <p class="text-normal text-white opacity-90">
          Vašu poslednú platbu sa nepodarilo spracovať.
          <span v-if="accessStore.isReadOnlyDueToPayment">
            Ochranná lehota uplynula, ADOCare je v režime len na čítanie.
          </span>
        </p>

        <div class="grid grid-cols-12 gap-4 mt-4">
          <div v-if="paymentIssue.payment_failed_at" class="col-span-12 md:col-span-3">
            <div class="text-mini uppercase tracking-wide text-white opacity-75 mb-1">Dátum zlyhania</div>
            <div class="text-heading text-white">{{ formatDate(paymentIssue.payment_failed_at) }}</div>
          </div>
          <div v-if="paymentIssue.grace_period_ends_at" class="col-span-12 md:col-span-3">
            <div class="text-mini uppercase tracking-wide text-white opacity-75 mb-1">Ochranná lehota do</div>
            <div class="text-heading text-white">{{ formatDate(paymentIssue.grace_period_ends_at) }}</div>
          </div>
          <div v-if="current?.subscription?.price" class="col-span-12 md:col-span-3">
            <div class="text-mini uppercase tracking-wide text-white opacity-75 mb-1">Suma</div>
            <div class="text-heading text-white">{{ formatPrice(current?.subscription?.price) }}</div>
          </div>
          <div
            v-if="paymentIssue.current_period_start || paymentIssue.current_period_end"
            class="col-span-12 md:col-span-3"
          >
            <div class="text-mini uppercase tracking-wide text-white opacity-75 mb-1">Fakturačné obdobie</div>
            <div class="text-heading text-white">
              {{ formatDate(paymentIssue.current_period_start ?? null) }} – {{ formatDate(paymentIssue.current_period_end ?? null) }}
            </div>
          </div>
        </div>

        <div v-if="paymentIssue.payment_action_required" class="mt-4">
          <Button
            label="Aktualizovať platobnú metódu"
            severity="contrast"
            :loading="paymentPortalLoading"
            @click="openPaymentMethodPortal"
          />
        </div>
      </section>

      <!-- PLAN USAGE - counted by ADOCare, limited by the StudioKristian plan entitlements.
           AI credits show the monthly allowance only; consumption is not metered yet. -->
      <section v-if="usageEntries.length || aiCreditsAllowance" class="bg-tag3 rounded-md p-5">
        <h3 class="text-sm text-accent mb-4">Využitie balíka</h3>

        <div class="grid grid-cols-12 gap-4">
          <div v-for="entry in usageEntries" :key="entry.key" class="col-span-12 md:col-span-3">
            <div class="text-mini uppercase tracking-wide text-lightgrey mb-1">{{ entry.label }}</div>
            <div class="text-heading" :class="entry.overLimit ? 'text-danger' : 'text-white'">
              {{ entry.usage }} / {{ entry.limitLabel }}
            </div>
            <div v-if="entry.overLimit" class="text-mini text-danger mt-1">
              Váš balík povoľuje {{ entry.limitLabel }}. Existujúce záznamy zostávajú zachované.
            </div>
          </div>

          <div v-if="aiCreditsAllowance" class="col-span-12 md:col-span-3">
            <div class="text-mini uppercase tracking-wide text-lightgrey mb-1">AI kredity</div>
            <div class="text-heading text-white">{{ aiCreditsAllowance }} / mesiac</div>
          </div>
        </div>
      </section>

      <!-- CURRENT BILLING STATE - the paid subscription always wins over the trial. -->
      <section class="bg-tag3 rounded-md p-5">
        <h3 class="text-sm text-accent mb-4">Aktuálne predplatné</h3>

        <div v-if="current?.type === 'subscription'" class="flex flex-col gap-4">
          <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12 md:col-span-3">
              <div class="text-mini uppercase tracking-wide text-lightgrey mb-1">Balík</div>
              <div class="text-heading text-white">{{ current.subscription?.plan?.name ?? '—' }}</div>
            </div>
            <div class="col-span-12 md:col-span-3">
              <div class="text-mini uppercase tracking-wide text-lightgrey mb-1">Stav</div>
              <div class="text-heading text-white">{{ formatSubscriptionStatus(current.subscription?.status) }}</div>
            </div>
            <div class="col-span-12 md:col-span-3">
              <div class="text-mini uppercase tracking-wide text-lightgrey mb-1">Cena</div>
              <div class="text-heading text-white">{{ formatPrice(current.subscription?.price) }}</div>
            </div>
            <div v-if="current.subscription?.current_period_start || current.subscription?.current_period_end" class="col-span-12 md:col-span-3">
              <div class="text-mini uppercase tracking-wide text-lightgrey mb-1">Aktuálne obdobie</div>
              <div class="text-heading text-white">
                {{ formatDate(current.subscription?.current_period_start ?? null) }} – {{ formatDate(current.subscription?.current_period_end ?? null) }}
              </div>
            </div>
          </div>

          <div v-if="current.subscription?.scheduled_change" class="text-normal text-lightgrey">
            Váš balík sa zmení na <strong class="text-white">{{ current.subscription.scheduled_change.plan?.name }}</strong>
            ({{ formatPrice(current.subscription.scheduled_change.price) }}) dňa {{ formatDate(current.subscription.scheduled_change.effective_at ?? null) }}.
          </div>

          <div v-if="current.subscription?.cancel_at_period_end" class="flex flex-col md:flex-row md:items-center gap-3 md:justify-between">
            <div class="text-normal text-lightgrey">
              Zrušené k: {{ formatDate(current.subscription?.current_period_end ?? null) }}
            </div>
            <Button
              label="Obnoviť predplatné"
              :loading="resuming"
              class="bg-accent! border-0!"
              @click="confirmResume"
            />
          </div>
          <div v-else-if="current.subscription?.canceled_at" class="text-normal text-lightgrey">
            Zrušené {{ formatDate(current.subscription.canceled_at) }}.
          </div>
          <div v-else class="flex justify-end">
            <Button
              label="Zrušiť predplatné"
              text
              class="text-danger! border-0!"
              @click="confirmCancel"
            />
          </div>

          <div v-if="currentEntitlementEntries.length" class="border-t border-darkgrey pt-4">
            <div class="text-mini uppercase tracking-wide text-lightgrey mb-2">Zahrnuté</div>
            <div class="grid grid-cols-12 gap-3">
              <div v-for="entry in currentEntitlementEntries" :key="entry.key" class="col-span-12 md:col-span-4">
                <div class="text-normal text-lightgrey">{{ formatEntitlementLabel(entry.key) }}</div>
                <div class="text-normal text-white">{{ formatEntitlementValue(entry.entitlement) }}</div>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="current?.type === 'trial'" class="text-normal text-lightgrey">
          Aktuálne využívate skúšobné obdobie - žiadne platené predplatné ešte nie je aktivované.
        </div>

        <div v-else-if="current?.type === 'expired_trial'" class="text-normal text-lightgrey">
          Skúšobné obdobie skončilo. Vyberte si platený balík nižšie a pokračujte v používaní ADOcare.
        </div>

        <div v-else-if="!billingProvisioned" class="text-normal text-lightgrey">
          Fakturačné údaje pre túto spoločnosť ešte neboli nastavené.
        </div>

        <div v-else class="text-normal text-lightgrey">
          Momentálne nemáte žiadne aktívne platené predplatné.
        </div>
      </section>

      <!-- Trial shown as separate historical/informational context only - never overrides the section above. -->
      <section v-if="trial?.active || trial?.expired" class="rounded-md bg-darkgrey p-4">
        <div class="text-mini uppercase tracking-wide text-lightgrey mb-2">Skúšobné obdobie</div>
        <div class="text-heading text-white">
          {{ trial.active ? 'Aktívne' : 'Skončilo' }}
          <span v-if="current?.type === 'subscription'" class="text-normal text-lightgrey font-normal">
            (nahradené platým predplatným)
          </span>
        </div>
      </section>

      <section class="bg-tag3 rounded-md p-5">
        <h3 class="text-sm text-accent mb-4">Dostupné balíky</h3>

        <div v-if="plans.length === 0" class="text-normal text-lightgrey">
          Momentálne nie sú dostupné žiadne balíky.
        </div>

        <div v-else class="grid grid-cols-12 gap-4">
          <div
            v-for="plan in plans"
            :key="plan.id"
            class="col-span-12 md:col-span-6 xl:col-span-4 rounded-md bg-darkgrey p-4 flex flex-col gap-3"
          >
            <div>
              <div class="text-heading text-white">{{ plan.name }}</div>
              <div v-if="plan.description" class="text-normal text-lightgrey mt-1">{{ plan.description }}</div>
            </div>

            <div v-if="entitlementEntries(plan.entitlements).length" class="flex flex-col gap-1">
              <div v-for="entry in entitlementEntries(plan.entitlements)" :key="entry.key" class="text-normal text-lightgrey flex justify-between gap-2">
                <span>{{ formatEntitlementLabel(entry.key) }}</span>
                <span class="text-white">{{ formatEntitlementValue(entry.entitlement) }}</span>
              </div>
            </div>

            <div class="flex flex-col gap-2 mt-auto">
              <div
                v-for="price in plan.prices"
                :key="price.id"
                class="flex items-center justify-between rounded-md bg-tag3 px-3 py-2"
              >
                <span class="text-normal text-white">{{ formatPrice(price) }}</span>
                <span v-if="isCurrentPrice(price.id)" class="text-normal text-lightgrey">Aktuálny</span>
                <Button
                  v-else
                  :label="planActionLabel(price)"
                  :loading="planActionLoadingPriceId === price.id"
                  class="bg-accent! border-0!"
                  @click="onPlanActionClick(price)"
                />
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="bg-tag3 rounded-md p-5">
        <h3 class="text-sm text-accent mb-4">História platieb</h3>

        <div v-if="payments.length === 0" class="text-normal text-lightgrey">
          Zatiaľ nemáte žiadne platby.
        </div>

        <table v-else class="w-full text-normal text-white">
          <thead>
            <tr class="text-mini uppercase tracking-wide text-lightgrey text-left">
              <th class="pb-2">Dátum</th>
              <th class="pb-2">Suma</th>
              <th class="pb-2">Stav</th>
              <th class="pb-2">Spôsob platby</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="payment in payments" :key="payment.id" class="border-t border-darkgrey">
              <td class="py-2">{{ formatDate(payment.date) }}</td>
              <td class="py-2">{{ formatCurrency(payment.amount, payment.currency) }}</td>
              <td class="py-2">{{ formatPaymentStatus(payment.status) }}</td>
              <td class="py-2">{{ formatPaymentMethod(payment.payment_method) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="bg-tag3 rounded-md p-5">
        <h3 class="text-sm text-accent mb-4">Faktúry</h3>

        <div v-if="invoices.length === 0" class="text-normal text-lightgrey">
          Zatiaľ nemáte žiadne faktúry.
        </div>

        <table v-else class="w-full text-normal text-white">
          <thead>
            <tr class="text-mini uppercase tracking-wide text-lightgrey text-left">
              <th class="pb-2">Faktúra</th>
              <th class="pb-2">Dátum</th>
              <th class="pb-2">Suma</th>
              <th class="pb-2">Stav</th>
              <th class="pb-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="invoice in invoices" :key="invoice.id" class="border-t border-darkgrey">
              <td class="py-2">{{ invoice.number ?? '—' }}</td>
              <td class="py-2">{{ formatDate(invoice.date) }}</td>
              <td class="py-2">{{ formatCurrency(invoice.amount_paid ?? invoice.amount_due, invoice.currency) }}</td>
              <td class="py-2">{{ formatPaymentStatus(invoice.status) }}</td>
              <td class="py-2 text-right whitespace-nowrap">
                <a v-if="invoice.view_url" :href="invoice.view_url" target="_blank" rel="noopener" class="text-accent underline mr-3">Zobraziť</a>
                <a v-if="invoice.pdf_url" :href="invoice.pdf_url" target="_blank" rel="noopener" class="text-accent underline">PDF</a>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="bg-tag3 rounded-md p-5">
        <div class="mb-4">
          <h3 class="text-sm text-accent">História platieb (legacy)</h3>
        </div>

        <UniversalDataTable :options="paymentTableOptions" />
      </section>
    </div>

    <Dialog v-model:visible="changeDialogVisible" :modal="true" :closable="false" header="Zmena balíka" :style="{ width: '520px' }">
      <div v-if="pendingPrice" class="flex flex-col gap-4">
        <p class="text-normal">
          {{ pendingIsUpgrade ? 'Prejsť na balík' : 'Zmeniť balík na' }}
          <strong>{{ pendingPrice.planName }}</strong> ({{ formatPrice(pendingPrice) }})?
        </p>
        <p v-if="pendingIsUpgrade" class="text-normal text-lightgrey">
          Zmena sa uplatní okamžite. Prípadný rozdiel v cene vyráta a naúčtuje StudioKristian/Stripe.
        </p>
        <p v-else class="text-normal text-lightgrey">
          Zostávate na aktuálnom balíku až do konca aktuálneho zúčtovacieho obdobia
          ({{ formatDate(current?.subscription?.current_period_end ?? null) }}). Nový balík sa aktivuje potom.
        </p>
        <div class="flex items-center gap-2 justify-end">
          <Button label="Zrušiť" text class="bg-accent! px-4! text-white! hover:bg-darkgrey! border-0!" @click="changeDialogVisible = false" />
          <Button label="Potvrdiť" :loading="planActionLoadingPriceId === pendingPrice.id" class="bg-accent! px-4! border-0!" @click="confirmPlanChange" />
        </div>
      </div>
    </Dialog>

    <Dialog v-model:visible="cancelDialogVisible" :modal="true" :closable="false" header="Zrušiť predplatné?" :style="{ width: '520px' }">
      <div class="flex flex-col gap-4">
        <p class="text-normal">
          Vaše predplatné zostane aktívne do {{ formatDate(current?.subscription?.current_period_end ?? null) }}.
        </p>
        <p class="text-normal text-lightgrey">Po tomto dátume vaše predplatné skončí.</p>
        <div class="flex items-center gap-2 justify-end">
          <Button label="Nie" text class="bg-accent! px-4! text-white! hover:bg-darkgrey! border-0!" @click="cancelDialogVisible = false" />
          <Button label="Zrušiť predplatné" :loading="cancelling" class="bg-danger! px-4! border-0!" @click="doCancel" />
        </div>
      </div>
    </Dialog>

    <Dialog v-model:visible="resumeDialogVisible" :modal="true" :closable="false" header="Obnoviť predplatné?" :style="{ width: '520px' }">
      <div class="flex flex-col gap-4">
        <p class="text-normal">Vaše predplatné bude pokračovať normálne.</p>
        <div class="flex items-center gap-2 justify-end">
          <Button label="Nie" text class="bg-accent! px-4! text-white! hover:bg-darkgrey! border-0!" @click="resumeDialogVisible = false" />
          <Button label="Obnoviť" :loading="resuming" class="bg-accent! px-4! border-0!" @click="doResume" />
        </div>
      </div>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import api from '@/services/api'
import { useAccessStore } from '@/stores/access'
import type { DataTableOptions } from '@/types/datatable'

interface Entitlement {
  type: 'boolean' | 'limit' | 'unlimited' | 'custom' | 'unknown'
  value?: number | boolean
  unit?: string
}

type Entitlements = Record<string, Entitlement>

interface PlanPrice {
  id: number
  amount: number
  currency: string
  interval: string
}

interface Plan {
  id: number
  name: string
  description?: string | null
  features?: string[]
  prices: PlanPrice[]
  entitlements?: Entitlements
}

interface ScheduledChange {
  plan?: { id: number; name: string } | null
  price?: PlanPrice | null
  effective_at?: string | null
}

interface Subscription {
  id: number
  status: string
  plan?: { id: number; name: string } | null
  price?: PlanPrice | null
  entitlements?: Entitlements
  current_period_start?: string | null
  current_period_end?: string | null
  canceled_at?: string | null
  ended_at?: string | null
  cancel_at_period_end?: boolean
  scheduled_change?: ScheduledChange | null
  payment_status?: string | null
  payment_failed_at?: string | null
  grace_period_ends_at?: string | null
  payment_action_required?: boolean
}

interface PaymentMethod {
  type?: string | null
  brand?: string | null
  last4?: string | null
}

interface Payment {
  id: number
  date: string | null
  amount: number
  currency: string
  status: string
  payment_method?: PaymentMethod | null
  invoice_id?: number | null
}

interface Invoice {
  id: number
  number?: string | null
  date: string | null
  amount_due: number
  amount_paid: number
  currency: string
  status: string
  period_start?: string | null
  period_end?: string | null
  view_url?: string | null
  pdf_url?: string | null
}

interface TrialState {
  active: boolean
  expired?: boolean
}

interface CurrentBillingState {
  type: 'subscription' | 'trial' | 'expired_trial' | 'none'
  subscription?: Subscription
  trial?: TrialState
}

const accessStore = useAccessStore()

const loading = ref(false)
const paymentPortalLoading = ref(false)
const billingError = ref<string | null>(null)
const trial = ref<TrialState | null>(null)
const current = ref<CurrentBillingState | null>(null)
const billingProvisioned = ref(false)
const payments = ref<Payment[]>([])
const invoices = ref<Invoice[]>([])
const plans = ref<Plan[]>([])
const checkoutLoadingPriceId = ref<number | null>(null)
const planActionLoadingPriceId = ref<number | null>(null)
const cancelling = ref(false)
const resuming = ref(false)

const changeDialogVisible = ref(false)
const cancelDialogVisible = ref(false)
const resumeDialogVisible = ref(false)
const pendingPrice = ref<(PlanPrice & { planName: string }) | null>(null)
const pendingIsUpgrade = ref(true)

const currentEntitlementEntries = computed(() => entitlementEntries(current.value?.subscription?.entitlements))

// Only StudioKristian decides there is a payment problem - never a local inference.
const paymentIssue = computed(() => {
  const subscription = current.value?.subscription
  if (!subscription || subscription.status?.toLowerCase() !== 'past_due') return null

  return subscription
})

const USAGE_LABELS: Record<string, string> = {
  users: 'Používatelia',
  managers: 'Manažéri',
  branches: 'Pobočky',
}

const usageEntries = computed(() =>
  Object.entries(accessStore.usage).map(([key, usage]) => ({
    key,
    label: USAGE_LABELS[key] ?? formatEntitlementLabel(key),
    usage: usage.usage,
    limitLabel: usage.unlimited ? 'neobmedzene' : String(usage.limit ?? '—'),
    overLimit: usage.over_limit,
  })),
)

// Allowance only - never presented as a remaining balance.
const aiCreditsAllowance = computed(() => {
  const entitlement = accessStore.entitlements['ai_credits']
  if (!entitlement || entitlement.type !== 'limit' || typeof entitlement.value !== 'number') return null

  return new Intl.NumberFormat('sk-SK').format(entitlement.value)
})

function entitlementEntries(entitlements: Entitlements | undefined): Array<{ key: string; entitlement: Entitlement }> {
  if (!entitlements) return []
  return Object.entries(entitlements).map(([key, entitlement]) => ({ key, entitlement }))
}

function formatEntitlementLabel(key: string): string {
  return key
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

function formatEntitlementValue(entitlement: Entitlement): string {
  switch (entitlement.type) {
    case 'unlimited':
      return 'Neobmedzené'
    case 'boolean':
      return entitlement.value ? 'Zahrnuté' : 'Nezahrnuté'
    case 'limit':
      return entitlement.unit ? `${entitlement.value} ${entitlement.unit}` : `${entitlement.value}`
    case 'custom':
      return 'Individuálne'
    default:
      return '—'
  }
}

function isCurrentPrice(priceId: number): boolean {
  return current.value?.type === 'subscription' && current.value.subscription?.price?.id === priceId
}

function planActionLabel(price: PlanPrice): string {
  if (current.value?.type !== 'subscription') return 'Vybrať'

  const currentAmount = current.value.subscription?.price?.amount ?? 0
  return price.amount >= currentAmount ? 'Upgradovať' : 'Znížiť balík'
}

const paymentTableOptions = computed<DataTableOptions<any>>(() => ({
  endpointUrl: 'v1/my-company/subscription-payments',
  defaultPageSize: 10,
  columns: [
    {
      field: 'received_at',
      header: 'Dátum prijatia',
      sortable: true,
      render: (value) => formatDate((value as string | null) ?? null),
    },
    {
      field: 'amount',
      header: 'Suma (EUR)',
      sortable: true,
      render: (value) => formatCurrency((value as number | null) ?? null),
    },
    {
      field: 'notes',
      header: 'Poznámka',
      render: (value) => ((value as string | null) || '—'),
    },
  ],
}))

onMounted(async () => {
  await loadBillingData()
})

async function loadBillingData() {
  loading.value = true
  billingError.value = null

  try {
    const [subscriptionRes, plansRes] = await Promise.all([
      api.get('v1/billing/subscription'),
      api.get('v1/billing/plans'),
    ])

    applyBillingResponse(subscriptionRes)
    plans.value = plansRes.data?.data ?? []
  } catch (error: any) {
    console.error('Error loading billing data:', error)
    billingError.value = error?.response?.data?.message ?? 'Nepodarilo sa načítať fakturačné údaje.'
  } finally {
    loading.value = false
  }
}

async function startCheckout(planPriceId: number) {
  checkoutLoadingPriceId.value = planPriceId
  billingError.value = null

  try {
    const res = await api.post('v1/billing/checkout', {
      plan_price_id: planPriceId,
      success_url: `${window.location.origin}/billing/success`,
      cancel_url: `${window.location.origin}/billing/cancel`,
    })

    const checkoutUrl: string | undefined = res.data?.data?.checkout_url

    // Creating the Checkout Session is not the same as paying - this only lets us send
    // the user to Stripe. The actual subscription only activates via StudioKristian's
    // own webhook processing once Stripe confirms payment.
    if (!checkoutUrl || !checkoutUrl.startsWith('https://')) {
      billingError.value = 'Fakturačná služba nevrátila platnú platobnú URL. Skúste to prosím znova.'
      return
    }

    window.location.href = checkoutUrl
  } catch (error: any) {
    console.error('Error starting checkout:', error)
    billingError.value = error?.response?.data?.message ?? 'Nepodarilo sa spustiť platbu.'
  } finally {
    checkoutLoadingPriceId.value = null
  }
}

function onPlanActionClick(price: PlanPrice) {
  if (current.value?.type !== 'subscription') {
    startCheckout(price.id)
    return
  }

  const plan = plans.value.find((p) => p.prices.some((pr) => pr.id === price.id))
  const currentAmount = current.value.subscription?.price?.amount ?? 0

  pendingPrice.value = { ...price, planName: plan?.name ?? '—' }
  pendingIsUpgrade.value = price.amount >= currentAmount
  changeDialogVisible.value = true
}

async function confirmPlanChange() {
  if (!pendingPrice.value) return

  const priceId = pendingPrice.value.id
  planActionLoadingPriceId.value = priceId
  billingError.value = null

  try {
    const res = await api.post('v1/billing/subscription/change', { plan_price_id: priceId })
    applyBillingResponse(res)
    changeDialogVisible.value = false
  } catch (error: any) {
    console.error('Error changing subscription:', error)
    billingError.value = error?.response?.data?.message ?? 'Zmena balíka sa nepodarila.'
  } finally {
    planActionLoadingPriceId.value = null
    pendingPrice.value = null
  }
}

function confirmCancel() {
  cancelDialogVisible.value = true
}

async function doCancel() {
  cancelling.value = true
  billingError.value = null

  try {
    const res = await api.post('v1/billing/subscription/cancel')
    applyBillingResponse(res)
    cancelDialogVisible.value = false
  } catch (error: any) {
    console.error('Error cancelling subscription:', error)
    billingError.value = error?.response?.data?.message ?? 'Zrušenie predplatného sa nepodarilo.'
  } finally {
    cancelling.value = false
  }
}

function confirmResume() {
  resumeDialogVisible.value = true
}

async function doResume() {
  resuming.value = true
  billingError.value = null

  try {
    const res = await api.post('v1/billing/subscription/resume')
    applyBillingResponse(res)
    resumeDialogVisible.value = false
  } catch (error: any) {
    console.error('Error resuming subscription:', error)
    billingError.value = error?.response?.data?.message ?? 'Obnovenie predplatného sa nepodarilo.'
  } finally {
    resuming.value = false
  }
}

// Every subscription-changing action returns the same full, authoritative billing state -
// the UI is always replaced with what StudioKristian just confirmed, never mutated blindly.
function applyBillingResponse(res: any) {
  trial.value = res.data?.data?.trial ?? null
  current.value = res.data?.data?.current ?? null
  billingProvisioned.value = Boolean(res.data?.data?.billing_provisioned)
  payments.value = res.data?.data?.payments ?? []
  invoices.value = res.data?.data?.invoices ?? []

  // The access state travels with the same authoritative payload - renewing a subscription
  // lifts read-only mode because StudioKristian confirmed it, never because the UI decided so.
  accessStore.applyFromBillingResponse(res.data?.data?.access ?? null)
}

function formatPrice(price: PlanPrice | null | undefined): string {
  if (!price) return '—'
  return `${formatCurrency(price.amount, price.currency)} / ${formatInterval(price.interval)}`
}

function formatInterval(interval: string): string {
  const labels: Record<string, string> = {
    month: 'mesiac',
    monthly: 'mesiac',
    year: 'rok',
    yearly: 'rok',
  }
  return labels[interval] ?? interval
}

// StudioKristian/Stripe amounts are always in the smallest currency unit (cents for EUR).
function formatCurrency(value: number | null | undefined, currency = 'EUR'): string {
  if (value === null || value === undefined) return '—'
  return `${(Number(value) / 100).toFixed(2)} ${currency}`
}

function formatDate(value: string | null): string {
  if (!value) return '—'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'

  return date.toLocaleDateString('sk-SK', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

function formatSubscriptionStatus(status: string | undefined): string {
  const labels: Record<string, string> = {
    active: 'Aktívne',
    trialing: 'Trial',
    past_due: 'Po splatnosti',
    canceled: 'Zrušené',
    cancelled: 'Zrušené',
    unpaid: 'Nezaplatené',
  }

  return labels[(status ?? '').toLowerCase()] || (status || '—')
}

function formatPaymentStatus(status: string | undefined | null): string {
  const labels: Record<string, string> = {
    paid: 'Zaplatené',
    open: 'Otvorená',
    pending: 'Čaká sa',
    failed: 'Zlyhala',
    void: 'Zrušená',
    uncollectible: 'Nevymožiteľná',
  }

  return labels[(status ?? '').toLowerCase()] || (status || '—')
}

// StudioKristian returns {type, brand, last4} (or null) - only what Stripe safely exposes.
// No expiry date is provided, so none is shown.
function formatPaymentMethod(method: PaymentMethod | null | undefined): string {
  if (!method) return '—'

  const brand = method.brand ? method.brand.charAt(0).toUpperCase() + method.brand.slice(1) : method.type
  if (!brand && !method.last4) return '—'
  if (!method.last4) return brand ?? '—'

  return `${brand ?? 'Karta'} •••• ${method.last4}`
}

async function openPaymentMethodPortal() {
  paymentPortalLoading.value = true
  billingError.value = ''
  try {
    await accessStore.openPaymentMethodPortal('/billing')
  } catch (e: any) {
    billingError.value = e?.response?.data?.message ?? e?.message ?? 'Nepodarilo sa otvoriť platobnú bránu.'
  } finally {
    paymentPortalLoading.value = false
  }
}
</script>

