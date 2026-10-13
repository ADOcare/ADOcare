<script setup lang="ts">
import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'

import type { IModalContentProps } from '@/types/ui'
import api from '@/services/api'

import FormField from '@/components/FormField.vue'
import AppButton from '@/components/AppButton.vue'

const props = defineProps<IModalContentProps & {
    userId: number
}>()

const toast = useToast()

const password = ref('')
const confirmPassword = ref('')

const showPassword = ref(false)
const showConfirmPassword = ref(false)

const submitted = ref(false)
const saving = ref(false)

const passwordsMatch = () => {
    return password.value === confirmPassword.value
}

function togglePasswordVisibility() {
    showPassword.value = !showPassword.value
}

function toggleConfirmPasswordVisibility() {
    showConfirmPassword.value = !showConfirmPassword.value
}

function passwordError() {
    if (!submitted.value) {
        return null
    }

    if (!password.value) {
        return 'Heslo je povinné.'
    }

    return null
}

function confirmPasswordError() {
    if (!submitted.value) {
        return null
    }

    if (!confirmPassword.value) {
        return 'Potvrdenie hesla je povinné.'
    }

    if (
        password.value &&
        confirmPassword.value &&
        !passwordsMatch()
    ) {
        return 'Heslá sa nezhodujú.'
    }

    return null
}

async function save() {
    submitted.value = true

    if (
        !password.value ||
        !confirmPassword.value ||
        !passwordsMatch()
    ) {
        return
    }

    saving.value = true

    try {
        await api.patch(
            `v1/users/${props.userId}`,
            {
                pin: password.value,
            },
        )

        toast.add({
            severity: 'success',
            summary: 'Uložené',
            detail: 'Heslo bolo zmenené',
            life: 3000,
        })

        props.modalResolve?.(true)
    } catch (err: unknown) {
        console.error('Nepodarilo sa zmeniť heslo', err)

        let detail = 'Nepodarilo sa zmeniť heslo'

        const error = err as {
            response?: {
                data?: {
                    errors?: Record<string, string[]>
                    message?: string
                }
            }
        }

        if (error.response?.data?.errors) {
            detail = Object
                .values(error.response.data.errors)
                .flat()
                .join(', ')
        } else if (error.response?.data?.message) {
            detail = error.response.data.message
        }

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail,
            life: 5000,
        })
    } finally {
        saving.value = false
    }
}

function close() {
    props.modalResolve?.(null)
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <FormField
            label="Nové heslo"
            required
            :error="passwordError()"
            v-slot="{ id, describedBy, invalid }"
        >
            <IconField class="flex items-center w-full">
                <InputText
                    :id="id"
                    v-model="password"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="new-password"
                    class="w-full"
                    :invalid="invalid"
                    :aria-invalid="invalid || undefined"
                    :aria-describedby="describedBy"
                />

                <InputIcon>
                    <button
                        type="button"
                        class="cursor-pointer"
                        :aria-label="
                            showPassword
                                ? 'Skryť heslo'
                                : 'Zobraziť heslo'
                        "
                        @click="togglePasswordVisibility"
                    >
                        <i
                            :class="
                                showPassword
                                    ? 'bi bi-eye'
                                    : 'bi bi-eye-slash'
                            "
                        />
                    </button>
                </InputIcon>
            </IconField>
        </FormField>

        <FormField
            label="Potvrďte heslo"
            required
            :error="confirmPasswordError()"
            v-slot="{ id, describedBy, invalid }"
        >
            <IconField class="flex items-center w-full">
                <InputText
                    :id="id"
                    v-model="confirmPassword"
                    :type="showConfirmPassword ? 'text' : 'password'"
                    autocomplete="new-password"
                    class="w-full"
                    :invalid="invalid"
                    :aria-invalid="invalid || undefined"
                    :aria-describedby="describedBy"
                    @keydown.enter="save"
                />

                <InputIcon>
                    <button
                        type="button"
                        class="cursor-pointer"
                        :aria-label="
                            showConfirmPassword
                                ? 'Skryť heslo'
                                : 'Zobraziť heslo'
                        "
                        @click="toggleConfirmPasswordVisibility"
                    >
                        <i
                            :class="
                                showConfirmPassword
                                    ? 'bi bi-eye'
                                    : 'bi bi-eye-slash'
                            "
                        />
                    </button>
                </InputIcon>
            </IconField>
        </FormField>

        <div class="flex justify-end gap-2 pt-4">
            <AppButton
                label="Zrušiť"
                variant="text"
                :disabled="saving"
                @click="close"
            />

            <AppButton
                label="Zmeniť heslo"
                :loading="saving"
                @click="save"
            />
        </div>
    </div>
</template>