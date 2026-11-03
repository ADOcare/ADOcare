<script setup lang="ts">
import { ref, onMounted, computed, markRaw, onBeforeUnmount, watch } from 'vue'
import router from '@/router'
import type { IModalContentProps } from '@/types/ui'
import api from '@/services/api'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import type { Branch, Role, User } from '@/types/models'
import { useAuthStore } from '@/stores/auth'
import useModal from '@/composables/useModal'
import ChangePasswordModal from './ChangePasswordModal.vue'
import SignaturePad from '@/components/SignaturePad.vue'

const props = defineProps<IModalContentProps & { userId?: number; baseUrl?: string; companyId?: number }>()

const toast = useToast()
const auth = useAuthStore()
const { openModal } = useModal()

const roleNameMap: Record<string, string> = {
    admin: 'Administrátor',
    superadmin: 'Superadministrátor',
    manager: 'Manažér',
    nurse: 'Sestra',
    physiotherapist: 'Fyzioterapeut',
    branch_manager: 'Manažér pobočky',
}

const getRoleName = (role: Role | undefined): string => {
    if (!role) return ''
    return roleNameMap[role.position || ''] || role.position || role.name || ''
}

type BranchAssign = {
    branch_id?: number | null
    working_time?: number | null
    role_id?: number | null
}

type UserType = 'manager' | 'nurse'

const user = ref<Partial<User> & { role_id?: number | null; pin?: string | null }>({
    first_name: '',
    last_name: '',
    title: '',
    code: '',
    phone_number: '',
    email: '',
    login: '',
    pin: '',
    role_id: null,
})

const userType = ref<UserType>('nurse')

const branchAssignments = ref<BranchAssign[]>([])
const branchOptions = ref<Branch[]>([])
const branchRoles = ref<Role[]>([])
const companyRoles = ref<Role[]>([])

const showPin = ref(false)
const submitted = ref(false)
const loginError = ref<string | null>(null)
const emailError = ref<string | null>(null)
const deleteConfirmVisible = ref(false)
const pendingDeleteIndex = ref<number | null>(null)
const signaturePreviewUrl = ref<string | null>(null)
const signatureBlob = ref<Blob | null>(null)
const signatureChanged = ref(false)
const signatureLoading = ref(false)
const branchesLoading = ref(false)

const canManageUserType = computed(() =>
    ['admin', 'superadmin'].includes(auth.user?.role?.position || '')
)

const hasAnyBranches = computed(() => branchOptions.value.length > 0)

const managerRole = computed(() =>
    companyRoles.value.find(role => role.position === 'manager')
)

const nurseRole = computed(() =>
    branchRoles.value.find(role => role.position === 'nurse')
)

function toNumberOrNull(v: any) {
    if (v === null || v === undefined || v === '') return null
    const n = Number(v)
    return Number.isFinite(n) ? n : null
}

function generateStrongPassword(length = 16) {
    const lowercase = 'abcdefghijkmnopqrstuvwxyz'
    const uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ'
    const numbers = '23456789'
    const symbols = '!@#$%^&*()-_=+'
    const allChars = lowercase + uppercase + numbers + symbols

    const getRandomIndex = (max: number) => {
        if (window.crypto?.getRandomValues) {
            const array = new Uint32Array(1)
            window.crypto.getRandomValues(array)
            return (array[0] ?? 0) % max
        }

        return Math.floor(Math.random() * max)
    }

    const requiredChars = [
        lowercase[getRandomIndex(lowercase.length)],
        uppercase[getRandomIndex(uppercase.length)],
        numbers[getRandomIndex(numbers.length)],
        symbols[getRandomIndex(symbols.length)],
    ]

    const remaining = Array.from({ length: Math.max(length - requiredChars.length, 0) }, () => {
        return allChars[getRandomIndex(allChars.length)]
    })

    const result = [...requiredChars, ...remaining]
        .sort(() => (getRandomIndex(2) === 0 ? -1 : 1))
        .join('')

    user.value.pin = result
    showPin.value = true
}

