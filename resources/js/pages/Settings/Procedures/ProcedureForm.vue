<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import api from '@/services/api'
import type { InsuranceCompany, Role } from '@/types/models'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'primevue/usetoast'
import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'
import ProcedurePriceEffectiveDateDialog, {
    type ProcedurePriceChange,
} from './ProcedurePriceEffectiveDateDialog.vue'
import ProcedurePriceHistoryDialog from './ProcedurePriceHistoryDialog.vue'

type HistoricalPrice = {
    insurance_company_id: number
    price: string | number | null
}

const props = withDefaults(defineProps<{
    procedure?: Record<string, any> | null
    modalResolve?: (value?: any) => void
    historyMode?: boolean
    historicalPrices?: HistoricalPrice[] | null
    highlightedInsuranceCompanyIds?: number[]
}>(), {
    procedure: null,
    modalResolve: undefined,
    historyMode: false,
    historicalPrices: null,
    highlightedInsuranceCompanyIds: () => [],
})

const emit = defineEmits(['save', 'close'])
const authStore = useAuthStore()
const toast = useToast()

const canEditBaseFields = computed(() => !props.historyMode && authStore.isSuperadmin)
const canEditPrices = computed(() => !props.historyMode && authStore.isManager)
const showPrices = computed(() => props.historyMode || !authStore.isSuperadmin)

const baseFieldClass = computed(() =>
    canEditBaseFields.value
        ? 'w-full'
        : 'w-full border-lightgrey! text-lightgrey!'
)

const loading = ref(false)
const insuranceCompanies = ref<InsuranceCompany[]>([])
const branchRoles = ref<Role[]>([])
const _originalSnapshot = ref<string | null>(null)
const priceValues = ref<Record<number, number>>({})
const originalPriceValues = ref<Record<number, number>>({})
const effectiveDateDialogVisible = ref(false)
const historyDialogVisible = ref(false)

const model = ref({
    id: null as number | null,
    code: '',
    description: '',
    prices: [] as Array<{ insurance_company_id: number; price: number }>,
    role_ids: [] as number[],
})

const procedureRoleLabels: Record<string, string> = {
    nurse: 'Zdravotná sestra',
    physiotherapist: 'Fyzioterapeut',
}

function procedureRoleLabel(role: Role): string {
    const position = String(role.position ?? '').toLowerCase()
    return procedureRoleLabels[position] ?? role.name ?? role.position
}

function normalizePrice(value: unknown): number {
    const n = Number(value)
    return Number.isFinite(n) ? n : 0
}

function buildPricesPayload() {
    return insuranceCompanies.value.map((ic: any) => {
        const id = Number(ic.id)
        return {
            insurance_company_id: id,
            price: normalizePrice(priceValues.value[id]),
        }
    })
}

const changedPrices = computed<ProcedurePriceChange[]>(() =>
    insuranceCompanies.value
        .map((insuranceCompany) => {
            const id = Number(insuranceCompany.id)
            return {
                insuranceCompanyId: id,
                insuranceCompanyName: insuranceCompany.name ?? String(id),
                previousPrice: normalizePrice(originalPriceValues.value[id]),
                newPrice: normalizePrice(priceValues.value[id]),
            }
        })
        .filter((change) => change.previousPrice !== change.newPrice),
)

function makeSnapshot() {
    return JSON.stringify({
        code: model.value.code,
        description: model.value.description,
        role_ids: [...model.value.role_ids].sort((a, b) => a - b),
        prices: buildPricesPayload().map((p) => ({
            insurance_company_id: Number(p.insurance_company_id),
            price: Number(p.price),
        })),
    })
}

function initializeModel() {
    const existing = props.historicalPrices
        ? props.historicalPrices.map((price) => ({
            id: price.insurance_company_id,
            pivot: { price: price.price },
        }))
        : (props.procedure?.insurance_companies_prices_minimal ?? [])

    model.value.id = props.procedure?.id ?? null
    model.value.code = props.procedure?.code ?? ''
    model.value.description = props.procedure?.description ?? ''
    model.value.role_ids = (props.procedure?.roles ?? []).map((role: Role) => Number(role.id))

    model.value.prices = insuranceCompanies.value.map((insuranceCompany) => {
        const found = existing.find(
            (price: any) => Number(price.id) === Number(insuranceCompany.id),
        )

        return {
            insurance_company_id: Number(insuranceCompany.id),
            price: normalizePrice(found?.pivot?.price),
        }
    })

    const nextPriceValues: Record<number, number> = {}
    model.value.prices.forEach((price) => {
        nextPriceValues[Number(price.insurance_company_id)] = normalizePrice(price.price)
    })
    priceValues.value = nextPriceValues
    originalPriceValues.value = { ...nextPriceValues }
    _originalSnapshot.value = makeSnapshot()
}

function priceHighlightPt(insuranceCompanyId: number) {
    if (!props.highlightedInsuranceCompanyIds.includes(insuranceCompanyId)) return undefined

    return {
        pcInputText: {
            root: {
                style: [
                    'background-color: var(--c-tag-3) !important',
                ].join('; '),
            },
        },
    }
}

function saveErrorDetail(error: any): string {
    const errors = error?.response?.data?.errors
    if (errors && typeof errors === 'object') {
        const firstError = Object.values(errors)
            .flat()
            .find((message) => typeof message === 'string')
        if (typeof firstError === 'string') return firstError
    }

    return error?.response?.data?.message || 'Výkon sa nepodarilo uložiť.'
}

