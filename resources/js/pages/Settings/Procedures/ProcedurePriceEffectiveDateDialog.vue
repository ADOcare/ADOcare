<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import DatePicker from 'primevue/datepicker'

import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'
import { toApiDate } from '@/utils/dateUtils'

export type ProcedurePriceChange = {
    insuranceCompanyId: number
    insuranceCompanyName: string
    previousPrice: number
    newPrice: number
}

const props = defineProps<{
    visible: boolean
    changes: ProcedurePriceChange[]
}>()

const emit = defineEmits<{
    (event: 'update:visible', visible: boolean): void
    (event: 'confirm', dates: Record<number, string>): void
}>()

function today(): Date {
    const now = new Date()
    return new Date(now.getFullYear(), now.getMonth(), now.getDate())
}

const dates = ref<Record<number, Date | null>>({})
const effectiveDates = computed<Record<number, string>>(() => Object.fromEntries(
    props.changes.map((change) => [
        change.insuranceCompanyId,
        toApiDate(dates.value[change.insuranceCompanyId] ?? null) ?? '',
    ]),
))
const canSubmit = computed(() =>
    props.changes.length > 0 && Object.values(effectiveDates.value).every(Boolean),
)

watch(
    () => props.visible,
    (visible) => {
        if (!visible) return
        dates.value = Object.fromEntries(
            props.changes.map((change) => [change.insuranceCompanyId, today()]),
        )
    },
    { immediate: true },
)

function formatPrice(value: number): string {
    return new Intl.NumberFormat('sk-SK', {
        style: 'currency',
        currency: 'EUR',
    }).format(value)
}

function confirm(): void {
    if (canSubmit.value) emit('confirm', effectiveDates.value)
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Platnosť zmien cien"
        :style="{ width: 'min(94vw, 720px)' }"
        @update:visible="emit('update:visible', $event)"
    >
        <div class="flex max-h-[60dvh] flex-col gap-3 overflow-y-auto pr-1">
            <article
                v-for="change in changes"
                :key="change.insuranceCompanyId"
                class="flex flex-col gap-3 rounded-md bg-tag3 p-3 sm:p-4"
            >
                <h3 class="text-normal text-accent">
                    {{ change.insuranceCompanyName }}
                </h3>

                <div class="grid grid-cols-1 items-center gap-2 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                    <div class="rounded-md bg-white px-3 py-2">
                        <p class="mb-1 text-mini text-lightgrey">Pôvodne</p>
                        <p class="text-normal text-darkgrey">
                            {{ formatPrice(change.previousPrice) }}
                        </p>
                    </div>

                    <div class="flex items-center justify-center text-accent" aria-hidden="true">
                        <i class="bi bi-arrow-down sm:hidden" />
                        <i class="bi bi-arrow-right hidden sm:inline" />
                    </div>

                    <div class="rounded-md bg-white px-3 py-2">
                        <p class="mb-1 text-mini text-lightgrey">Teraz</p>
                        <p class="text-normal font-medium text-darkgrey">
                            {{ formatPrice(change.newPrice) }}
                        </p>
                    </div>
                </div>

                <FormField label="Platnosť zmeny od" required v-slot="{ id, describedBy, invalid }">
                    <DatePicker
                        :input-id="id"
                        v-model="dates[change.insuranceCompanyId]"
                        date-format="dd.mm.yy"
                        :show-icon="false"
                        :manual-input="false"
                        class="w-full!"
                        input-class="w-full! border-0!"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>
            </article>
        </div>

        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <AppButton
                    type="button"
                    variant="secondary"
                    label="Zrušiť"
                    @click="emit('update:visible', false)"
                />
                <AppButton
                    type="button"
                    label="Potvrdiť a uložiť"
                    :disabled="!canSubmit"
                    @click="confirm"
                />
            </div>
        </template>
    </Dialog>
</template>
