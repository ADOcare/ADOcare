<script setup lang="ts">
import SecondaryNavbar from '@/components/SecondaryNavbar.vue'
</script>

<template>
  <div class="flex h-full min-h-0 flex-col">
    <SecondaryNavbar class="flex-none" />

    <div class="min-h-0 flex-1">
      <router-view />
    </div>
  </div>
</template>