onMounted(async () => {
    let companies: InsuranceCompany[] = []

    try {
        const [loadedCompanies, loadedRoles] = await Promise.all([
            api.fetchEntities<InsuranceCompany>('v1/insurance-companies'),
            api.fetchEntities<Role>('v1/roles/branch'),
        ])
        companies = loadedCompanies
        branchRoles.value = loadedRoles
    } catch {
        companies = []
    }

    insuranceCompanies.value = companies

    initializeModel()
})

watch(
    () => props.historicalPrices,
    () => {
        if (props.historyMode && insuranceCompanies.value.length > 0) initializeModel()
    },
    { deep: true },
)

function close() {
    if (props.modalResolve) {
        try {
            props.modalResolve(undefined)
        } catch {}
    } else {
        emit('close')
    }
}

async function save() {
    if (model.value.id && canEditPrices.value && changedPrices.value.length > 0) {
        effectiveDateDialogVisible.value = true
        return
    }

    await persist()
}

async function confirmEffectiveDates(effectiveDates: Record<number, string>) {
    effectiveDateDialogVisible.value = false
    await persist(effectiveDates)
}

async function persist(effectiveDates: Record<number, string> = {}) {
    loading.value = true

    try {
        const payload: Record<string, any> = {}

        if (authStore.isSuperadmin) {
            payload.code = model.value.code?.toString().trim()
            payload.description = model.value.description?.toString().trim()
            payload.role_ids = model.value.role_ids
        }

        if (authStore.isManager) {
            payload.prices = buildPricesPayload().map((price) => ({
                ...price,
                effective_date: effectiveDates[price.insurance_company_id] ?? null,
            }))
        }

        if (model.value.id) {
            await api.put(`/v1/procedures/${model.value.id}`, payload)
        } else {
            await api.post('/v1/procedures', {
                code: model.value.code?.toString().trim(),
                description: model.value.description?.toString().trim(),
                role_ids: model.value.role_ids,
                prices: buildPricesPayload(),
            })
        }

        const snapshot = makeSnapshot()

        const changed = _originalSnapshot.value !== null
            ? _originalSnapshot.value !== snapshot
            : true

        if (props.modalResolve) {
            try {
                props.modalResolve({ changed, model: model.value })
            } catch {}
        } else {
            emit('save')
        }
        toast.add({
            severity: 'success',
            summary: 'Uložené',
            detail: 'Výkon bol uložený.',
            life: 3000,
        })
    } catch (e) {
        console.error('Failed to save procedure', e, (e as any)?.response?.data)
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: saveErrorDetail(e),
            life: 4000,
        })
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <div class="p-4">
        <div class="flex flex-col gap-4">
            <FormField label="Kód" :disabled="!canEditBaseFields">
                <InputText
                    v-model.trim="model.code"
                    :class="baseFieldClass"
                    :disabled="!canEditBaseFields"
                />
            </FormField>

            <FormField label="Popis" :disabled="!canEditBaseFields">
                <Textarea
                    v-model.trim="model.description"
                    :rows="3"
                    autoResize
                    :class="baseFieldClass"
                    :disabled="!canEditBaseFields"
                />
            </FormField>

            <FormField
                label="Povolené roly"
                class="mt-4"
                :disabled="!canEditBaseFields"
            >
                <div class="flex flex-col gap-2">
                    <label v-for="role in branchRoles" :key="role.id" class="flex items-center gap-2">
                        <Checkbox
                            v-model="model.role_ids"
                            :value="Number(role.id)"
                            :disabled="!canEditBaseFields"
                        />
                        <span :class="!canEditBaseFields ? 'text-tag1!' : ''">
                            {{ procedureRoleLabel(role) }}
                        </span>
                    </label>
                </div>
            </FormField>
        </div>

        <div v-if="showPrices" class="mt-4">
            <div class="overflow-auto">
                <table class="w-full table-auto">
                    <tbody>
                        <tr v-for="ic in insuranceCompanies" :key="ic.id">
                            <td class="p-2 pl-0 text-normal">
                                {{ ic.name ?? ic.id }}
                            </td>

                            <td class="p-2 pr-0">
                                <div class="flex justify-end">
                                    <FormField class="max-w-56">
                                        <template #default="{ id }">
                                            <div class="relative">
                                                <InputNumber
                                                    :input-id="id"
                                                    v-model="priceValues[Number(ic.id)]"
                                                    :min="0"
                                                    :minFractionDigits="2"
                                                    :maxFractionDigits="2"
                                                    :useGrouping="false"
                                                    locale="en-US"
                                                    class="w-full price-input"
                                                    :disabled="!canEditPrices"
                                                    :pt="priceHighlightPt(Number(ic.id))"
                                                />
                                                <span
                                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-lightgrey pointer-events-none"
                                                >
                                                    &euro;
                                                </span>
                                            </div>
                                        </template>
                                    </FormField>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="!historyMode" class="mt-4 flex flex-wrap items-center justify-between gap-2">
            <AppButton
                v-if="model.id && canEditPrices"
                label="História cien"
                icon="bi bi-clock-history"
                variant="dark"
                @click="historyDialogVisible = true"
                :expand-on-hover="true"

            />
            <span v-else />

            <div class="flex justify-end gap-2">
                <AppButton
                    label="Zrušiť"
                    variant="secondary"
                    @click="close"
                />

                <AppButton
                    label="Uložiť"
                    @click="save"
                    :loading="loading"
                />
            </div>
        </div>

        <ProcedurePriceEffectiveDateDialog
            v-if="!historyMode"
            v-model:visible="effectiveDateDialogVisible"
            :changes="changedPrices"
            @confirm="confirmEffectiveDates"
        />

        <ProcedurePriceHistoryDialog
            v-if="!historyMode && model.id"
            v-model:visible="historyDialogVisible"
            :procedure-id="model.id"
            :procedure="procedure"
        />
    </div>
</template>