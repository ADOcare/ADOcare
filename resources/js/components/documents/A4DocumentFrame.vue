<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue'

defineProps<{
    src: string
    title: string
}>()

const emit = defineEmits<{
    load: []
}>()

const A4_WIDTH_PX = 210 / 25.4 * 96
const A4_HEIGHT_PX = 297 / 25.4 * 96

const containerRef = ref<HTMLElement | null>(null)
const iframeRef = ref<HTMLIFrameElement | null>(null)

const containerWidth = ref(0)
const documentHeight = ref(A4_HEIGHT_PX)

let resizeObserver: ResizeObserver | null = null

const scale = computed(() =>
    containerWidth.value > 0
        ? containerWidth.value / A4_WIDTH_PX
        : 1,
)

const frameStyle = computed(() => ({
    width: `${A4_WIDTH_PX}px`,
    height: `${documentHeight.value}px`,
    transform: `scale(${scale.value})`,
}))

const sizerStyle = computed(() => ({
    height: `${documentHeight.value * scale.value}px`,
}))

function handleLoad() {
    try {
        const document = iframeRef.value?.contentDocument

        if (document) {
            document.documentElement.style.overflow = 'hidden'

            // Same-origin previews can be measured so every page is visible without inner scrolling.
            documentHeight.value = Math.max(
                A4_HEIGHT_PX,
                document.documentElement.scrollHeight,
                document.body?.scrollHeight ?? 0,
            )
        }
    } catch {
        documentHeight.value = A4_HEIGHT_PX
    }

    emit('load')
}

onMounted(() => {
    if (!containerRef.value || typeof ResizeObserver === 'undefined') {
        return
    }

    resizeObserver = new ResizeObserver(([entry]) => {
        containerWidth.value = entry?.contentRect.width ?? 0
    })

    resizeObserver.observe(containerRef.value)
})

onBeforeUnmount(() => {
    resizeObserver?.disconnect()
    resizeObserver = null
})
</script>

<template>
    <div
        ref="containerRef"
        class="
            h-full
            w-full
            overflow-y-auto
            overflow-x-hidden
            bg-white
        "
    >
        <div
            class="
                relative
                w-full
                overflow-hidden
            "
            :style="sizerStyle"
        >
            <iframe
                ref="iframeRef"
                :src="src"
                :title="title"
                :style="frameStyle"
                scrolling="no"
                class="
                    absolute
                    left-0
                    top-0
                    origin-top-left
                    border-0
                    bg-white
                "
                @load="handleLoad"
            />
        </div>
    </div>
</template>
