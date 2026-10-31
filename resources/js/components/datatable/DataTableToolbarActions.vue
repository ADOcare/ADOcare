<script setup lang="ts">
import { unref } from 'vue'

import AppButton from '@/components/AppButton.vue'
import type {
    ActionDef,
    RemoteTableReturn,
} from '@/types/datatable'

type IBaseModel = any

const props = defineProps<{
    actions?: ActionDef<IBaseModel>[]
    rows: IBaseModel[]
    selectedRows: IBaseModel[]
    remote: RemoteTableReturn
}>()

const emits = defineEmits<{
    (e: 'action', action: ActionDef<IBaseModel>): void
}>()

function getContext() {
    return {
        rows: props.rows,
        selectedRows: props.selectedRows,
        remote: props.remote,
    }
}

function getIcon(
    action: ActionDef<IBaseModel>,
): string {
    if (!action.icon) {
        return 'bi bi-three-dots'
    }

    return typeof action.icon === 'function'
        ? action.icon(getContext()) ?? 'bi bi-three-dots'
        : unref(action.icon) ?? 'bi bi-three-dots'
}

function getTooltip(
    action: ActionDef<IBaseModel>,
): string | undefined {
    if (!action.tooltip) {
        return undefined
    }

    return typeof action.tooltip === 'function'
        ? action.tooltip(getContext())
        : unref(action.tooltip)
}

function getLabel(
    action: ActionDef<IBaseModel>,
): string {
    return (
        (typeof action.label === 'function' ? action.label(getContext()) : action.label)?.trim() ||
        getTooltip(action) ||
        action.key ||
        'Akcia'
    )
}

function getClass(
    action: ActionDef<IBaseModel>,
): string | undefined {
    if (!action.class) {
        return undefined
    }

    return typeof action.class === 'function'
        ? action.class(getContext())
        : unref(action.class)
}

function isDisabled(
    action: ActionDef<IBaseModel>,
): boolean {
    if (!action.disabled) {
        return false
    }

    return typeof action.disabled === 'boolean'
        ? action.disabled
        : action.disabled(getContext())
}

</script>

<template>
    <template v-if="actions?.length">
        <span
            v-for="action in actions"
            :key="action.key"
            v-tooltip.top="getTooltip(action)"
            class="inline-flex"
        >
            <AppButton
                :variant="action.variant ?? 'dark'"
                :class="getClass(action)"
                expand-on-hover
                :icon="getIcon(action)"
                :label="getLabel(action)"
                :aria-label="getLabel(action)"
                :disabled="isDisabled(action)"
                :initial-expand-ms="0"
                @click="emits('action', action)"
            />
        </span>
    </template>
</template>