import { computed, ref } from 'vue'

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

export type AppNotification = {
    id: string
    type: 'car_service'
    title: string
    description: string
    date: Date
    raw: CarService
}

const carServices = ref<CarService[]>([])
const loading = ref(false)
const loaded = ref(false)

export function useNotifications() {
    function getNextDueDate(service: CarService) {
        const lastDate = new Date(service.date)

        return new Date(
            lastDate.getTime() + service.interval_days * 24 * 60 * 60 * 1000,
        )
    }

    const notifications = computed<AppNotification[]>(() => {
        return carServices.value.map((service) => ({
            id: `car-service-${service.id}`,
            type: 'car_service',
            title: service.name,
            description: [service.car?.model, service.car?.evc ? `(${service.car.evc})` : null]
                .filter(Boolean)
                .join(' '),
            date: getNextDueDate(service),
            raw: service,
        }))
    })

    const notificationCount = computed(() => notifications.value.length)

    async function loadNotifications(force = false) {
        if (loaded.value && !force) {
            return
        }

        loading.value = true

        try {
            const response = await api.get('v1/my-cars/services/due-this-month')
            carServices.value = response.data?.data?.services ?? []
            loaded.value = true
        } catch (error) {
            console.error('Failed to load notifications', error)
        } finally {
            loading.value = false
        }
    }

    return {
        notifications,
        notificationCount,
        loading,
        loadNotifications,
    }
}
