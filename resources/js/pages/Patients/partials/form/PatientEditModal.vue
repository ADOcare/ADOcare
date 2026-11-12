<script setup lang="ts">
import EditPatientForm from './EditPatientForm.vue'

const props = defineProps<{ patientId: number }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

function handleSaved(result?: unknown) {
  if (result) {
    emit('saved')
  }
  emit('close')
}
</script>

<template>
  <Dialog :visible="true" modal header="Upraviť pacienta" :style="{ width: '90%', maxWidth: '1100px' }"
    @update:visible="emit('close')">
    <!-- We pass modalResolve so your existing body can finish + close -->
    <EditPatientForm :visible="true" :patient-id="props.patientId" :modalResolve="handleSaved" :is-manager-view="false" />
  </Dialog>
</template>
