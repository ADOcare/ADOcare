<template>
    <form
        class="flex flex-col gap-6"
        @submit.prevent="onSendClick"
    >
        <FormField
            label="Email príjemcu"
            required
        >
            <EmailInput
                v-model="recipientEmail"
                :disabled="sending"
                :error="emailError"
            />
        </FormField>

        <div class="flex flex-col gap-2">
            <div class="text-normal text-darkgrey">
                Vybrané položky: {{ props.documents.length }}
            </div>

            <div
                class="
                    max-h-64
                    overflow-y-auto
                    rounded-md
                    bg-white
                    p-1
                "
            >
                <div
                    v-for="doc in props.documents"
                    :key="doc.id"
                    class="
                        flex
                        items-start
                        gap-3
                        rounded-md
                        bg-tag3
                        p-3
                        mb-2
                        last:mb-0
                    "
                >
                    <div
                        class="
                            flex
                            h-10
                            w-10
                            shrink-0
                            items-center
                            justify-center
                            rounded-md
                            bg-white
                            text-accent
                        "
                    >
                        <i class="bi bi-file-earmark text-lg" />
                    </div>

                    <div class="min-w-0 flex-1">
                        <div
                            class="
                                truncate
                                text-normal
                                font-medium
                                text-darkgrey
                            "
                        >
                            {{ formatDocumentType(doc.type) }}
                        </div>

                        <div class="mt-1 text-mini text-accent">
                            {{ formatDocumentMetadata(doc) }}
                        </div>
                    </div>
                </div>

                <div
                    v-if="props.documents.length === 0"
                    class="
                        py-6
                        text-center
                        text-normal
                        text-accent
                    "
                >
                    Nie sú vybrané žiadne položky.
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2">
            <AppButton
                type="button"
                label="Zrušiť"
                variant="secondary"
                :disabled="sending"
                @click="close"
            />

            <AppButton
                type="submit"
                :label="props.buttonLabel || 'Odoslať'"
                :loading="sending"
                loading-label="Odosielam"
                :disabled="props.documents.length === 0"
            />
        </div>
    </form>
</template>

<script setup lang="ts">
import {
    computed,
    ref,
} from 'vue'

import { useToast } from 'primevue/usetoast'

import FormField from '@/components/FormField.vue'
import AppButton from '@/components/AppButton.vue'
import EmailInput from '@/components/Contact/EmailInput.vue'

import { isValidEmail } from '@/composables/companySettingsShared'
import api from '@/services/api'

type Document = {
    id: number
    name?: string
    type?: string
    period?: string
    created_at?: string
    updated_at?: string
}

const props = defineProps<{
    documents: Document[]
    onSend?: (
        payload: Record<string, unknown>,
    ) => Promise<void>
    apiEndpoint?: string
    apiMethod?:
        | 'post'
        | 'put'
        | 'patch'
        | 'delete'
    apiPayload?: Record<string, unknown>
    idKey?: 'ids' | 'invoice_ids'
    buttonLabel?: string
    successMessage?: string
    modalResolve?: (
        value?: any,
    ) => void
}>()

const emit = defineEmits<{
    sent: []
}>()

const toast = useToast()

const recipientEmail = ref('')
const sending = ref(false)
const submitted = ref(false)

const emailError = computed(() => {
    if (!submitted.value) {
        return null
    }

    const email =
        recipientEmail.value.trim()

    if (!email) {
        return 'Email príjemcu je povinný.'
    }

    if (!isValidEmail(email)) {
        return 'Zadajte platnú emailovú adresu.'
    }

    return null
})

function formatDocumentType(
    type?: string,
) {
    const typeMap: Record<string, string> = {
        cp: 'Cestovný príkaz',
        dzc: 'Denný záznam ciest',
        kilometers: 'Kilometráž',
        kilometers_batch: 'Dávka kilometrov',
        points_batch: 'Dávka bodov',
        proposal: 'Návrh',
        agreement: 'Dohoda',
        dekurz: 'Dekurz',
        leave: 'Prepúšťacia správa',
        record: 'Ošetrovateľský záznam',
        scan: 'Lekársky nález',
        invoice: 'Faktúra',
        procedures: 'Faktúra (výkonová)',
        transport: 'Faktúra (dopravná)',
        credit_note: 'Dobropis',
        debit_note: 'Ťarchopis',
    }

    return (
        typeMap[type ?? ''] ??
        type ??
        ''
    )
}

function formatPeriod(
    period?: string,
) {
    const match = period?.match(
        /^(\d{4})-(\d{2})$/,
    )

    return match
        ? `${match[2]}/${match[1]}`
        : period
}

function formatDocumentDate(
    value?: string,
) {
    if (!value) {
        return ''
    }

    const date = new Date(value)

    if (Number.isNaN(date.getTime())) {
        return ''
    }

    return date.toLocaleString(
        'sk-SK',
        {
            dateStyle: 'short',
            timeStyle: 'short',
        },
    )
}

function formatDocumentMetadata(
    document: Document,
) {
    const period =
        formatPeriod(document.period)

    const date =
        formatDocumentDate(
            document.created_at ??
            document.updated_at,
        )

    const parts = [
        period
            ? `Obdobie: ${period}`
            : null,

        date
            ? `Dátum: ${date}`
            : null,
    ].filter(Boolean)

    return parts.join(' · ') ||
        document.name ||
        '-'
}

function close() {
    recipientEmail.value = ''
    submitted.value = false

    props.modalResolve?.(null)
}

async function sendRequest(
    payload: Record<string, unknown>,
) {
    if (props.onSend) {
        return props.onSend(payload)
    }

    if (!props.apiEndpoint) {
        return
    }

    const fullPayload = {
        ...payload,
        ...(props.apiPayload ?? {}),
    }

    switch (props.apiMethod ?? 'post') {
        case 'post':
            return api.post(
                props.apiEndpoint,
                fullPayload,
            )

        case 'put':
            return api.put(
                props.apiEndpoint,
                fullPayload,
            )

        case 'patch':
            return api.patch(
                props.apiEndpoint,
                fullPayload,
            )

        case 'delete':
            return api.delete(
                props.apiEndpoint,
                {
                    data: fullPayload,
                },
            )
    }
}

async function onSendClick() {
    submitted.value = true

    const email =
        recipientEmail.value.trim()

    if (
        !email ||
        !isValidEmail(email)
    ) {
        return
    }

    if (!props.documents.length) {
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail:
                'Nie sú vybrané žiadne dokumenty.',
            life: 3000,
        })

        return
    }

    sending.value = true

    try {
        const idsKey =
            props.idKey ?? 'ids'

        await sendRequest({
            email,
            [idsKey]:
                props.documents.map(
                    (document) =>
                        document.id,
                ),
        })

        toast.add({
            severity: 'success',
            summary: 'Odoslané',
            detail:
                props.successMessage ??
                'Email bol odoslaný.',
            life: 3000,
        })

        emit('sent')

        close()
    } catch (error: any) {
        console.error(
            'Error sending documents email from dialog:',
            error,
        )

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail:
                error?.response?.data?.message ??
                error?.message ??
                'Nepodarilo sa odoslať email.',
            life: 4000,
        })
    } finally {
        sending.value = false
    }
}
</script>