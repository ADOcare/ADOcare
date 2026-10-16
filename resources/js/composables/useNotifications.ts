import { computed, ref } from 'vue'

import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

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
    type: string
    title: string
    description: string
    date: Date
    raw: CarService | PersistedNotification
    actionable: boolean
    dismissible: boolean
    action?: {
        target: 'patient' | 'patient_document_page' | 'car'
        id: number
        route?: 'dekurz' | 'agreement' | 'record' | 'proposal' | 'leave' | 'points'
    }
}

type PersistedNotification = {
    id: number
    type: string
    title: string
    description: string | null
    occurred_at: string
    data?: Record<string, unknown>
    kind: 'event' | 'issue'
}

const carServices = ref<CarService[]>([])
const persistedNotifications = ref<PersistedNotification[]>([])
const dismissedNotificationIds = ref<Set<string>>(new Set())
const loading = ref(false)
const loaded = ref(false)
let activeSessionToken: string | null = null

export function useNotifications() {
    const authStore = useAuthStore()

    function getNextDueDate(service: CarService) {
        const lastDate = new Date(service.date)

        return new Date(
            lastDate.getTime() + service.interval_days * 24 * 60 * 60 * 1000,
        )
    }

    const notifications = computed<AppNotification[]>(() => {
        const carNotifications = carServices.value
            .map((service) => ({
                id: `car-service-${service.id}`,
                type: 'car_service' as const,
                title: service.name,
                description: [service.car?.model, service.car?.evc ? `(${service.car.evc})` : null]
                    .filter(Boolean)
                    .join(' '),
                date: getNextDueDate(service),
                raw: service,
                actionable: authStore.currentRole === 'manager' && !!service.car?.id,
                dismissible: true,
                action: service.car?.id
                    ? {
                        target: 'car' as const,
                        id: service.car.id,
                    }
                    : undefined,
            }))
            .filter((notification) => !dismissedNotificationIds.value.has(notification.id))

        const patientNotifications = persistedNotifications.value.map((notification) => {
            const patientId = Number(notification.data?.patient_id)
            const actionable = [
                'patient_insurance_issue',
                'patient_insurance_changed',
                'documentation_audit_issue',
            ].includes(notification.type) && Number.isInteger(patientId)
            const persistent = notification.data?.persistent === true
            const targetRoute = notification.data?.target_route
            const patientDocumentRoutes = ['dekurz', 'agreement', 'record', 'proposal', 'leave', 'points'] as const
            const patientDocumentRoute = patientDocumentRoutes.find((route) => route === targetRoute)

            return {
                id: `app-${notification.id}`,
                type: notification.type,
                title: notification.title,
                description: notification.description ?? '',
                date: new Date(notification.occurred_at),
                raw: notification,
                actionable,
                dismissible: !persistent,
                action: actionable
                    ? {
                        target: notification.type === 'documentation_audit_issue'
                            ? 'patient_document_page' as const
                            : 'patient' as const,
                        id: patientId,
                        route: notification.type === 'documentation_audit_issue'
                            ? patientDocumentRoute ?? 'dekurz'
                            : undefined,
                    }
                    : undefined,
            }
        })

        return [...patientNotifications, ...carNotifications]
            .sort((left, right) => {
                if (left.actionable !== right.actionable) {
                    return left.actionable ? -1 : 1
                }

                return right.date.getTime() - left.date.getTime()
            })
    })

    const notificationCount = computed(() => notifications.value.length)

    function startNotificationSession(token: string | null) {
        if (activeSessionToken === token) {
            return
        }

        activeSessionToken = token
        dismissedNotificationIds.value = new Set()
        carServices.value = []
        persistedNotifications.value = []
        loaded.value = false
    }

    async function dismissNotification(id: string) {
        if (id.startsWith('app-')) {
            const notificationId = Number(id.slice(4))
            const notification = persistedNotifications.value.find(
                (item) => item.id === notificationId,
            )
            if (notification?.data?.persistent === true) {
                return
            }

            persistedNotifications.value = persistedNotifications.value.filter(
                (notification) => notification.id !== notificationId,
            )

            try {
                await api.post(`v1/notifications/${notificationId}/dismiss`)
            } catch (error) {
                console.error('Failed to dismiss notification', error)
                loaded.value = false
                await loadNotifications(true)
            }

            return
        }

        dismissedNotificationIds.value = new Set([
            ...dismissedNotificationIds.value,
            id,
        ])
    }

    async function dismissAllNotifications() {
        const visibleNotifications = notifications.value

        dismissedNotificationIds.value = new Set([
            ...dismissedNotificationIds.value,
            ...visibleNotifications
                .filter((notification) => notification.id.startsWith('car-service-'))
                .map((notification) => notification.id),
        ])

        await Promise.all(
            visibleNotifications
                .filter((notification) => notification.id.startsWith('app-') && notification.dismissible)
                .map((notification) => dismissNotification(notification.id)),
        )
    }

    async function loadNotifications(force = false) {
        if (loaded.value && !force) {
            return
        }

        loading.value = true

        try {
            const carServicesEndpoint = authStore.currentRole === 'manager'
                ? 'v1/cars/services/due-this-month'
                : 'v1/my-cars/services/due-this-month'

            const [carResponse, notificationResponse] = await Promise.all([
                api.get(carServicesEndpoint),
                api.get('v1/notifications'),
            ])

            carServices.value = carResponse.data?.data?.services ?? []
            persistedNotifications.value =
                notificationResponse.data?.data?.notifications ?? []
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
        dismissAllNotifications,
        dismissNotification,
        loadNotifications,
        startNotificationSession,
    }
}
