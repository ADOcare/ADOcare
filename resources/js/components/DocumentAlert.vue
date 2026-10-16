<script setup lang="ts">
import { ref } from 'vue'

import Dialog from 'primevue/dialog'
import { useToast } from 'primevue/usetoast'

import AppButton from '@/components/AppButton.vue'

import api from '@/services/api'

interface Props {
    visible: boolean
    documentId: number | null
    documentUrl?: string
}

const props =
    defineProps<Props>()

const emit =
    defineEmits<{
        'update:visible': [
            value: boolean,
        ]
        close: []
        continue: []
        deleted: []
    }>()

const toast =
    useToast()

const isDeleting =
    ref(false)

function continueCreation() {
    emit(
        'update:visible',
        false,
    )

    emit('close')
    emit('continue')
}

function viewDocument() {
    if (
        !props.documentId ||
        !props.documentUrl
    ) {
        return
    }

    const url =
        props.documentUrl.replace(
            '{id}',
            props.documentId.toString(),
        )

    window.open(
        url,
        '_blank',
    )
}

async function replaceDocument() {
    if (!props.documentId) {
        return
    }

    isDeleting.value =
        true

    try {
        await api.delete(
            `/v1/documents/${props.documentId}`,
        )

        emit(
            'update:visible',
            false,
        )

        emit('deleted')
        emit('continue')
    } catch (error: any) {
        console.error(
            'Failed to replace document:',
            error,
        )

        const message =
            error
                ?.response
                ?.data
                ?.message ??
            'Nepodarilo sa nahradiť dokument.'

        toast.add({
            severity:
                'error',

            summary:
                'Chyba',

            detail:
                message,

            life:
                5000,
        })
    } finally {
        isDeleting.value =
            false
    }
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        closable
        header="Upozornenie"
        class="w-full max-w-5xl"
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
                flex-col
                gap-6
            "
        >
            <!-- Message -->
            <div
                class="
                    flex
                    flex-col
                    gap-1
                "
            >
                <div
                    class="
                        text-heading
                        text-darkgrey
                    "
                >
                  Dokument pre zvolené obdobie už existuje.
                </div>
            </div>

            <!-- Main actions -->
            <div
                class="
                    flex
                    flex-wrap
                    items-center
                    justify-end
                    gap-2
                "
            >
              <AppButton
                  type="button"
                  variant="text"
                  label="Zobraziť existujúci dokument"
                  :disabled="isDeleting"
                  @click="viewDocument"
              />

              <AppButton
                  type="button"
                  variant="secondary"
                  label="Vytvoriť novú verziu a ponechať pôvodnú"
                  :disabled="isDeleting"
                  @click="continueCreation"
              />

              <AppButton
                  type="button"
                  variant="accent"
                  label="Nahradiť existujúci dokument novou verziou"
                  :loading="isDeleting"
                  loading-label="Nahrádzam dokument"
                  @click="replaceDocument"
              />
            </div>
        </div>
    </Dialog>
</template>