function normalizeUserFromApi(data: any) {
    const roleId = data?.role_id ?? data?.role?.id ?? null

    user.value = {
        ...user.value,
        ...data,
        role_id: roleId != null ? toNumberOrNull(roleId) : null,
        pin: null,
    }

    branchAssignments.value = (data?.branches ?? []).map((b: any) => ({
        branch_id: b?.id ?? null,
        working_time: b?.pivot?.working_time ?? null,
        role_id: b?.pivot?.role_id != null ? toNumberOrNull(b.pivot.role_id) : null,
    }))

    if (data?.role?.position === 'manager' || companyRoles.value.some(role => Number(role.id) === Number(roleId))) {
        userType.value = 'manager'
    } else {
        userType.value = 'nurse'
    }

    if (userType.value === 'nurse' && branchAssignments.value.length === 0) {
        branchAssignments.value = [{
            branch_id: null,
            working_time: null,
            role_id: nurseRole.value?.id ?? null,
        }]
    }
}

function revokeSignaturePreview() {
    if (signaturePreviewUrl.value && signaturePreviewUrl.value.startsWith('blob:')) {
        URL.revokeObjectURL(signaturePreviewUrl.value)
    }
}

async function loadSignaturePreview(userId: number) {
    try {
        signatureLoading.value = true
        const res = await api.get(`v1/users/${userId}/signature`, { responseType: 'blob' })
        revokeSignaturePreview()
        signaturePreviewUrl.value = URL.createObjectURL(res.data)
    } catch {
        revokeSignaturePreview()
        signaturePreviewUrl.value = null
    } finally {
        signatureLoading.value = false
    }
}

function onSignatureChange(blob: Blob | null) {
    signatureBlob.value = blob
    signatureChanged.value = true

    if (blob) {
        revokeSignaturePreview()
        signaturePreviewUrl.value = URL.createObjectURL(blob)
    } else {
        revokeSignaturePreview()
        signaturePreviewUrl.value = null
    }
}

async function persistSignature(userId: number) {
    if (!signatureChanged.value) return

    if (!signatureBlob.value) {
        await api.delete(`v1/users/${userId}/signature`)
        return
    }

    const formData = new FormData()
    formData.append('signature', signatureBlob.value, `signature_${userId}.png`)

    await api.post(`v1/users/${userId}/signature`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    })
}

function ensureNurseAssignment() {
    if (branchAssignments.value.length === 0) {
        branchAssignments.value.push({
            branch_id: null,
            working_time: null,
            role_id: nurseRole.value?.id ?? null,
        })
    }
}

function applyUserTypeDefaults(type: UserType) {
    if (type === 'manager') {
        user.value.role_id = managerRole.value?.id ?? null
        branchAssignments.value = []
        return
    }

    user.value.role_id = null
    ensureNurseAssignment()

    branchAssignments.value = branchAssignments.value.map(assign => ({
        ...assign,
        role_id: nurseRole.value?.id ?? assign.role_id ?? null,
    }))
}

watch(userType, newType => {
    applyUserTypeDefaults(newType)
})

async function loadBranches() {
    try {
        branchesLoading.value = true

        const routeCompanyId = Number(router.currentRoute.value.params.companyId)
        const targetCompanyId = Number(props.companyId ?? routeCompanyId)
        const hasTargetCompanyId = Number.isFinite(targetCompanyId) && targetCompanyId > 0

        const branchesUrl = auth.isSuperadmin && hasTargetCompanyId
            ? `v1/companies/${targetCompanyId}/branches`
            : 'v1/my-company/branches'

        const branches = await api.fetchEntities<Branch>(branchesUrl)
        branchOptions.value = Array.isArray(branches) ? branches : []
    } catch (e) {
        console.error('Nepodarilo sa načítať pobočky', e)
        branchOptions.value = []
    } finally {
        branchesLoading.value = false
    }
}

function confirmDeleteAssignment(idx: number) {
    const assign = branchAssignments.value[idx]
    const hasData =
        (assign?.branch_id !== null && assign?.branch_id !== undefined) ||
        (assign?.working_time !== null && assign?.working_time !== undefined) ||
        (assign?.role_id !== null && assign?.role_id !== undefined)

    if (!hasData) {
        branchAssignments.value.splice(idx, 1)
        return
    }

    pendingDeleteIndex.value = idx
    deleteConfirmVisible.value = true
}

function cancelDelete() {
    deleteConfirmVisible.value = false
    pendingDeleteIndex.value = null
}

