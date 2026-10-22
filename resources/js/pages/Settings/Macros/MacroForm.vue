<script setup lang="ts">
import {
    ref,
    watch,
} from 'vue'

import { useToast } from 'primevue/usetoast'

import api from '@/services/api'

import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'

import type {
    IModalContentProps,
} from '@/types/ui'

import type {
    Macro,
} from '@/types/models'

const props =
    defineProps<
        IModalContentProps & {
            macro?:
                | Partial<Macro>
                | null
        }
    >()

const emit =
    defineEmits<{
        save:
            [
                macro:
                    Partial<Macro>,
            ]

        close:
            []
    }>()

const toast =
    useToast()

/* -------------------------------------------------------------------------- */
/* State                                                                      */
/* -------------------------------------------------------------------------- */

const emptyMacro = (): Partial<Macro> => ({
    name:
        '',

    abbreviation:
        '',

    text:
        '',
})

const local =
    ref<Partial<Macro>>(
        props.macro
            ? {
                  ...props.macro,
              }
            : emptyMacro(),
    )

const saving =
    ref(false)

const errors =
    ref<
        Record<
            string,
            string
        >
    >({})

const submitted =
    ref(false)

/* -------------------------------------------------------------------------- */
/* Watch                                                                      */
/* -------------------------------------------------------------------------- */

watch(
    () =>
        props.macro,
    value => {
        local.value =
            value
                ? {
                      ...value,
                  }
                : emptyMacro()

        errors.value =
            {}

        submitted.value =
            false
    },
    {
        immediate:
            true,
    },
)

/* -------------------------------------------------------------------------- */
/* Validation                                                                 */
/* -------------------------------------------------------------------------- */

function validate() {
    const validationErrors:
        Record<
            string,
            string
        > = {}

    if (
        !local.value.name
            ?.trim()
    ) {
        validationErrors.name =
            'Názov je povinný.'
    }

    if (
        !local.value.abbreviation
            ?.trim()
    ) {
        validationErrors.abbreviation =
            'Skratka je povinná.'
    }

    if (
        !local.value.text
            ?.trim()
    ) {
        validationErrors.text =
            'Text je povinný.'
    }

    errors.value =
        validationErrors

    return (
        Object.keys(
            validationErrors,
        ).length ===
        0
    )
}

/* -------------------------------------------------------------------------- */
/* Close                                                                      */
/* -------------------------------------------------------------------------- */

function close() {
    if (
        props.modalResolve
    ) {
        props.modalResolve(
            undefined,
        )

        return
    }

    emit(
        'close',
    )
}

/* -------------------------------------------------------------------------- */
/* Save                                                                       */
/* -------------------------------------------------------------------------- */

async function save() {
    if (
        saving.value
    ) {
        return
    }

    submitted.value =
        true

    if (
        !validate()
    ) {
        return
    }

    saving.value =
        true

    try {
        const payload = {
            name:
                local.value.name
                    ?.trim() ??
                '',

            abbreviation:
                local.value.abbreviation
                    ?.trim() ??
                '',

            text:
                local.value.text
                    ?.trim() ??
                '',
        }

        let response

        if (
            local.value.id
        ) {
            response =
                await api.put(
                    `/v1/macros/${local.value.id}`,
                    payload,
                )
        } else {
            response =
                await api.post(
                    '/v1/macros',
                    payload,
                )
        }

        const savedMacro =
            response.data
                ?.data ??
            response.data ??
            {
                ...local.value,
                ...payload,
            }

        toast.add({
            severity:
                'success',

            summary:
                'Uložené',

            detail:
                local.value.id
                    ? 'Makro bolo upravené.'
                    : 'Makro bolo vytvorené.',

            life:
                3000,
        })

        if (
            props.modalResolve
        ) {
            props.modalResolve(
                savedMacro,
            )

            return
        }

        emit(
            'save',
            savedMacro,
        )
    } catch (error) {
        console.error(
            'Save macro failed:',
            error,
        )

        toast.add({
            severity:
                'error',

            summary:
                'Chyba',

            detail:
                'Nepodarilo sa uložiť makro.',

            life:
                4000,
        })
    } finally {
        saving.value =
            false
    }
}
</script>

<template>
    <form
        class="
            flex
            flex-col
            gap-6
        "
        @submit.prevent="
            save
        "
    >
        <div
            class="
                grid
                grid-cols-12
                gap-4
            "
        >
            <FormField
                label="
                    Názov
                "
                required
                :error="
                    submitted
                        ? errors.name
                        : null
                "
                class="
                    col-span-12
                "
                v-slot="{
                    id,
                    describedBy,
                    invalid,
                }"
            >
                <InputText
                    :id="
                        id
                    "
                    v-model="
                        local.name
                    "
                    class="
                        w-full
                    "
                    :invalid="
                        invalid
                    "
                    :aria-invalid="
                        invalid ||
                        undefined
                    "
                    :aria-describedby="
                        describedBy
                    "
                    autocomplete="
                        off
                    "
                />
            </FormField>

            <FormField
                label="
                    Skratka
                "
                required
                :error="
                    submitted
                        ? errors.abbreviation
                        : null
                "
                class="
                    col-span-12
                "
                v-slot="{
                    id,
                    describedBy,
                    invalid,
                }"
            >
                <InputText
                    :id="
                        id
                    "
                    v-model="
                        local.abbreviation
                    "
                    class="
                        w-full
                    "
                    :invalid="
                        invalid
                    "
                    :aria-invalid="
                        invalid ||
                        undefined
                    "
                    :aria-describedby="
                        describedBy
                    "
                    autocomplete="
                        off
                    "
                />
            </FormField>

            <FormField
                label="
                    Text
                "
                required
                :error="
                    submitted
                        ? errors.text
                        : null
                "
                class="
                    col-span-12
                "
                v-slot="{
                    id,
                    describedBy,
                    invalid,
                }"
                :outlined="true"
            >
                <Textarea
                    :id="
                        id
                    "
                    v-model="
                        local.text
                    "
                    rows="
                        3
                    "
                    autoResize
                    class="
                        w-full
                        resize-none!
                    "
                    :invalid="
                        invalid
                    "
                    :aria-invalid="
                        invalid ||
                        undefined
                    "
                    :aria-describedby="
                        describedBy
                    "
                />
            </FormField>
        </div>

        <div
            class="
                flex
                items-center
                justify-end
                gap-2
                pt-2
            "
        >
            <AppButton
                type="button"
                label="
                    Zrušiť
                "
                variant="secondary"
                :disabled="
                    saving
                "
                @click="
                    close
                "
            />

            <AppButton
                type="submit"
                variant="accent"
                :label="
                    local.id
                        ? 'Upraviť'
                        : 'Vytvoriť'
                "
                :loading="
                    saving
                "
                :loading-label="
                    local.id
                        ? 'Upravujem'
                        : 'Vytváram'
                "
            />
        </div>
    </form>
</template>