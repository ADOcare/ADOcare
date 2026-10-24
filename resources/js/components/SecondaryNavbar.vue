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
    'text-mini! underline px-2! transition-colors',
    activeKey.value === key ? 'text-tag1!' : 'text-white! hover:text-tag1!'
  ]
}
</script>

<template>
  <Menubar
    class="
      flex
      items-center
      justify-between
      bg-tag2!
      border-y!
      border-darkgrey!
      px-3!
      py-3!
    "
  >
    <template #start>
      <div class="flex items-center text-white!">
        <h2 class="border-r! border-white! pr-2! text-normal!">
          {{ h1Title }}
        </h2>
        <h2 v-if="h2Title" class="px-2! text-normal!">
          {{ h2Title }}
        </h2>
      </div>
    </template>

    <template #end>
      <div class="flex items-center gap-2">
        <div
          id="secondary-navbar-document-actions"
          class="flex items-center gap-2 empty:hidden"
          aria-label="Referenčné dokumenty"
        />

        <RouterLink
          v-for="l in links"
          :key="l.key"
          :to="l.to"
          :class="linkClass(l.key)"
        >
          {{ l.label }}
        </RouterLink>
      </div>
    </template>

  </Menubar>
</template>

