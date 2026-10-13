<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

// section root: define which part of the route tree to scan
const sectionRootName = computed(() => {
  return route.matched[0]?.meta?.sectionRoot ?? route.matched[0]?.name
})

// parent <h1>
const h1Title = computed(() => {
  return route.matched[0]?.meta?.sectionTitle ?? route.matched[0]?.meta?.title ?? ''
})

// child <h2>
const h2Title = computed(() => {
  if (route.matched.length < 2) {
    return route.matched[0]?.meta?.sectionTitle
      ? route.matched[0]?.meta?.title ?? ''
      : ''
  }

  const last = route.matched[route.matched.length - 1]
  return last?.meta?.title ?? ''
})

type SectionLink = {
  key: string
  label: string
  to: any
}

// resolve children dynamically
const links = computed<SectionLink[]>(() => {
  const root = router.getRoutes().find(r => r.name === sectionRootName.value)
  if (!root) return []

  return root.children
    .filter(child => child.meta?.navbar === true)
    .map(child => ({
      key: child.name?.toString() ?? child.path,
      label:
        (child.meta?.link as string) ??
        (child.meta?.title as string) ??
        child.name?.toString(),
      to: { name: child.name }
    }))
})


const activeKey = computed(() => {
  const last = route.matched[route.matched.length - 1]
  return last?.name?.toString()
})

function linkClass(key: string) {
  return [
    'text-mini! underline! px-sm! transition-colors',
    activeKey.value === key ? 'text-accent!' : 'text-darkgrey! hover:text-accent!'
  ]
}
</script>

<template>
  <Menubar
  class="pb-2 justify-between flex items-center">
    <template #start>
      <div class="flex">
        <h1 class="text-heading-accent! border-r-2! border-accent! pr-sm!">
          {{ h1Title }}
        </h1>
        <h1 v-if="h2Title" class="text-heading-accent! text-darkgrey! px-sm! font-light!">
          {{ h2Title }}
        </h1>
      </div>
    </template>

    <template #end>
      <RouterLink
        v-for="l in links"
        :key="l.key"
        :to="l.to"
        :class="linkClass(l.key)"
      >
        {{ l.label }}
      </RouterLink>
    </template>

  </Menubar>
</template>

