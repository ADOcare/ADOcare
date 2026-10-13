<script setup lang="ts">
import { onBeforeMount, ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import auth from '@/services/auth';
import InputText from 'primevue/inputtext';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import { useToast } from 'primevue/usetoast';
import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const route = useRoute();
const toast = useToast();
const authStore = useAuthStore();

onBeforeMount(async () => {
    await authStore.waitUntilInitialized();

    if (authStore.isAuthenticated) {
        await redirectAfterLogin();
    }
});

const login = ref('');
const pin = ref('');
const loading = ref(false);
const showPin = ref(false);
const submitted = ref(false);

function togglePinVisibility() {
    showPin.value = !showPin.value;
}

async function redirectAfterLogin() {
    const redirect = typeof route.query.redirect === 'string'
        && route.query.redirect.startsWith('/')
        && !route.query.redirect.startsWith('//')
        && route.query.redirect !== '/login'
        ? route.query.redirect
        : null;

    if (redirect) {
        await router.replace(redirect);
    } else if (authStore.currentRole === 'nurse') {
        await router.replace({ name: 'patients' });
    } else {
        await router.dashboard();
    }
}

async function submit() {
    submitted.value = true;

    if (!login.value || !pin.value) return;

    loading.value = true;

    try {
        await auth.login({ login: login.value, pin: pin.value });

        await redirectAfterLogin();
    } catch (e: any) {
        const message = e.response?.data?.message ||
            "Nepodarilo sa prihlásiť. Skúste ešte raz."

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: message,
            life: 3000
        });
    } finally {
        loading.value = false;
    }
}
</script>


<template>
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-2 overflow-hidden">
        <div class="flex items-center justify-center px-8 py-12">
            <div class="w-full max-w-md min-w-100">
                <form
                    class="flex flex-col gap-6"
                    @submit.prevent="submit"
                >
                    <FormField
                        id="login"
                        v-model.trim="login"
                        label="Prihlasovacie meno"
                        autocomplete="username"
                        required
                        :error="
                            submitted && !login
                                ? 'Prihlasovacie meno je povinné.'
                                : null
                        "
                    />

                    <FormField
                        label="Heslo"
                        required
                        :error="
                            submitted && !pin
                                ? 'Heslo je povinné.'
                                : null
                        "
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <IconField class="flex items-center w-full">
                            <InputText
                                :id="id"
                                v-model="pin"
                                :type="showPin ? 'text' : 'password'"
                                autocomplete="current-password"
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
                                        showPin
                                            ? 'Skryť heslo'
                                            : 'Zobraziť heslo'
                                    "
                                    @click="togglePinVisibility"
                                >
                                    <i
                                        :class="
                                            showPin
                                                ? 'bi bi-eye'
                                                : 'bi bi-eye-slash'
                                        "
                                    />
                                </button>
                            </InputIcon>
                        </IconField>
                    </FormField>

                    <AppButton
                        type="submit"
                        label="Prihlásiť sa"
                        :loading="loading"
                    />
                </form>
            </div>
        </div>

        <div
            class="
                hidden
                lg:flex
                items-center
                justify-center
                overflow-hidden
                bg-accent
                p-12
            "
        >
            <img
                src="/logo.svg"
                alt="ADOcare"
                class="
                    w-[85%]
                    max-w-[850px]
                    h-auto
                    object-contain
                "
            >
        </div>

        <!-- Login side -->

    </div>
</template>
