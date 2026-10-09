<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import type { IModalContentProps } from '@/types/ui'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import router from '@/router'
import { useToast } from 'primevue/usetoast'
import type { Car, User } from '@/types/models'
import { formatUserFullName } from '@/utils/formatUtils'
import AutoComplete from 'primevue/autocomplete'

// If you have axios inside api, we can use api.get for blob.
// If not, replace download with your preferred client.
import type { AxiosResponse } from 'axios'

type CarDocument = {
    id: number
    path: string
    mime_type: string
    notes?: string
    created_at: string
}

type CarService = {
    id: number
    name: string
    date: string
    interval_days: number
}

type ServiceIntervalUnit = 'days' | 'weeks' | 'months' | 'years'

const props = defineProps<IModalContentProps & { carId?: number }>()

const toast = useToast()
const authStore = useAuthStore()

const car = ref<Car>({} as Car)
const users = ref<User[]>([])
const submitted = ref(false)
const companyName = ref('')
const ownerSuggestions = ref<string[]>([])

const documents = ref<CarDocument[]>([])
const documentBlobUrls = ref<{ [key: number]: string }>({})
const services = ref<CarService[]>([])

const showServiceDialog = ref(false)
const showDocumentPreview = ref(false)
const selectedDocumentPreview = ref<CarDocument | null>(null)
const uploading = ref(false)

// helpers
const toYmd = (d?: Date | string | null) => {
    if (!d) return null
    const date = d instanceof Date ? d : new Date(String(d))
    if (Number.isNaN(date.getTime())) return null
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')
    return `${year}-${month}-${day}`
}

const selectedFiles = ref<File[]>([])
const documentsPreviewUrls = ref<string[]>([])
const documentNotes = ref('')

// Service form state (DatePicker -> Date)
const newService = ref<{
    name?: string
    date?: Date | null
    interval_days: number
    interval_amount: number
    interval_unit: ServiceIntervalUnit
}>({
    interval_days: 365,
    interval_amount: 1,
    interval_unit: 'years',
    date: null,
})

const editingServiceId = ref<number | null>(null)

// Template refs
const galleryInputRef = ref<HTMLInputElement | null>(null)

// Helper to get frequency label from flexible day interval
const getFrequencyLabel = (days: number): string => {
    const normalizedDays = Math.max(1, Math.round(Number(days) || 0))
    const { interval_amount, interval_unit } = normalizeIntervalForm(normalizedDays)

    const amount = Math.max(1, Math.round(Number(interval_amount) || 1))

    const unitLabel = (() => {
        switch (interval_unit) {
            case 'years':
                if (amount === 1) return 'rok'
                if (amount >= 2 && amount <= 4) return 'roky'
                return 'rokov'
            case 'months':
                if (amount === 1) return 'mesiac'
                if (amount >= 2 && amount <= 4) return 'mesiace'
                return 'mesiacov'
            case 'weeks':
                if (amount === 1) return 'týždeň'
                if (amount >= 2 && amount <= 4) return 'týždne'
                return 'týždňov'
            default:
                if (amount === 1) return 'deň'
                if (amount >= 2 && amount <= 4) return 'dni'
                return 'dní'
        }
    })()

    return `Každých ${amount} ${unitLabel}`
}

const intervalUnitOptions: Array<{ label: string; value: ServiceIntervalUnit }> = [
    { label: 'Dni', value: 'days' },
    { label: 'Týždne', value: 'weeks' },
    { label: 'Mesiace', value: 'months' },
    { label: 'Roky', value: 'years' },
]

const normalizeIntervalForm = (days: number) => {
    if (days % 365 === 0) {
        return { interval_amount: days / 365, interval_unit: 'years' as const }
    }

    if (days % 30 === 0) {
        return { interval_amount: days / 30, interval_unit: 'months' as const }
    }

    if (days % 7 === 0) {
        return { interval_amount: days / 7, interval_unit: 'weeks' as const }
    }

    return { interval_amount: days, interval_unit: 'days' as const }
}

const intervalToDays = (amount: number, unit: ServiceIntervalUnit): number => {
    const safeAmount = Math.max(1, Math.round(Number(amount) || 0))

    switch (unit) {
        case 'years':
            return safeAmount * 365
        case 'months':
            return safeAmount * 30
        case 'weeks':
            return safeAmount * 7
        default:
            return safeAmount
    }
}

