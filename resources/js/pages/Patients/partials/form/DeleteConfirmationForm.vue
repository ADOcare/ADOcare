<script setup lang="ts">
import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'

import AppButton from '@/components/AppButton.vue'

import type { IModalContentProps } from '@/types/ui'
import type { Patient } from '@/types/models'
import type { RemoteTableReturn } from '@/types/datatable'

import api from '@/services/api'

const props = defineProps<
    IModalContentProps & {
        selectedRows: Patient[]
        remote: RemoteTableReturn
    }
>()

const toast = useToast()

const isLoading = ref(false)

async function confirmDelete() {
    isLoading.value = true

    try {
        await api.delete(
            'v1/patients',
            {
                data: {
                    ids: props.selectedRows.map(
                        (row) => row.id,
                    ),
                },
            },
        )

        await props.remote.loadPage(
            props.remote.page.value,
        )

        toast.add({
            severity: 'success',
            summary: 'Vymazané',
            detail: 'Pacienti boli vymazaní',
            life: 3000,
        })

        props.modalResolve?.(true)
    } catch (error) {
        console.error(
            'Failed to delete patients',
            error,
        )

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Nepodarilo sa vymazať pacientov',
            life: 3000,
        })
    } finally {
        isLoading.value = false
    }
}

function cancel() {
    props.modalResolve?.(false)
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="text-normal">
            Pacienti budú deaktivovaní. Ich body a dokumenty zostanú zachované.
        </div>

        <div class="flex items-center justify-end gap-2 pt-2">
            <AppButton
                type="button"
                label="Zrušiť"
                variant="secondary"
                :disabled="isLoading"
                @click="cancel"
            />

            <AppButton
                type="button"
                label="Vymazať"
                variant="danger"
                :loading="isLoading"
                loading-label="Vymazávam"
                @click="confirmDelete"
            />
        </div>
    </div>
</template>