async function confirmDelete() {
    const idx = pendingDeleteIndex.value
    if (idx === null) return

    const assign = branchAssignments.value[idx]
    if (!assign) return

    deleteConfirmVisible.value = false

    try {
        if (props.userId && assign.branch_id) {
            await api.delete(`v1/users/${props.userId}/branches/${assign.branch_id}`)
            toast.add({
                severity: 'success',
                summary: 'Zmazané',
                detail: 'Priradenie pobočky bolo zmazané',
                life: 3000,
            })
        }

        branchAssignments.value.splice(idx, 1)

        if (userType.value === 'nurse' && branchAssignments.value.length === 0) {
            ensureNurseAssignment()
        }
    } catch (err) {
        console.error('Nepodarilo sa odstrániť priradenie', err)
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Nepodarilo sa odstrániť priradenie',
            life: 3000,
        })
    } finally {
        pendingDeleteIndex.value = null
    }
}

function togglePasswordVisibility() {
    showPin.value = !showPin.value
}

async function openChangePasswordModal() {
    await openModal(
        markRaw(ChangePasswordModal),
        { userId: props.userId },
        { header: 'Zmeniť heslo', style: { width: '500px' } }
    )
}

onMounted(async () => {
    try {
        await loadBranches()

        // Retry once during modal init to avoid stale/late data when opening right after branch changes.
        if (branchOptions.value.length === 0) {
            await loadBranches()
        }

        await Promise.all([
            api.fetchEntities<Role>('v1/roles/branch').then((roles: Role[]) => {
                branchRoles.value = Array.isArray(roles) ? roles : []
            }),
            api.fetchEntities<Role>('v1/roles/company').then((roles: Role[]) => {
                companyRoles.value = Array.isArray(roles) ? roles : []
            }),
        ])
    } catch (e) {
        console.error('Nepodarilo sa načítať pobočky alebo role', e)
    }

    if (props.userId != null) {
        try {
            const data = await api.fetchEntity<User>(`v1/users/${props.userId}`)
            normalizeUserFromApi(data)
            await loadSignaturePreview(props.userId)
        } catch (e) {
            console.error('Nepodarilo sa načítať používateľa', e)
        }
    } else {
        if (props.companyId) {
            user.value.company_id = props.companyId
        }

        applyUserTypeDefaults(userType.value)
    }
})

onBeforeUnmount(() => {
    revokeSignaturePreview()
})

