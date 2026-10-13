<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { parseDateInput, toApiDate } from '@/utils/dateUtils'
import { buildDaysForMonth } from '@/utils/dateRanges'
import AppButton from '@/components/AppButton.vue'

type PresetMode =
    | 'all'
    | 'holidays'
    | 'mwf'
    | 'weekends'
    | 'workdays'
    | 'workdaysExcludingHolidays'

type MonthChangeEvent = {
    year?: number
    month?: number
}

type PickerInstance = {
    currentMonth?: number
    currentYear?: number
}

const DEFAULT_INPUT_CLASS =
    '!w-full !border-none !shadow-none !bg-white focus:!ring-0 focus:!shadow-none'

const props = withDefaults(
    defineProps<{
        modelValue?: Date[] | null
        viewDate?: Date | null
        minDate?: Date | null
        maxDate?: Date | null
        disabledDates?: Date[]
        showOtherMonths?: boolean
        invalid?: boolean
        inputClass?: string
    }>(),
    {
        modelValue: () => [],
        viewDate: null,
        minDate: null,
        maxDate: null,
        disabledDates: () => [],
        showOtherMonths: true,
        invalid: false,
        inputClass: DEFAULT_INPUT_CLASS,
    },
)

const emit = defineEmits<{
    (e: 'update:modelValue', value: Date[]): void
}>()

const pickerRef = ref<PickerInstance | null>(null)
const panelDate = ref<Date>(getInitialPanelDate())

const disabledDateSet = computed(() => {
    return new Set(
        normalizeDatesWithoutConstraints(props.disabledDates)
            .map((date) => toApiDate(date))
            .filter((date): date is string => !!date),
    )
})

const selectedDates = computed<Date[]>({
    get() {
        return normalizeDates(props.modelValue)
    },
    set(value) {
        emit('update:modelValue', normalizeDates(value))
    },
})

watch(
    () => props.viewDate,
    (value) => {
        const normalized = normalizeSingleDate(value)

        if (normalized) {
            panelDate.value = new Date(
                normalized.getFullYear(),
                normalized.getMonth(),
                1,
            )
        }
    },
    {
        immediate: true,
    },
)

function getInitialPanelDate(): Date {
    const normalizedViewDate = normalizeSingleDate(props.viewDate)

    if (normalizedViewDate) {
        return new Date(
            normalizedViewDate.getFullYear(),
            normalizedViewDate.getMonth(),
            1,
        )
    }

    const firstSelectedDate = normalizeDates(
        props.modelValue,
    )[0]

    if (firstSelectedDate) {
        return new Date(
            firstSelectedDate.getFullYear(),
            firstSelectedDate.getMonth(),
            1,
        )
    }

    const now = new Date()

    return new Date(
        now.getFullYear(),
        now.getMonth(),
        1,
    )
}

function normalizeSingleDate(input: unknown): Date | null {
    const parsed = parseDateInput(input as never)

    if (!parsed) {
        return null
    }

    return new Date(
        parsed.getFullYear(),
        parsed.getMonth(),
        parsed.getDate(),
    )
}

function normalizeDates(input: unknown): Date[] {
    const values = Array.isArray(input)
        ? input
        : []

    const uniqueDates = new Map<string, Date>()

    for (const value of values) {
        const date = normalizeSingleDate(value)

        if (!date || !isSelectableDate(date)) {
            continue
        }

        const dateKey = toApiDate(date)

        if (dateKey) {
            uniqueDates.set(dateKey, date)
        }
    }

    return Array.from(uniqueDates.entries())
        .sort((left, right) =>
            left[0].localeCompare(right[0]),
        )
        .map(([, date]) => date)
}

function normalizeDatesWithoutConstraints(
    input: unknown,
): Date[] {
    const values = Array.isArray(input)
        ? input
        : []

    const uniqueDates = new Map<string, Date>()

    for (const value of values) {
        const date = normalizeSingleDate(value)

        if (!date) {
            continue
        }

        const dateKey = toApiDate(date)

        if (dateKey) {
            uniqueDates.set(dateKey, date)
        }
    }

    return Array.from(uniqueDates.entries())
        .sort((left, right) =>
            left[0].localeCompare(right[0]),
        )
        .map(([, date]) => date)
}

