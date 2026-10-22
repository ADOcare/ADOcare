<script setup lang="ts">
import {
    computed,
    ref,
    watch,
} from 'vue'

import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'

import AppButton from '@/components/AppButton.vue'

import type {
    DekurzDay,
    DekurzEntry,
    DekurzPropagationMode,
} from '@/types/dekurz'

const props = defineProps<{
    visible: boolean
    entry: DekurzEntry | null
    day: DekurzDay | null
    days: DekurzDay[]
}>()

const emit = defineEmits<{
    'update:visible': [value: boolean]

    apply: [payload: {
        mode: DekurzPropagationMode
        target_entry_ids: number[]
        target_dates: string[]
        save_as_patient_template: boolean
    }]
}>()

/* -------------------------------------------------------------------------- */
/* State                                                                      */
/* -------------------------------------------------------------------------- */

const selectedDates =
    ref<Date[]>([])

const saveAsPatientTemplate =
    ref(true)

/* -------------------------------------------------------------------------- */
/* Candidates                                                                 */
/* -------------------------------------------------------------------------- */

const candidates =
    computed(() => {
        if (
            !props.entry ||
            !props.day
        ) {
            return []
        }

        return props.days
            .filter(
                day =>
                    day.date >=
                    props.day!.date,
            )
            .filter(day => {
                if (
                    props.entry
                        ?.track_type ===
                    'custom'
                ) {
                    return true
                }

                return day.entries.some(
                    entry =>
                        entry.track_key ===
                        props.entry!
                            .track_key,
                )
            })
            .map(day => ({
                day,

                entry:
                    day.entries.find(
                        entry =>
                            entry.track_key ===
                            props.entry!
                                .track_key,
                    ) ?? null,
            }))
    })

const candidateDateStrings =
    computed(
        () =>
            new Set(
                candidates.value.map(
                    candidate =>
                        candidate.day.date,
                ),
            ),
    )

const candidateDates =
    computed(
        () =>
            candidates.value.map(
                candidate =>
                    parseDate(
                        candidate.day.date,
                    ),
            ),
    )

const minimumDate =
    computed(
        () =>
            candidateDates.value[0] ??
            null,
    )

const maximumDate =
    computed(
        () =>
            candidateDates.value[
                candidateDates.value.length -
                    1
            ] ??
            null,
    )

const canApply =
    computed(
        () =>
            Boolean(
                props.entry?.id &&
                props.entry.text.trim() &&
                selectedDates.value.length,
            ),
    )

/* -------------------------------------------------------------------------- */
/* Disabled dates                                                             */
/* -------------------------------------------------------------------------- */

const disabledDates =
    computed<Date[]>(
        () => {
            if (
                !minimumDate.value ||
                !maximumDate.value
            ) {
                return []
            }

            const disabled:
                Date[] = []

            const cursor =
                new Date(
                    minimumDate.value,
                )

            while (
                cursor <=
                maximumDate.value
            ) {
                const key =
                    formatDate(
                        cursor,
                    )

                if (
                    !candidateDateStrings.value.has(
                        key,
                    )
                ) {
                    disabled.push(
                        new Date(
                            cursor,
                        ),
                    )
                }

                cursor.setDate(
                    cursor.getDate() +
                        1,
                )
            }

            return disabled
        },
    )

/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

function parseDate(
    value: string,
) {
    const [
        year,
        month,
        day,
    ] =
        value
            .split('-')
            .map(Number)

    if (
        year === undefined ||
        month === undefined ||
        day === undefined
    ) {
        return new Date(NaN)
    }

    return new Date(year, month - 1, day)
}

function formatDate(
    value: Date,
) {
    const year =
        value.getFullYear()

    const month =
        String(
            value.getMonth() +
                1,
        ).padStart(
            2,
            '0',
        )

    const day =
        String(
            value.getDate(),
        ).padStart(
            2,
            '0',
        )

    return `${year}-${month}-${day}`
}

/* -------------------------------------------------------------------------- */
/* Dialog state                                                               */
/* -------------------------------------------------------------------------- */

watch(
    () =>
        props.visible,

    visible => {
        if (
            !visible
        ) {
            return
        }

        /*
         * Select all valid candidate days
         * by default.
         */
        selectedDates.value =
            candidateDates.value.map(
                date =>
                    new Date(
                        date,
                    ),
            )

        /*
         * Default checked every time
         * dialog opens.
         */
        saveAsPatientTemplate.value =
            true
    },
)

