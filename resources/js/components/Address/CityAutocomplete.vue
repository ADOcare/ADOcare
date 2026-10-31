<script setup lang="ts">
import { ref } from 'vue'
import api from '@/services/api'
import type { AutoCompleteCompleteEvent, AutoCompleteOptionSelectEvent } from 'primevue/autocomplete'

const props = defineProps<{ modelValue: string | null }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: string | null): void; (e: 'selected', v: { name: string; zip?: string }): void }>();

const query = ref(props.modelValue ?? '')
const suggestions = ref<any[]>([])

async function onComplete(e: AutoCompleteCompleteEvent) {
  try {
    const q = (e.query ?? '').trim()
    if (!q) { suggestions.value = []; return }
    const res = await api.get('/v1/cities/suggest', { params: { q, limit: 10 } })
    suggestions.value = res.data?.data ?? []
  } catch (err) {
    console.error('searchCity error:', err)
    suggestions.value = []
  }
}

function onSelect(e: AutoCompleteOptionSelectEvent) {
  const sel: any = e?.value
  if (!sel) return
  emit('update:modelValue', sel.name || null)
  emit('selected', { name: sel.name || '', zip: sel.zip || '' })
}

function onInput(v: string) {
  emit('update:modelValue', v || null)
}
</script>

<template>
  <AutoComplete v-model="query" :suggestions="suggestions" optionLabel="name" @complete="onComplete" @option-select="onSelect" @input="onInput" class="w-full" />
</template>
