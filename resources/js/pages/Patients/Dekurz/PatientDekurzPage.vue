<script setup lang="ts">
import { computed } from 'vue'
import { usePatientStore } from '@/stores/patientStore'
import DekurzWizard from './components/DekurzWizard.vue'

const patientStore = usePatientStore()
patientStore.loadFromStorage()

const patientId = computed(() => Number(patientStore.current?.id ?? 0))
const dekurzNumber = computed(() => patientStore.current?.dekurz_number ?? null)
</script>

<template>
    <DekurzWizard v-if="patientId" :patient-id="patientId" :dekurz-number="dekurzNumber" />
    <div v-else class="rounded-md border border-tag3 bg-white p-8 text-center text-lightgrey">
        Najprv vyberte pacienta.
    </div>
</template>