const save = async () => {
    submitted.value = true
    loginError.value = null
    emailError.value = null

    const creating = !props.userId
    const firstName = (user.value.first_name ?? '').trim()
    const lastName = (user.value.last_name ?? '').trim()
    const code = (user.value.code ?? '').trim()
    const login = (user.value.login ?? '').trim()
    const email = (user.value.email ?? '').trim()
    const pin = (user.value.pin ?? '').trim()

    if (
        !firstName ||
        !lastName ||
        !code ||
        (creating && !login) ||
        !email ||
        (creating && !pin)
    ) {
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Vyplňte povinné údaje',
            life: 5000,
        })
        return
    }

    if (userType.value === 'manager' && !managerRole.value) {
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Rola manažéra nebola nájdená.',
            life: 5000,
        })
        return
    }

    try {
        const payload: Record<string, any> = {
            ...user.value,
            first_name: firstName,
            last_name: lastName,
            code,
            login,
            email,
            pin,
        }

        if (creating) {
            if (props.companyId) payload.company_id = props.companyId
            if (!payload.pin) delete payload.pin
        } else {
            delete payload.pin
            delete payload.login
        }

        if (canManageUserType.value) {
            if (userType.value === 'manager') {
                payload.role_id = toNumberOrNull(managerRole.value?.id)
                payload.branches = []
            } else {
                payload.role_id = null
                payload.branches = branchAssignments.value
                    .filter(assign => assign.branch_id != null)
                    .map(assign => ({
                        branch_id: assign.branch_id ?? null,
                        working_time: assign.working_time ?? null,
                        role_id: toNumberOrNull(assign.role_id ?? nurseRole.value?.id),
                    }))
            }
        } else {
            delete payload.role_id
            payload.branches = branchAssignments.value
                .filter(assign => assign.branch_id != null)
                .map(assign => ({
                    branch_id: assign.branch_id ?? null,
                    working_time: assign.working_time ?? null,
                    role_id: assign.role_id != null ? toNumberOrNull(assign.role_id) : null,
                }))
        }

        const resp = creating
            ? await api.post('v1/users/', payload)
            : await api.patch(`v1/users/${props.userId}`, payload)

        const savedUserId = Number(resp?.data?.data?.id ?? props.userId)
        if (savedUserId && signatureChanged.value) {
            await persistSignature(savedUserId)
        }

        if (props.modalResolve) {
            props.modalResolve(resp.data.data)
        }
    } catch (err: any) {
        console.error('Nepodarilo sa uložiť používateľa', err)

        const backendMessage = String(err?.response?.data?.message || '').trim()
        const errors = err?.response?.data?.errors || {}
        const loginErrors = (Array.isArray(errors?.login) ? errors.login : []).map((v: any) => String(v).trim()).filter(Boolean)
        const emailErrors = (Array.isArray(errors?.email) ? errors.email : []).map((v: any) => String(v).trim()).filter(Boolean)
        const firstValidationError = Object.values(errors)
            .flatMap((value: any) => Array.isArray(value) ? value : [])
            .map((value: any) => String(value).trim())
            .find(Boolean)

        if (emailErrors.length > 0) {
            emailError.value = emailErrors[0]
            toast.add({
                severity: 'error',
                summary: 'Chyba',
                detail: emailErrors[0],
                life: 5000,
            })
            return
        }

        if (creating && loginErrors.length > 0) {
            loginError.value = loginErrors[0]
            toast.add({
                severity: 'error',
                summary: 'Chyba',
                detail: loginErrors[0],
                life: 5000,
            })
            return
        }

        if (backendMessage) {
            toast.add({
                severity: 'error',
                summary: 'Chyba',
                detail: backendMessage,
                life: 5000,
            })
            return
        }

        if (firstValidationError) {
            toast.add({
                severity: 'error',
                summary: 'Chyba',
                detail: firstValidationError,
                life: 5000,
            })
            return
        }

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Nepodarilo sa uložiť používateľa',
            life: 3000,
        })
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">Meno</label>
                <InputText v-model="user.first_name" class="w-full" />
                <small v-if="submitted && !user.first_name" class="text-danger">Meno je povinné.</small>
            </div>

            <div>
                <label class="block text-sm mb-1">Priezvisko</label>
                <InputText v-model="user.last_name" class="w-full" />
                <small v-if="submitted && !user.last_name" class="text-danger">Priezvisko je povinné.</small>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">Titul</label>
                <InputText v-model="user.title" class="w-full" />
            </div>

            <div>
                <label class="block text-sm mb-1">Kód</label>
                <InputText v-model="user.code" class="w-full" />
                <small v-if="submitted && !user.code" class="text-danger">Kód je povinný.</small>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">Telefón</label>
                <InputText v-model="user.phone_number" class="w-full" />
            </div>

            <div>
                <label class="block text-sm mb-1">Email</label>
                <InputText v-model="user.email" @input="emailError = null" class="w-full" />
                <small v-if="emailError" class="text-danger">{{ emailError }}</small>
                <small v-else-if="submitted && !user.email" class="text-danger">Email je povinný.</small>
            </div>
        </div>

        <h4 class="text-accent text-normal mt-2">Prihlasovacie údaje</h4>

        <div class="grid grid-cols-2 gap-4">
            <div v-if="!props.userId">
                <label class="block text-sm mb-1">Prihlasovacie meno</label>
                <InputText v-model="user.login" @input="loginError = null" class="w-full" />
                <small v-if="loginError" class="text-danger">{{ loginError }}</small>
                <small v-else-if="submitted && !user.login" class="text-danger">
                    Prihlasovacie meno je povinné.
                </small>
            </div>

            <div v-if="!props.userId">
                <label class="block text-sm mb-1">Heslo</label>

                <div class="flex gap-2">
                    <IconField class="flex items-center w-full">
                        <InputText
                            v-model="user.pin"
                            :type="showPin ? 'text' : 'password'"
                            class="w-full"
                        />
                        <InputIcon>
                            <i
                                :class="showPin ? 'bi bi-eye' : 'bi bi-eye-slash'"
                                class="cursor-pointer"
                                @click="togglePasswordVisibility"
                            />
                        </InputIcon>
                    </IconField>

                    <Button
                        type="button"
                        icon="bi bi-shuffle"
                        class="bg-accent! border-accent! px-3! hover:bg-darkgrey! hover:border-darkgrey! text-white! h-7!"
                        @click="generateStrongPassword()"
                    />
                </div>

                <small v-if="submitted && !user.pin" class="text-danger">Heslo je povinné.</small>
            </div>

            <div v-if="props.userId" class="flex items-end col-span-2">
                <Button
                    label="Zmeniť heslo"
                    icon="bi bi-key"
                    class="bg-accent! border-accent! px-2! hover:bg-darkgrey! hover:border-darkgrey! text-white! w-full"
                    @click="openChangePasswordModal"
                />
            </div>
        </div>

        <div v-if="canManageUserType" class="mt-2">
            <label class="block text-sm mb-2">Typ používateľa</label>

            <div class="inline-flex rounded-md overflow-hidden border border-gray-300">
                <button
                    type="button"
                    class="px-4 transition-colors cursor-pointer h-7 text-normal"
                    :class="userType === 'manager' ? 'bg-accent text-white' : 'bg-white text-dark'"
                    @click="userType = 'manager'"
                >
                    Manažér
                </button>

                <button
                    type="button"
                    class="px-4 transition-colors border-l border-gray-300 cursor-pointer h-7 text-normal"
                    :class="userType === 'nurse' ? 'bg-accent text-white' : 'bg-white text-dark'"
                    @click="userType = 'nurse'"
                >
                    Sestra
                </button>
            </div>
        </div>

        <template v-if="userType === 'nurse'">

            <div class="space-y-3">
                <div
                    v-for="(assign, idx) in branchAssignments"
                    :key="idx"
                    class="grid grid-cols-12 gap-2 items-end"
                >
                    <div class="col-span-5">
                        <label class="block text-sm mb-1">Pobočka</label>
                        <Select
                            v-model="assign.branch_id"
                            :options="branchOptions"
                            optionLabel="address"
                            optionValue="id"
                            class="w-full"
                            :disabled="!hasAnyBranches"
                            placeholder="Vyberte pobočku"
                        />
                    </div>

                    <div class="col-span-3">
                        <label class="block text-sm mb-1">Rola</label>
                        <Select
                            v-model="assign.role_id"
                            :options="branchRoles"
                            :optionLabel="getRoleName"
                            optionValue="id"
                            class="w-full"
                            placeholder="Vyberte rolu"
                        />
                    </div>

                    <div class="col-span-3">
                        <label class="block text-sm mb-1">Úväzok</label>
                        <InputNumber
                            v-model="assign.working_time"
                            mode="decimal"
                            locale="sk-SK"
                            :min="0"
                            :max="1"
                            :step="0.1"
                            :minFractionDigits="1"
                            :maxFractionDigits="2"
                            :useGrouping="false"
                            class="w-full"
                        />
                    </div>

                    <div class="col-span-1">
                        <Button
                            icon="bi bi-eraser"
                            class="h-7! bg-danger!"
                            severity="danger"
                            @click="confirmDeleteAssignment(idx)"
                        />
                    </div>
                </div>

                <div>
                    <Button
                        label="Pridať pobočku"
                        class="w-full! bg-accent! border-accent! px-2! hover:bg-darkgrey! hover:border-darkgrey! text-white!"
                        @click="branchAssignments.push({ branch_id: null, working_time: null, role_id: nurseRole?.id ?? null })"
                    />
                </div>
            </div>
        </template>

        <h4 class="text-accent text-normal mt-2">Podpis používateľa</h4>

        <div class="flex flex-col gap-2">
            <SignaturePad
                :initial-image-url="signaturePreviewUrl"
                :disabled="signatureLoading"
                @change="onSignatureChange"
            />
        </div>

        <div class="flex justify-end gap-2 mt-4">
            <Button
                label="Zrušiť"
                text
                @click="props.modalResolve && props.modalResolve(null)"
                class="text-accent! px-2!"
            />
            <Button
                label="Uložiť"
                class="bg-accent! border-accent! px-2! hover:bg-darkgrey! hover:border-darkgrey! text-white!"
                @click="save"
            />
        </div>

        <Dialog
            v-model:visible="deleteConfirmVisible"
            :style="{ width: '500px' }"
            :modal="true"
            :closable="false"
            header="Upozornenie"
        >
            <div class="flex items-center justify-between w-full">
                <span class="text-heading">
                    Priradenie obsahuje údaje. Naozaj ho chcete odstrániť?
                </span>

                <div class="flex items-center gap-2">
                    <Button
                        label="Nie"
                        text
                        @click="cancelDelete"
                        class="!bg-accent !px-4 !text-white hover:!bg-darkgrey !border-0"
                    />
                    <Button
                        label="Áno"
                        text
                        @click="confirmDelete"
                        class="!bg-danger !px-4 !text-white"
                    />
                </div>
            </div>
        </Dialog>
    </div>
</template>

<style scoped>
.p-field {
    margin-bottom: 0.5rem;
}
</style>