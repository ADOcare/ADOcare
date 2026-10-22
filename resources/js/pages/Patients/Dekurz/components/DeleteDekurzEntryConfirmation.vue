<script setup lang="ts">
import AppButton from '@/components/AppButton.vue'

import type {
    IModalContentProps,
} from '@/types/ui'

import type {
    DekurzEntry,
} from '@/types/dekurz'

const props =
    defineProps<
        IModalContentProps & {
            entry:
                DekurzEntry
        }
    >()

function confirmDelete() {
    props.modalResolve?.(
        true,
    )
}

function cancel() {
    props.modalResolve?.(
        false,
    )
}
</script>

<template>
    <div
        class="
            flex
            flex-col
            gap-6
        "
    >
        <div
            class="
                flex
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
                Naozaj chcete odstrániť túto vlastnú sekciu z tohto dňa?
            </p>
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
                @click="
                    cancel
                "
            />

            <AppButton
                type="button"
                label="
                    Odstrániť
                "
                variant="danger"
                @click="
                    confirmDelete
                "
            />
        </div>
    </div>
</template>