const resetServiceForm = () => {
    newService.value = {
        interval_days: 365,
        interval_amount: 1,
        interval_unit: 'years',
        date: null,
    }
}

// Helper to format date for display
const formatDate = (date: string | Date | null | undefined): string => {
    if (!date) return ''
    const d = date instanceof Date ? date : new Date(String(date))
    if (Number.isNaN(d.getTime())) return ''
    return d.toLocaleDateString('sk-SK', { year: 'numeric', month: '2-digit', day: '2-digit' })
}

const getOwnerCandidateValues = (): string[] => {
    const candidates = [
        companyName.value,
        ...users.value.map((user) => formatUserFullName(user)),
    ]

    return Array.from(
        new Set(
            candidates
                .map((value) => (value ?? '').trim())
                .filter((value) => value.length > 0),
        ),
    )
}

const searchOwnerSuggestions = (event: { query?: string }) => {
    const query = (event.query ?? '').trim().toLowerCase()
    const candidates = getOwnerCandidateValues()

    ownerSuggestions.value = query.length
        ? candidates.filter((value) => value.toLowerCase().includes(query))
        : candidates
}

onMounted(async () => {
    // ensure defaults
    car.value.company_id = authStore.user?.company_id ?? null

    await loadCarOwnerName()

    // users list
    try {
        const url =
            authStore.isSuperadmin && router.currentRoute.value.params.companyId
                ? `v1/companies/${Number(router.currentRoute.value.params.companyId)}/users`
                : 'v1/my-company/users'

        users.value = await api.fetchEntities<User>(url)
    } catch (e) {
        console.error('Failed to fetch users', e)
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa načítať používateľov', life: 5000 })
    }

    ownerSuggestions.value = getOwnerCandidateValues()

    // editing existing car
    if (props.carId) {
        try {
            car.value = await api.fetchEntity<Car>(`v1/cars/${props.carId}`)
            await Promise.all([loadDocuments(), loadServices()])
        } catch (e) {
            console.error('Failed to fetch car', e)
            toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa načítať auto', life: 5000 })
        }
    } else if (!car.value.owner_name && companyName.value) {
        car.value.owner_name = companyName.value
    }
})

const loadCarOwnerName = async () => {
    if (!authStore.isSuperadmin && authStore.user?.company?.name) {
        companyName.value = authStore.user.company.name
        return
    }

    const routeCompanyId = authStore.isSuperadmin && router.currentRoute.value.params.companyId
        ? Number(router.currentRoute.value.params.companyId)
        : null

    const companyId = routeCompanyId ?? car.value.company_id ?? authStore.user?.company_id ?? null
    if (!companyId || Number.isNaN(Number(companyId))) {
        return
    }

    try {
        const company = await api.fetchEntity<any>(`v1/companies/${companyId}`)
        companyName.value = company?.name ?? ''
    } catch (e) {
        console.error('Failed to fetch company name', e)
        companyName.value = ''
        return
    }
}

onBeforeUnmount(() => {
    // Cleanup blob URLs
    Object.values(documentBlobUrls.value).forEach(url => {
        URL.revokeObjectURL(url)
    })
    documentsPreviewUrls.value.forEach(url => {
        URL.revokeObjectURL(url)
    })
})

const loadDocuments = async () => {
    if (!car.value.id) return
    try {
        const res = await api.get(`v1/cars/${car.value.id}/documents`)
        documents.value = res.data?.data?.documents ?? res.data?.data ?? []

        // Load image blobs for display
        for (const doc of documents.value) {
            if (doc.mime_type?.startsWith('image/')) {
                try {
                    const imgRes: AxiosResponse<Blob> = await api.get(
                        `v1/cars/${car.value.id}/documents/${doc.id}/download`,
                        { responseType: 'blob' }
                    )
                    documentBlobUrls.value[doc.id] = URL.createObjectURL(imgRes.data)
                } catch (err) {
                    console.error(`Failed to load image blob for document ${doc.id}`, err)
                }
            }
        }
    } catch (e) {
        console.error('Failed to load documents', e)
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa načítať dokumenty', life: 5000 })
    }
}

const loadServices = async () => {
    if (!car.value.id) return
    try {
        const res = await api.get(`v1/cars/${car.value.id}/services`)
        console.log('Services API response:', res.data)

        // Try different response structures
        if (Array.isArray(res.data)) {
            services.value = res.data
        } else if (res.data?.data) {
            services.value = Array.isArray(res.data.data) ? res.data.data : (res.data.data.services ?? [])
        } else {
            services.value = []
        }

        console.log('Loaded services:', services.value)
    } catch (e) {
        console.error('Failed to load services', e)
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa načítať služby', life: 5000 })
    }
}

