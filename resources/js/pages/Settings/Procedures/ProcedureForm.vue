<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import api from '@/services/api'
import type { InsuranceCompany, Role } from '@/types/models'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
    procedure: { type: Object, default: null },
    modalResolve: { type: Function, required: false },
})

const emit = defineEmits(['save', 'close'])
const authStore = useAuthStore()

const canEditBaseFields = computed(() => authStore.isSuperadmin)
const canEditPrices = computed(() => authStore.isManager)
const showPrices = computed(() => !authStore.isSuperadmin)

const baseFieldClass = computed(() =>
    canEditBaseFields.value
        ? 'w-full'
        : 'w-full border-lightgrey! text-lightgrey!'
)

const labelClass = computed(() =>
    canEditBaseFields.value
        ? 'block text-normal text-dark mb-1'
        : 'block text-normal text-lightgrey mb-1'
)

const loading = ref(false)
const insuranceCompanies = ref<InsuranceCompany[]>([])
const branchRoles = ref<Role[]>([])
const _originalSnapshot = ref<string | null>(null)
const priceValues = ref<Record<number, number>>({})

const model = ref({
    id: null as number | null,
    code: '',
    description: '',
    prices: [] as Array<{ insurance_company_id: number; price: number }>,
    role_ids: [] as number[],
})

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

    const existing = props.procedure?.insurance_companies_prices_minimal ?? []

    model.value.id = props.procedure?.id ?? null
    model.value.code = props.procedure?.code ?? ''
    model.value.description = props.procedure?.description ?? ''
    model.value.role_ids = (props.procedure?.roles ?? []).map((role: Role) => Number(role.id))

    model.value.prices = companies.map((ic: any) => {
        const found = existing.find((p: any) => Number(p.id) === Number(ic.id))
        const price = found ? (found.pivot?.price ?? 0) : 0

        return {
            insurance_company_id: Number(ic.id),
            price: Number(price),
        }
    })

    const nextPriceValues: Record<number, number> = {}
    model.value.prices.forEach((p) => {
        nextPriceValues[Number(p.insurance_company_id)] = normalizePrice(p.price)
    })
    priceValues.value = nextPriceValues

    _originalSnapshot.value = makeSnapshot()
})

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
    loading.value = true

    try {
        const payload: Record<string, any> = {}

        if (authStore.isSuperadmin) {
            payload.code = model.value.code?.toString().trim()
            payload.description = model.value.description?.toString().trim()
            payload.role_ids = model.value.role_ids
        }

        if (authStore.isManager) {
            payload.prices = buildPricesPayload()
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
    } catch (e) {
        console.error(e)
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <div class="p-4">
        <div class="flex flex-col gap-4">
            <div class="col-span-6">
                <label :class="labelClass">Kód</label>
                <InputText
                    v-model.trim="model.code"
                    :class="baseFieldClass"
                    :disabled="!canEditBaseFields"
                />
            </div>

            <div v-if="canEditBaseFields" class="mt-4">
                <label class="block text-normal text-dark mb-2">Povolené roly</label>
                <div class="flex flex-col gap-2">
                    <label v-for="role in branchRoles" :key="role.id" class="flex items-center gap-2">
                        <Checkbox v-model="model.role_ids" :value="Number(role.id)" />
                        <span>{{ role.name || role.position }}</span>
                    </label>
                </div>
            </div>

            <div class="col-span-6">
                <label :class="labelClass">Popis</label>
                <Textarea
                    v-model.trim="model.description"
                    :rows="3"
                    autoResize
                    :class="baseFieldClass"
                    :disabled="!canEditBaseFields"
                />
            </div>
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
                                    <div class="relative">
                                        <InputNumber
                                            v-model="priceValues[Number(ic.id)]"
                                            :min="0"
                                            :minFractionDigits="2"
                                            :maxFractionDigits="2"
                                            :useGrouping="false"
                                            locale="en-US"
                                            class="w-full price-input"
                                            :disabled="!canEditPrices"
                                        />
                                        <span
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-lightgrey pointer-events-none"
                                        >
                                            &euro;
                                        </span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4 flex justify-end gap-2">
            <Button label="Zrušiť" text class="text-accent! px-2!" @click="close" />
            <Button
                label="Uložiť"
                class="bg-accent! border-accent! px-2! hover:bg-darkgrey! hover:border-darkgrey! text-white!"
                @click="save"
                :loading="loading"
            />
        </div>
    </div>
</template>

<style scoped>
.input {
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
}

.btn {
    padding: 0.4rem 0.75rem;
    background: transparent;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
}

.btn-primary {
    background: #0ea5a4;
    color: white;
    border: none;
}

.text-danger {
    color: #dc2626;
}
</style>