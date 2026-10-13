<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useAttrs,
} from 'vue'

import Button from 'primevue/button'

defineOptions({
    inheritAttrs: false,
})

type ButtonVariant =
    | 'accent'
    | 'dark'
    | 'text'
    | 'secondary'
    | 'danger'

const props = withDefaults(
    defineProps<{
        align?: 'left' | 'center' | 'right'
        ariaLabel?: string
        disabled?: boolean
        expanded?: boolean
        expandOnHover?: boolean
        icon?: string
        initialExpandMs?: number
        label?: string
        loading?: boolean
        loadingIcon?: string
        loadingLabel?: string
        type?: 'button' | 'submit' | 'reset'
        variant?: ButtonVariant
    }>(),
    {
        align: undefined,
        ariaLabel: undefined,
        disabled: false,
        expanded: false,
        expandOnHover: false,
        icon: undefined,
        initialExpandMs: 0,
        label: undefined,
        loading: false,
        loadingIcon: 'pi pi-spinner pi-spin',
        loadingLabel: undefined,
        type: 'button',
        variant: 'accent',
    },
)

const emit = defineEmits<{
    click: [event: MouseEvent]
}>()

defineSlots<{
    default?: () => unknown
    icon?: (props: { loading: boolean }) => unknown
}>()

const attrs = useAttrs()

const forwardedAttrs = computed(() =>
    Object.fromEntries(
        Object.entries(attrs).filter(([name]) => name !== 'class'),
    ),
)

const variantClasses: Record<ButtonVariant, string> = {
    accent:
        'bg-accent! text-white! hover:bg-darkgrey!',

    dark:
        'bg-darkgrey! text-white! hover:bg-accent!',

    text:
        'bg-transparent! text-accent! hover:bg-tag3!',

    secondary:
        'bg-tag3! text-accent! hover:bg-accent! hover:text-white!',

    danger:
        'bg-danger! text-white! hover:bg-danger!',
}

const alignClasses = computed(() => {
    if (props.align === 'right') {
        return 'flex! w-fit! ml-auto!'
    }

    if (props.align === 'center') {
        return 'flex! w-fit! mx-auto!'
    }

    if (props.align === 'left') {
        return 'flex! w-fit! mr-auto!'
    }

    return ''
})

const currentLabel = computed(() =>
    props.loading && props.loadingLabel
        ? props.loadingLabel
        : props.label,
)

const accessibleLabel = computed(() =>
    props.ariaLabel ??
    currentLabel.value ??
    undefined,
)

const currentIcon = computed(() =>
    props.loading
        ? props.loadingIcon
        : props.icon,
)

const iconIsImage = computed(() => {
    const icon = currentIcon.value ?? ''

    return (
        icon.startsWith('/') ||
        /\.(svg|png|webp)(\?.*)?$/i.test(icon)
    )
})

const showInitially = ref(false)

const showExpandedLabel = computed(() => {
    return (
        props.expanded ||
        props.loading ||
        showInitially.value
    )
})

let initialExpandTimer: ReturnType<typeof setTimeout> | null = null

function preloadImage(source: string | undefined) {
    if (
        !source ||
        (
            !source.startsWith('/') &&
            !/\.(svg|png|webp)(\?.*)?$/i.test(source)
        )
    ) {
        return
    }

    const image = new Image()
    image.src = source
}

function handleClick(event: MouseEvent) {
    emit('click', event)
}

onMounted(() => {
    preloadImage(props.icon)
    preloadImage(props.loadingIcon)

    if (
        !props.expandOnHover ||
        props.initialExpandMs <= 0
    ) {
        return
    }

    showInitially.value = true

    initialExpandTimer = setTimeout(() => {
        showInitially.value = false
    }, props.initialExpandMs)
})

onBeforeUnmount(() => {
    if (initialExpandTimer) {
        clearTimeout(initialExpandTimer)
    }
})
</script>

<template>
    <Button
        v-bind="forwardedAttrs"
        :type="type"
        :label="
            expandOnHover || $slots.default
                ? undefined
                : label
        "
        :icon="
            expandOnHover || $slots.icon
                ? undefined
                : icon
        "
        :loading="!expandOnHover && loading"
        :loading-icon="loadingIcon"
        :disabled="disabled || loading"
        :aria-label="accessibleLabel"
        :class="[
            'h-7! min-h-7! rounded-md! border-0! text-normal!',
            'shadow-none!',
            expandOnHover
                ? 'group w-fit! min-w-7! overflow-hidden p-0!'
                : 'px-3!',
            variantClasses[variant],
            alignClasses,
            $attrs.class,
        ]"
        @click="handleClick"
    >
        <template v-if="expandOnHover">
            <span
                class="
                    flex
                    h-7
                    items-center
                    overflow-hidden
                "
            >
                <!-- Fixed 28px icon area -->
                <span
                    class="
                        flex
                        h-7
                        w-10
                        min-w-10
                        shrink-0
                        items-center
                        justify-center
                    "
                    aria-hidden="true"
                >
                    <slot
                        name="icon"
                        :loading="loading"
                    >
                        <img
                            v-if="iconIsImage"
                            :src="currentIcon"
                            alt=""
                            class="h-4 w-4 object-contain"
                        />

                        <i
                            v-else
                            :class="currentIcon"
                        />
                    </slot>
                </span>

                <!-- Smoothly expanding label -->
                <span
                    :class="[
                        'whitespace-nowrap overflow-hidden',
                        'transition-[max-width,opacity,padding] duration-300 ease-in-out',
                        showExpandedLabel
                            ? 'max-w-64 pr-2 opacity-100'
                            : [
                                'max-w-0 pr-0 opacity-0',

                                'group-hover:max-w-64',
                                'group-hover:pr-2',
                                'group-hover:opacity-100',

                                'group-focus-visible:max-w-64',
                                'group-focus-visible:pr-2',
                                'group-focus-visible:opacity-100',
                            ],
                    ]"
                >
                    <slot>
                        {{ currentLabel }}
                    </slot>
                </span>
            </span>
        </template>

        <slot v-else-if="$slots.default" />
    </Button>
</template>