<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { computed, ref, onMounted } from 'vue';
import type { User } from '@/types/models';
import { useAuthStore } from '@/stores/auth';
import api from '@/services/api'

type CarService = {
  id: number
  name: string
  date: string
  interval_days: number
  car?: {
    id: number
    model: string
    evc: string
    user?: {
      first_name: string
      last_name: string
    }
  }
}

const authStore = useAuthStore();

const user = computed<User | null>(() => authStore.user as User | null);

const fullName = computed(() =>
  user.value
    ? `${user.value.first_name ?? ''}`.trim()
    : ''
);

const servicesDueThisMonth = ref<CarService[]>([])
const isLoadingServices = ref(false)

const getNextDueDate = (service: CarService) => {
  const lastDate = new Date(service.date)
  const nextDate = new Date(lastDate.getTime() + service.interval_days * 24 * 60 * 60 * 1000)
  return nextDate
}

onMounted(async () => {
  isLoadingServices.value = true
  try {
    const res = await api.get('v1/my-cars/services/due-this-month')
    servicesDueThisMonth.value = res.data?.data?.services ?? []
  } catch (e) {
    console.error('Failed to load services due this month', e)
  } finally {
    isLoadingServices.value = false
  }
})
</script>

<template>
  <div class="space-y-10">

    <!-- Greeting -->
    <div class="text-heading-accent">
      Dobrý deň {{ fullName }},<br />
      všetko je pre Vás pripravené.
    </div>

    <!-- Maintenance Warning -->
    <section v-if="servicesDueThisMonth.length > 0" class="bg-danger p-6 rounded-md flex flex-col gap-4">
      <div class="flex items-center gap-3">
        <i class="bi bi-exclamation-triangle text-white text-normal"></i>
        <label class="block text-normal text-white ">Údržba požadovaná tento mesiac</label>
      </div>

      <div class="space-y-2">
        <div v-for="service in servicesDueThisMonth" :key="service.id" class="bg-white p-3 rounded-md">
          <div class="flex justify-between items-start">
            <div>
              <p class="text-heading text-darkgrey my-2">{{ service.name }}</p>
              <p class="text-normal text-darkgrey">{{ service.car?.model }} ({{ service.car?.evc }})</p>
              <p class="text-mini text-darkgrey">Podľa plánu by mala prebehnúť údržba: {{
                getNextDueDate(service).toLocaleDateString('sk-SK') }}</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="bg-tag3 p-6 rounded-md flex flex-col gap-4">
      <label class="block text-normal text-accent">Rýchle skratky</label>

      <div class="flex gap-4">
        <RouterLink :to="{ name: 'patients' }" class="block w-70">
          <Button
            class="w-full h-12 bg-darkgrey! border-0! text-white rounded-md flex justify-between! items-center px-4! hover:bg-accent!">
            <span>Pacienti</span>
            <i class="bi bi-arrow-right text-lg"></i>
          </Button>
        </RouterLink>
      </div>
    </section>

    <section class="bg-tag3 p-6 rounded-md flex flex-col gap-4">
      <label class="block text-normal text-accent">Mesačné úkony</label>

      <div class="flex gap-4">
        <RouterLink to="/data/points" class="block w-70">
          <Button
            class="w-full h-12 bg-darkgrey! border-0! text-white rounded-md flex justify-between! items-center px-4! hover:bg-accent!">
            <span>Dávka bodovanie</span>
            <i class="bi bi-arrow-right text-lg"></i>
          </Button>
        </RouterLink>

        <RouterLink to="/data/kilometers" class="block w-70">
          <Button
            class="w-full h-12 bg-darkgrey! border-0! text-white rounded-md flex justify-between! items-center px-4! hover:bg-accent!">
            <span>Dávka kilometre</span>
            <i class="bi bi-arrow-right text-lg"></i>
          </Button>
        </RouterLink>

        <RouterLink to="/accounting/routes" class="block w-70">
          <Button
            class="w-full h-12 bg-darkgrey! border-0! text-white rounded-md flex justify-between! items-center px-4! hover:bg-accent!">
            <span>Cestovné</span>
            <i class="bi bi-arrow-right text-lg"></i>
          </Button>
        </RouterLink>
      </div>
    </section>
  </div>
</template>
