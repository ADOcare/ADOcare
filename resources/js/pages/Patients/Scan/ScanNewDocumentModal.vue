<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';
import api from '@/services/api';
import { useUiOverlayStore } from '@/stores/uiOverlay';
import { useNotifications } from '@/composables/useNotifications';
import { computed, onBeforeUnmount, ref, watch, watchEffect } from 'vue';
import { useRouter } from 'vue-router';

interface Props {
    patientId: number;
    branchId: number;
    modalResolve?: (result?: ScanModalResult | null) => void;
}

type ScanModalResult = {
    status: 'completed';
    documentId?: number;
};

const props = defineProps<Props>();
const router = useRouter();
const uiOverlayStore = useUiOverlayStore();
const { loadNotifications } = useNotifications();
const emit = defineEmits<{
    close: [];
    'document-created': [documentId: number];
}>();

const isLoading = ref(false);
const qrUrl = ref('');
const sessionId = ref<number | null>(null);

const isPolling = ref(false);
const sessionStarted = ref(false);
const hasFinished = ref(false);
const expiresIn = ref(0);
const maxTime = ref(0);

const expiryProgress = computed(() => {
    if (maxTime.value <= 0 || expiresIn.value <= 0) return 0;

    return Math.max(0, Math.min(100, (expiresIn.value / maxTime.value) * 100));
});

const pollIntervalId = ref<number | null>(null);

function finish(result?: ScanModalResult | null) {
    if (hasFinished.value) return;

    hasFinished.value = true;
    stopPolling();
    isLoading.value = false;
    props.modalResolve?.(result);
}

function stopPolling() {
    isPolling.value = false;
    if (pollIntervalId.value) {
        clearInterval(pollIntervalId.value);
        pollIntervalId.value = null;
    }
}

onBeforeUnmount(() => {
    stopPolling();
});

watchEffect(() => {
    uiOverlayStore.setContentLoading(isLoading.value);
});

/**
 * Create a new scan session and get QR code
 */
async function createScanSession() {
    isLoading.value = true;
    hasFinished.value = false;

    try {
        if (!props.patientId || !props.branchId) {
            throw new Error(
                `Invalid props: patientId=${props.patientId}, branchId=${props.branchId}`,
            );
        }

        const payload = {
            patient_id: props.patientId,
            branch_id: props.branchId,
        };

        const response = await api.post('/v1/scan-sessions', payload);

        const responseData = response.data?.data;
        if (!responseData) throw new Error('No data in API response');

        qrUrl.value = responseData.qr_url || '';
        sessionId.value = responseData.session_id || null;

        if (qrUrl.value && sessionId.value) {
            startPolling();
        } else {
            throw new Error(
                `QR URL or Session ID is missing: qr_url="${qrUrl.value}", session_id="${sessionId.value}"`,
            );
        }
    } catch (error: any) {
        console.error('Error creating scan session:', error);

        finish(null);
    } finally {
        isLoading.value = false;
    }
}

function startPolling() {
    if (!sessionId.value) return;

    isPolling.value = true;

    // Clear any existing interval first
    stopPolling();
    isPolling.value = true;

    pollIntervalId.value = window.setInterval(async () => {
        try {
            const response = await api.get(
                `/v1/scan-sessions/${sessionId.value}`,
            );
            const statusData = response.data?.data;
            const remainingSeconds = Number(statusData?.expires_in);
            const lifetimeSeconds = Number(statusData?.lifetime_seconds);

            if (Number.isFinite(remainingSeconds) && remainingSeconds >= 0) {
                expiresIn.value = remainingSeconds;

                if (Number.isFinite(lifetimeSeconds) && lifetimeSeconds > 0) {
                    maxTime.value = Math.max(lifetimeSeconds, remainingSeconds);
                } else if (maxTime.value === 0) {
                    maxTime.value = remainingSeconds;
                }
            }

            // Backend expired
            if (statusData?.status === 'expired') {
                finish(null);
                return;
            }

            if (statusData?.status === 'completed') {
                const documentId = Number(statusData?.document_id);

                if (!Number.isInteger(documentId) || documentId <= 0) {
                    finish(null);
                    return;
                }

                emit('document-created', documentId);
                await loadNotifications(true);
                finish(null);

                await router.push({
                    name: 'documents-scan',
                    params: { documentId },
                });
                return;
            }

        } catch (error: any) {
            console.error('Polling error:', error);

            if (error?.response?.status === 404) {
                finish(null);
                return;
            }

            finish(null);
        }
    }, 500);
}

function generateQRCodeImage(): string {
    if (!qrUrl.value) return '';
    return `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(qrUrl.value)}`;
}

function openScanPage() {
    if (!qrUrl.value) return;
    let url = qrUrl.value;
    if (url.startsWith('/')) {
        url = window.location.origin + url;
    }
    window.open(url, '_blank', 'noopener,noreferrer');
}

watch(
    () => ({ patientId: props.patientId, branchId: props.branchId }),
    ({ patientId, branchId }) => {
        if (patientId && branchId && !sessionStarted.value) {
            sessionStarted.value = true;
            createScanSession();
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-6">
        <div
            v-if="isLoading"
            class="flex flex-col items-center gap-6 py-10 text-accent"
        >
            Načítavam...
        </div>

        <div v-else-if="qrUrl" class="flex w-full flex-col gap-4">
            <div class="flex flex-col items-center gap-4 pt-6 pb-20">
                <img
                    :src="generateQRCodeImage()"
                    alt="QR Code for scan"
                    class="h-40 w-40"
                />
            </div>

            <div
                v-if="isPolling"
                class="h-1 w-full overflow-hidden rounded-full bg-tag3"
            >
                <div
                    class="h-full rounded-full bg-darkgrey transition-[width] duration-500"
                    :style="{ width: `${expiryProgress}%` }"
                />
            </div>

            <AppButton
                type="button"
                variant="text"
                label="
              Nahrať z počítača
          "
                @click="openScanPage"
            />
        </div>
    </div>
</template>