function isSelectableDate(date: Date): boolean {
    const minDate = normalizeSingleDate(props.minDate)
    const maxDate = normalizeSingleDate(props.maxDate)

    if (minDate && date < minDate) {
        return false
    }

    if (maxDate && date > maxDate) {
        return false
    }

    const dateKey = toApiDate(date)

    if (!dateKey) {
        return false
    }

    return !disabledDateSet.value.has(dateKey)
}

function getActivePanelYearMonth(): {
    year: number
    month: number
} {
    const pickerYear = Number(
        pickerRef.value?.currentYear,
    )

    const pickerMonth = Number(
        pickerRef.value?.currentMonth,
    )

    if (
        Number.isFinite(pickerYear) &&
        Number.isFinite(pickerMonth)
    ) {
        return {
            year: pickerYear,
            month: pickerMonth,
        }
    }

    return {
        year: panelDate.value.getFullYear(),
        month: panelDate.value.getMonth(),
    }
}

function onMonthChange(event: MonthChangeEvent) {
    const year = Number(event?.year)
    const month = Number(event?.month)

    if (
        !Number.isFinite(year) ||
        !Number.isFinite(month)
    ) {
        return
    }

    panelDate.value = new Date(
        year,
        month - 1,
        1,
    )
}

function buildDatesForCurrentView(
    mode: PresetMode,
): Date[] {
    const { year, month } =
        getActivePanelYearMonth()

    return buildDaysForMonth(
        year,
        month,
        mode,
    )
}

async function setDatesAndKeepView(
    nextDates: Date[],
) {
    const { year, month } =
        getActivePanelYearMonth()

    selectedDates.value = nextDates

    await nextTick()

    panelDate.value = new Date(
        year,
        month,
        1,
    )

    if (pickerRef.value) {
        pickerRef.value.currentMonth = month
        pickerRef.value.currentYear = year
    }
}

async function applyPreset(mode: PresetMode) {
    await setDatesAndKeepView(
        buildDatesForCurrentView(mode),
    )
}
</script>

<template>
    <DatePicker
        ref="pickerRef"
        v-model="selectedDates"
        :viewDate="panelDate"
        selectionMode="multiple"
        dateFormat="dd.mm.yy"
        :showIcon="false"
        showButtonBar
        :manualInput="false"
        :minDate="minDate || undefined"
        :maxDate="maxDate || undefined"
        :disabledDates="disabledDates"
        :showOtherMonths="showOtherMonths"
        :invalid="invalid"
        :inputClass="inputClass"
        @month-change="onMonthChange"
        @year-change="onMonthChange"
    >
        <template #buttonbar="{ clearCallback }">
            <div class="flex flex-wrap w-full gap-2">
                <AppButton
                    label="Pracovné dni"
                    @mousedown.prevent
                    @click.prevent="
                        applyPreset(
                            'workdaysExcludingHolidays',
                        )
                    "
                />

                <AppButton
                    label="So, Ne, sviatky"
                    @mousedown.prevent
                    @click.prevent="
                        applyPreset('weekends')
                    "
                />

                <AppButton
                    label="Po–Ne"
                    @mousedown.prevent
                    @click.prevent="
                        applyPreset('all')
                    "
                />

                <AppButton
                    label="Po–Pia"
                    @mousedown.prevent
                    @click.prevent="
                        applyPreset('workdays')
                    "
                />

                <AppButton
                    label="Po, St, Pia"
                    @mousedown.prevent
                    @click.prevent="
                        applyPreset('mwf')
                    "
                />

                <AppButton
                    label="Sviatky"
                    @mousedown.prevent
                    @click.prevent="
                        applyPreset('holidays')
                    "
                />

                <AppButton
                    label="Zrušiť výber"
                    variant="secondary"
                    @mousedown.prevent
                    @click.prevent="clearCallback"
                />
            </div>
        </template>
    </DatePicker>
</template>