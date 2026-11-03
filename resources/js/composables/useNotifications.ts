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
const loadingMore = ref(false)
const loaded = ref(false)
const persistedNotificationTotal = ref(0)
const nextNotificationCursor = ref<string | null>(null)
const hasMoreNotifications = ref(false)
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
                'patient_audit_issue',
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

    const notificationCount = computed(() => (
        persistedNotificationTotal.value
        + carServices.value.filter(
            (service) => !dismissedNotificationIds.value.has(`car-service-${service.id}`),
        ).length
    ))

    function startNotificationSession(token: string | null) {
        if (activeSessionToken === token) {
            return
        }

        activeSessionToken = token
        dismissedNotificationIds.value = new Set()
        carServices.value = []
        persistedNotifications.value = []
        persistedNotificationTotal.value = 0
        nextNotificationCursor.value = null
        hasMoreNotifications.value = false
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

            const notificationIndex = persistedNotifications.value.findIndex(
                (item) => item.id === notificationId,
            )
            if (notificationIndex < 0) {
                return
            }

            persistedNotifications.value = persistedNotifications.value.filter(
                (notification) => notification.id !== notificationId,
            )
            persistedNotificationTotal.value = Math.max(
                0,
                persistedNotificationTotal.value - 1,
            )

            try {
                await api.post(`v1/notifications/${notificationId}/dismiss`)
            } catch (error) {
                console.error('Failed to dismiss notification', error)
                persistedNotifications.value.splice(notificationIndex, 0, notification)
                persistedNotificationTotal.value++
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
        if (loading.value || (loaded.value && !force)) {
            return
        }

        loading.value = true

        try {
            const carServicesEndpoint = authStore.currentRole === 'manager'
                ? 'v1/cars/services/due-this-month'
                : 'v1/my-cars/services/due-this-month'

            const [carResponse, notificationResponse] = await Promise.all([
                api.get(carServicesEndpoint),
                api.get('v1/notifications', {
                    params: { per_page: 10 },
                }),
            ])

            carServices.value = carResponse.data?.data?.services ?? []
            const notificationData = notificationResponse.data?.data ?? {}
            persistedNotifications.value = notificationData.notifications ?? []
            persistedNotificationTotal.value = Number(notificationData.pagination?.total ?? 0)
            nextNotificationCursor.value = notificationData.pagination?.next_cursor ?? null
            hasMoreNotifications.value = notificationData.pagination?.has_more === true
            loaded.value = true
        } catch (error) {
            console.error('Failed to load notifications', error)
        } finally {
            loading.value = false
        }
    }

    async function loadMoreNotifications() {
        if (
            loading.value
            || loadingMore.value
            || !loaded.value
            || !hasMoreNotifications.value
            || !nextNotificationCursor.value
        ) {
            return
        }

        loadingMore.value = true

        try {
            const response = await api.get('v1/notifications', {
                params: {
                    per_page: 10,
                    cursor: nextNotificationCursor.value,
                },
            })
            const data = response.data?.data ?? {}
            const existingIds = new Set(
                persistedNotifications.value.map((notification) => notification.id),
            )
            const nextNotifications = (data.notifications ?? []).filter(
                (notification: PersistedNotification) => !existingIds.has(notification.id),
            )

            persistedNotifications.value.push(...nextNotifications)
            persistedNotificationTotal.value = Number(
                data.pagination?.total ?? persistedNotificationTotal.value,
            )
            nextNotificationCursor.value = data.pagination?.next_cursor ?? null
            hasMoreNotifications.value = data.pagination?.has_more === true
        } catch (error) {
            console.error('Failed to load more notifications', error)
        } finally {
            loadingMore.value = false
        }
    }

    return {
        notifications,
        notificationCount,
        loading,
        loadingMore,
        hasMoreNotifications,
        dismissAllNotifications,
        dismissNotification,
        loadNotifications,
        loadMoreNotifications,
        startNotificationSession,
    }
}