const save = async () => {
    submitted.value = true

    if (!car.value.model || !car.value.evc) {
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Vyplňte povinné údaje', life: 5000 })
        return
    }

    try {
        if (props.carId) {
            const updated = (await api.patch(`v1/cars/${props.carId}`, car.value)).data.data
            car.value = updated
            await Promise.all([loadDocuments(), loadServices()])
            props.modalResolve?.(updated)
            return
        }

        const created = (await api.post('v1/cars', car.value)).data.data
        car.value = created
        await Promise.all([loadDocuments(), loadServices()])
        toast.add({ severity: 'success', summary: 'Úspech', detail: 'Auto bolo vytvorené', life: 3000 })
    } catch (err) {
        console.error('Failed to save car', err)
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa uložiť auto', life: 5000 })
    }
}

const deleteDocument = async (doc: CarDocument) => {
    if (!car.value.id) return
    try {
        await api.delete(`v1/cars/${car.value.id}/documents/${doc.id}`)
        documents.value = documents.value.filter(d => d.id !== doc.id)

        // Cleanup blob URL
        const blobUrl = documentBlobUrls.value[doc.id]
        if (blobUrl) {
            URL.revokeObjectURL(blobUrl)
            delete documentBlobUrls.value[doc.id]
        }

        toast.add({ severity: 'success', summary: 'Úspech', detail: 'Dokument bol vymazaný', life: 3000 })
    } catch (err) {
        console.error('Failed to delete document', err)
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa vymazať dokument', life: 5000 })
    }
}

const saveService = async () => {
    if (!car.value.id) {
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Najprv uložte auto', life: 5000 })
        return
    }

    const name = newService.value.name?.trim()
    const date = toYmd(newService.value.date ?? null)
    const interval_days = intervalToDays(newService.value.interval_amount, newService.value.interval_unit)

    if (!name || !date) {
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Vyplňte povinné údaje', life: 5000 })
        return
    }

    try {
        const payload = {
            name,
            interval_days,
            date,
        }

        if (editingServiceId.value) {
            // Update existing service
            const res = await api.patch(
                `v1/cars/${car.value.id}/services/${editingServiceId.value}`,
                payload
            )
            const service = res.data?.data?.service ?? res.data?.data
            if (service) {
                const index = services.value.findIndex(s => s.id === editingServiceId.value)
                if (index !== -1) services.value[index] = service
            }
            toast.add({ severity: 'success', summary: 'Úspech', detail: 'Služba bola aktualizovaná', life: 3000 })
        } else {
            // Create new service
            const res = await api.post(`v1/cars/${car.value.id}/services`, payload)
            const service = res.data?.data?.service ?? res.data?.data
            if (service) services.value.unshift(service)
            toast.add({ severity: 'success', summary: 'Úspech', detail: 'Služba bola vytvorená', life: 3000 })
        }

        showServiceDialog.value = false
        resetServiceForm()
        editingServiceId.value = null
    } catch (err) {
        console.error('Failed to save service', err)
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa uložiť službu', life: 5000 })
    }
}

const editService = (service: CarService) => {
    editingServiceId.value = service.id
    const normalizedInterval = normalizeIntervalForm(service.interval_days)
    newService.value = {
        name: service.name,
        date: service.date ? new Date(String(service.date)) : null,
        interval_days: service.interval_days,
        interval_amount: normalizedInterval.interval_amount,
        interval_unit: normalizedInterval.interval_unit,
    }
    showServiceDialog.value = true
}

const deleteService = async (service: CarService) => {
    if (!car.value.id) return
    try {
        await api.delete(`v1/cars/${car.value.id}/services/${service.id}`)
        services.value = services.value.filter(s => s.id !== service.id)
        toast.add({ severity: 'success', summary: 'Úspech', detail: 'Služba bola vymazaná', life: 3000 })
    } catch (err) {
        console.error('Failed to delete service', err)
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa vymazať službu', life: 5000 })
    }
}

const removeDocumentPreview = (index: number) => {
    const url = documentsPreviewUrls.value[index]
    if (url) URL.revokeObjectURL(url)
    documentsPreviewUrls.value.splice(index, 1)
    selectedFiles.value.splice(index, 1)
}

