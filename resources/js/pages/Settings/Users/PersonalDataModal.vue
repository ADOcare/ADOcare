<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'
import api from '@/services/api'
import type { User } from '@/types/models'
import type { IModalContentProps } from '@/types/ui'

const props = defineProps<IModalContentProps & { user: User }>()
const toast = useToast()
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const form = reactive({
    first_name: props.user.first_name ?? '',
    last_name: props.user.last_name ?? '',
    title: props.user.title ?? '',
    email: props.user.email ?? '',
    phone_number: props.user.phone_number ?? '',
    code: props.user.code ?? '',
})

function close() {
    props.modalResolve?.(null)
}

function validationErrors(error: unknown): Record<string, string> {
    const responseErrors = (error as {
        response?: { data?: { errors?: Record<string, string[]> } }
    }).response?.data?.errors ?? {}

    return Object.fromEntries(
        Object.entries(responseErrors).map(([field, messages]) => [field, messages[0] ?? 'Neplatná hodnota.']),
    )
}

async function save() {
    errors.value = {}

    if (!form.first_name.trim()) errors.value.first_name = 'Meno je povinné.'
    if (!form.last_name.trim()) errors.value.last_name = 'Priezvisko je povinné.'
    if (Object.keys(errors.value).length) return

    saving.value = true

    try {
        const response = await api.patch('/auth/profile', {
            first_name: form.first_name.trim(),
            last_name: form.last_name.trim(),
            title: form.title.trim() || null,
            email: form.email.trim() || null,
            phone_number: form.phone_number.trim() || null,
            code: form.code.trim() || null,
        })
        const updatedUser = response.data?.data as User

        toast.add({
            severity: 'success',
            summary: 'Osobné údaje boli uložené',
            life: 3000,
        })
        props.modalResolve?.(updatedUser)
    } catch (error) {
        errors.value = validationErrors(error)
        toast.add({
            severity: 'error',
            summary: 'Osobné údaje sa nepodarilo uložiť',
            detail: Object.values(errors.value)[0] ?? 'Skúste to znova.',
            life: 5000,
        })
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="save">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-12">
            <FormField
                v-model.trim="form.title"
                label="Titul"
                :error="errors.title"
                class="sm:col-span-2"
            />
            <FormField
                v-model.trim="form.first_name"
                label="Meno"
                required
                :error="errors.first_name"
                class="sm:col-span-5"
            />
            <FormField
                v-model.trim="form.last_name"
                label="Priezvisko"
                required
                :error="errors.last_name"
                class="sm:col-span-5"
            />
            <FormField
                v-model.trim="form.code"
                label="Kód pracovníka"
                :error="errors.code"
                class="sm:col-span-12"
            />
            <FormField
                v-model.trim="form.email"
                label="E-mail"
                type="email"
                autocomplete="email"
                :error="errors.email"
                class="sm:col-span-6"
            />
            <FormField
                v-model.trim="form.phone_number"
                label="Telefón"
                autocomplete="tel"
                :error="errors.phone_number"
                class="sm:col-span-6"
            />
        </div>

        <div class="mt-2 flex justify-end gap-2">
            <AppButton label="Zrušiť" variant="text" @click="close" />
            <AppButton type="submit" label="Uložiť" :loading="saving" />
        </div>
    </form>
</template>