/* -------------------------------------------------------------------------- */
/* Apply                                                                      */
/* -------------------------------------------------------------------------- */

function apply() {
    if (!canApply.value) {
        return
    }

    const dates =
        selectedDates.value
            .map(
                date =>
                    formatDate(
                        date,
                    ),
            )
            .filter(
                date =>
                    candidateDateStrings.value.has(
                        date,
                    ),
            )
            .sort()

    const entryIds =
        candidates.value
            .filter(
                candidate =>
                    dates.includes(
                        candidate.day.date,
                    ),
            )
            .map(
                candidate =>
                    candidate.entry?.id,
            )
            .filter(
                (
                    id,
                ): id is number =>
                    typeof id ===
                    'number',
            )

    emit(
        'apply',
        {
            mode:
                'selected',

            target_entry_ids:
                entryIds,

            target_dates:
                dates,

            save_as_patient_template:
                saveAsPatientTemplate.value,
        },
    )

    emit(
        'update:visible',
        false,
    )
}
</script>

<template>
    <Dialog
        :visible="
            visible
        "
        header="Ďalšie možnosti"
        modal
        :style="{
            width:
                'min(560px, 94vw)',
            height:
                'min(760px, 94vh)',
        }"
        :pt="{
            content: {
                class: 'flex min-h-0 flex-1 flex-col',
            },
        }"
        @update:visible="
            emit(
                'update:visible',
                $event,
            )
        "
    >
        <div
            class="
                flex
                min-h-0
                flex-1
                flex-col
                gap-6
            "
        >
            <!-- ======================================================== -->
            <!-- DAY PICKER                                               -->
            <!-- ======================================================== -->

            <div
                class="
                    flex
                    min-h-0
                    flex-1
                    flex-col
                    gap-2
                "
            >
                <p
                    class="
                        text-normal
                        text-darkgrey
                    "
                >
                    Vyberte dni, na ktoré sa má text použiť
                </p>

                <DatePicker
                    v-model="
                        selectedDates
                    "
                    :selectionMode="'multiple'"
                    :manualInput="
                        false
                    "
                    :minDate="minimumDate ?? undefined"
                    :maxDate="maximumDate ?? undefined"
                    :disabledDates="
                        disabledDates
                    "
                    inline
                    class="
                        min-h-0
                        flex-1
                        w-full
                    "
                    :pt="{
                        panel: {
                            class: 'flex h-full min-h-0 flex-col',
                        },
                        calendarContainer: {
                            class: 'min-h-0 flex-1',
                        },
                        calendar: {
                            class: 'flex h-full min-h-0 flex-col',
                        },
                        dayView: {
                            class: 'h-full min-h-0 flex-1',
                        },
                        tableBody: {
                            class: 'h-full',
                        },
                    }"
                />

                <p
                    v-if="entry && (!entry.id || !entry.text.trim())"
                    class="text-mini text-danger"
                >
                    {{
                        !entry.id
                            ? 'Záznam ešte nie je uložený.'
                            : 'Pred použitím na ďalšie dni zadajte text.'
                    }}
                </p>
            </div>

            <!-- ======================================================== -->
            <!-- PATIENT TEMPLATE                                         -->
            <!-- ======================================================== -->

            <label
                class="
                    flex
                    cursor-pointer
                    items-start
                    gap-3
                    border-t
                    border-darkgrey/10
                    pt-4
                "
            >
                <Checkbox
                    v-model="
                        saveAsPatientTemplate
                    "
                    binary
                />

                <span>
                    <span
                        class="
                            block
                            text-normal
                            text-darkgrey
                        "
                    >
                        Uložiť ako aktuálny text pacienta
                    </span>
                </span>
            </label>
        </div>

        <template #footer>
            <div
                class="
                    flex
                    w-full
                    justify-end
                    gap-2
                "
            >
                <AppButton
                    type="button"
                    variant="text"
                    label="
                        Zrušiť
                    "
                    @click="
                        emit(
                            'update:visible',
                            false,
                        )
                    "
                />

                <AppButton
                    type="button"
                    variant="accent"
                    label="
                        Použiť
                    "
                    :disabled="!canApply"
                    @click="
                        apply
                    "
                />
            </div>
        </template>
    </Dialog>
</template>