const handleDocumentFileSelected = async (event: Event) => {
    const input = event.target as HTMLInputElement
    const files = Array.from(input.files ?? [])
    if (!files.length) return

    // Add previews
    for (const file of files) {
        if (!file) continue
        selectedFiles.value.push(file)
        documentsPreviewUrls.value.push(URL.createObjectURL(file))
    }

    input.value = ''

    // Upload immediately in the background
    uploading.value = true
    try {
        for (let i = 0; i < selectedFiles.value.length; i++) {
            const file = selectedFiles.value[i]
            if (!file) continue

            try {
                const formData = new FormData()
                formData.append('file', file)
                if (documentNotes.value?.trim()) formData.append('notes', documentNotes.value.trim())

                const res = await api.post(`v1/cars/${car.value.id}/documents`, formData, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                })

                const doc = res.data?.data?.document ?? res.data?.data
                if (doc) {
                    documents.value.unshift(doc)

                    // Load blob URL for images
                    if (doc.mime_type?.startsWith('image/')) {
                        try {
                            const imgRes: AxiosResponse<Blob> = await api.get(
                                `v1/cars/${car.value.id}/documents/${doc.id}/download`,
                                { responseType: 'blob' }
                            )
                            documentBlobUrls.value[doc.id] = URL.createObjectURL(imgRes.data)
                        } catch (err) {
                            console.error(`Failed to load image blob for document ${doc.id}`, err)
                        }
                    }
                }

                // Remove from preview after successful upload
                documentsPreviewUrls.value.splice(i, 1)
                selectedFiles.value.splice(i, 1)
                i-- // Adjust index after splicing
            } catch (err) {
                console.error('Failed to upload document', err)
                toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa nahrať dokument', life: 5000 })
            }
        }

        if (documentsPreviewUrls.value.length === 0) {
            toast.add({ severity: 'success', summary: 'Úspech', detail: 'Dokumenty boli nahrané', life: 3000 })
            documentNotes.value = ''
        }
    } finally {
        uploading.value = false
    }
}

const downloadDocument = async (docId: number) => {
    if (!car.value.id) return
    try {
        const res: AxiosResponse<Blob> = await api.get(
            `v1/cars/${car.value.id}/documents/${docId}/download`,
            { responseType: 'blob' }
        )

        const blob = new Blob([res.data])
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url

        // try to read filename from headers, fallback:
        const cd = res.headers?.['content-disposition'] as string | undefined
        const match = cd?.match(/filename\*=UTF-8''([^;]+)|filename="([^"]+)"/)
        const filename = decodeURIComponent(match?.[1] || match?.[2] || `document_${docId}`)
        a.download = filename

        document.body.appendChild(a)
        a.click()
        a.remove()
        URL.revokeObjectURL(url)
    } catch (e) {
        console.error('Download failed', e)
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa stiahnuť dokument', life: 5000 })
    }
}

const openDocumentPreview = (doc: CarDocument) => {
    selectedDocumentPreview.value = doc
    showDocumentPreview.value = true
}

const getDocumentPreviewUrl = (doc: CarDocument) => {
    if (!car.value.id) return ''
    if (doc.mime_type?.startsWith('image/') && documentBlobUrls.value[doc.id]) {
        return documentBlobUrls.value[doc.id]
    }
    return `/api/v1/cars/${car.value.id}/documents/${doc.id}/download`
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Basic Info Section -->
        <div>
            <h3 class="text-normal text-accent mb-4">Základné informácie</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm mb-1">Model</label>
                    <InputText v-model="car.model" class="w-full" />
                    <small v-if="submitted && !car.model" class="text-danger">Povinné pole</small>
                </div>

                <div>
                    <label class="block text-sm mb-1">EVČ</label>
                    <InputText v-model="car.evc" class="w-full" />
                    <small v-if="submitted && !car.evc" class="text-danger">Povinné pole</small>
                </div>

                <div class="col-span-12 md:col-span-6">
                    <label class="block mb-1">VIN</label>
                    <InputText v-model="car.vin" maxlength="17" class="w-full" />
                    <small>17 znakov, ak je vozidlu VIN pridelené.</small>
                </div>

                <div class="w-full">
                    <label class="block text-sm mb-1">Majiteľ vozidla</label>
                    <AutoComplete
                        v-model="car.owner_name"
                        :suggestions="ownerSuggestions"
                        @complete="searchOwnerSuggestions"
                        dropdown
                        class="w-full"
                        inputClass="!w-full"
                        fluid
                    />
                </div>

                <div>
                    <label class="block text-sm mb-1">Spotreba (l/100 km)</label>
                    <InputNumber
                        v-model="car.fuel_consumption_l_per_100km"
                        mode="decimal"
                        locale="sk-SK"
                        :min="0"
                        :max="99.99"
                        :minFractionDigits="1"
                        :maxFractionDigits="2"
                        class="w-full"
                    />
                </div>

                <div>
                    <label class="block text-sm mb-1">Používateľ</label>
                    <Select v-model="car.user_id" :options="users" optionLabel="first_name" optionValue="id">
                        <template #value="slotProps">
                            <span v-if="slotProps.value">
                                {{formatUserFullName(users.find(n => n.id === slotProps.value) as User)}}
                            </span>
                            <span v-else>Vybrať sestru</span>
                        </template>
                        <template #option="slotProps">
                            <span v-if="slotProps.option">
                                {{ formatUserFullName(slotProps.option) }}
                            </span>
                        </template>
                    </Select>
                </div>
            </div>
        </div>

        <!-- Documents Section -->
        <div v-if="car.id">
            <h3 class="text-normal text-accent mb-4">Dokumenty a fotografie</h3>

            <!-- Hidden file input -->
            <input ref="galleryInputRef" type="file" accept="image/*,application/pdf" multiple class="hidden"
                @change="handleDocumentFileSelected" />

            <div v-if="documentsPreviewUrls.length > 0" class="grid grid-cols-2 gap-3 bg-white p-3 rounded-md mb-4">
                <div v-for="(url, index) in documentsPreviewUrls" :key="url" class="relative">
                    <img v-if="url.startsWith('blob:')" :src="url"
                        class="w-full h-40 object-cover rounded-md border border-gray-200" />
                    <div v-else
                        class="w-full h-40 rounded-md border border-gray-200 bg-gray-100 flex items-center justify-center text-gray-500">
                        <i class="bi bi-file-pdf text-2xl"></i>
                    </div>
                    <button type="button" @click="removeDocumentPreview(index)"
                        class="absolute top-2 right-2 bg-white/50 rounded px-2 py-1 text-darkgrey text-mini"
                        aria-label="Odstrániť dokument" :disabled="uploading">
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <div class="absolute bottom-2 left-2 bg-white/50 rounded px-2 py-1 text-mini text-darkgrey">
                        {{ index + 1 }}
                    </div>
                </div>
            </div>

            <div v-if="documents.length > 0" class="grid grid-cols-2 gap-3">
                <div v-for="doc in documents" :key="doc.id"
                    class="relative cursor-pointer group rounded-md overflow-hidden" @click="openDocumentPreview(doc)">
                    <img v-if="doc.mime_type?.startsWith('image/')" :src="getDocumentPreviewUrl(doc)"
                        class="w-full h-40 object-cover bg-darkgrey/20" :alt="`Document ${doc.id}`" />
                    <div v-else
                        class="w-full h-40 flex items-center justify-center bg-gray-100 group-hover:bg-gray-200 transition-colors">
                        <i class="bi bi-file-pdf text-4xl text-gray-400"></i>
                    </div>

                    <div
                        class="absolute inset-0 bg-black/30 group-hover:bg-black/40 transition-colors flex items-center justify-center">
                        <i class="bi bi-eye text-white text-2xl"></i>
                    </div>
                </div>
            </div>

            <div class="flex justify-start items-center gap-2 mt-4">
                <Button icon="bi bi-plus-lg"
                    @click="() => { if (!car.id) { toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nejprve uložte auto', life: 3000 }); return } galleryInputRef?.click() }"
                    class="bg-accent! border-none! hover:bg-darkgrey! h-7!" />
            </div>


        </div>

        <div v-if="car.id">
            <h3 class="text-normal text-accent mb-4">Údržba</h3>

            <DataTable v-if="services.length > 0" :value="services" class="text-sm">
                <Column field="name" header="Názov" />
                <Column field="interval_days" header="Frekvencia">
                    <template #body="{ data }">
                        {{ getFrequencyLabel(data.interval_days) }}
                    </template>
                </Column>
                <Column field="date" header="Dátum údržby">
                    <template #body="{ data }">
                        {{ formatDate(data.date) }}
                    </template>
                </Column>
                <Column header="" style="width: 3rem">
                    <template #body="{ data }">
                        <Button icon="bi bi-pencil" class="text-darkgrey! text-normal! bg-transparent! border-none!"
                            @click="editService(data)" />
                    </template>
                </Column>
                <Column header="" style="width: 3rem">
                    <template #body="{ data }">
                        <Button icon="bi bi-eraser" class="text-danger! text-normal! bg-transparent! border-none!"
                            @click="deleteService(data)" />
                    </template>
                </Column>
            </DataTable>

            <div class="flex justify-start items-center gap-2 mt-4">
                <Button icon="bi bi-plus-lg"
                    @click="() => { editingServiceId = null; resetServiceForm(); showServiceDialog = true }"
                    class="bg-accent! border-none! hover:bg-darkgrey! h-7!" />
            </div>

        </div>

        <div class="flex justify-end gap-2 mt-4">
            <Button label="Zrušiť" text @click="() => { if (props.modalResolve) props.modalResolve(null) }"
                class="text-accent! px-2!" />
            <Button label="Uložiť"
                class="bg-accent! border-accent! px-2! hover:bg-darkgrey! hover:border-darkgrey! text-white!"
                @click="save" />
        </div>



        <Dialog v-model:visible="showServiceDialog" :header="editingServiceId ? 'Upraviť' : 'Nová'" :modal="true"
            style="width: 500px">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm mb-1">Názov</label>
                    <InputText v-model="newService.name" class="w-full" placeholder="napr. Technická kontrola" />
                </div>

                <div>
                    <label class="block text-sm mb-1">Dátum údržby</label>
                    <DatePicker v-model="newService.date" dateFormat="dd.mm.yy" class="w-full" inputClass="w-full!" />
                </div>

                <div>
                    <label class="block text-sm mb-1">Opakovať každých</label>
                    <div class="grid grid-cols-5 gap-2">
                        <InputNumber
                            v-model="newService.interval_amount"
                            inputId="horizontal-buttons"
                            showButtons
                            buttonLayout="horizontal"
                            :step="1"
                            fluid
                            :min="1"
                            :minFractionDigits="0"
                            :maxFractionDigits="0"
                            class="col-span-2 w-full"
                        >
                            <template #incrementbuttonicon>
                                <span class="bi bi-plus" />
                            </template>
                            <template #decrementbuttonicon>
                                <span class="bi bi-dash" />
                            </template>
                        </InputNumber>
                        <Select v-model="newService.interval_unit" :options="intervalUnitOptions"
                            optionLabel="label" optionValue="value" class="w-full col-span-3" />
                    </div>
                </div>

                <div class="flex gap-2 mt-4 justify-end">
                    <Button label="Zrušiť" text @click="showServiceDialog = false" class="text-accent!" />
                    <Button label="Uložiť"
                        class="bg-accent! border-accent! hover:bg-darkgrey! hover:border-darkgrey! px-2!"
                        @click="saveService" />
                </div>
            </div>
        </Dialog>

        <Dialog v-model:visible="showDocumentPreview" :header="`Náhľad dokumentu`" :modal="true"
            style="width: 80vw; max-width: 900px">
            <div v-if="selectedDocumentPreview" class="flex flex-col gap-4">
                <img v-if="selectedDocumentPreview.mime_type?.startsWith('image/')"
                    :src="getDocumentPreviewUrl(selectedDocumentPreview)"
                    class="w-full max-h-[70vh] object-contain rounded-md" :alt="selectedDocumentPreview.mime_type" />
                <!-- PDF or unsupported file -->
                <div v-else
                    class="w-full h-[70vh] flex flex-col items-center justify-center bg-gray-100 rounded-md gap-4">
                    <i class="bi bi-file-pdf text-6xl text-gray-400"></i>
                    <p class="text-gray-600">PDF náhľad nie je dostupný. Stiahnite si súbor.</p>
                    <Button icon="bi bi-download" label="Stiahnuť" class="bg-accent! border-accent! text-white!"
                        @click="downloadDocument(selectedDocumentPreview.id)" />
                </div>

                <div class="flex gap-2">
                    <Button icon="bi bi-eraser" label="Vymazať" severity="danger"
                        class="flex-1 border-none! bg-danger! hover:bg-darkgrey!"
                        @click="() => { deleteDocument(selectedDocumentPreview!); showDocumentPreview = false }" />
                </div>
            </div>
        </Dialog>
    </div>
</template>
