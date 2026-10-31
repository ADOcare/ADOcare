<script setup lang="ts">
import DocumentShell, {
    type DownloadOption,
} from '@/components/DocumentShell.vue';
import { useApi } from '@/composables/useApi';
import { useUiOverlayStore } from '@/stores/uiOverlay';
import type { Doctor, Patient } from '@/types/models';
import { formatBranchFullName, formatUserFullName } from '@/utils/formatUtils';
import { useToast } from 'primevue/usetoast';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

interface PatientsPrintPayload {
    mode: 'selected' | 'filtered';
    selectedPatients?: Patient[];
    endpointUrl?: string;
    params?: Record<string, unknown>;
}

interface PatientsResponse {
    data?: {
        items?: Patient[];
        data?: Patient[];
        meta?: { last_page?: number };
        last_page?: number;
    };
}

const { request } = useApi();
const toast = useToast();
const uiOverlayStore = useUiOverlayStore();
const patients = ref<Patient[]>([]);
const printedAt = ref('');
const previewHtml = ref('');
const errorMessage = ref('');
const measurementRef = ref<HTMLElement | null>(null);
const loading = ref(true);
let downloadUrl = '';
let disposed = false;

const downloadOptions = computed<DownloadOption[]>(() =>
    downloadUrl && previewHtml.value
        ? [
              {
                  url: downloadUrl,
                  filename: 'zoznam-pacientov.pdf',
                  fileType: 'PDF',
                  contentType: 'application/pdf',
              },
          ]
        : [],
);

const documentStyles = `
    @page { size: A4; margin: 0; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: white; }
    .print-page {
        width: 210mm; height: 297mm; padding: 14mm;
        background: white; font-family: Arial, sans-serif; color: #111827;
        break-after: page; page-break-after: always;
    }
    .print-page:last-child { break-after: auto; page-break-after: auto; }
    .page-inner { height: 100%; }
    h1 { margin: 0 0 16px; text-align: center; font-size: 18px; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .metadata { margin-bottom: 16px; font-size: 14px; }
    .metadata td { border: 1px solid black; padding: 8px; }
    .patients-table { font-size: 10.5px; }
    .patients-table th, .patients-table td {
        border: 1px solid #111827; padding: 5px 6px; vertical-align: top;
        overflow-wrap: anywhere; word-break: break-word;
    }
    .patients-table th { text-align: left; }
    .patients-table th:first-child, .patients-table td:first-child { text-align: center; }
    .empty { margin-top: 28px; font-size: 14px; }
`;

function formatDoctor(doctor?: Doctor | null): string {
    if (!doctor) return '-';
    return (
        `${doctor.title ?? ''} ${doctor.first_name ?? ''} ${doctor.last_name ?? ''}`.trim() ||
        '-'
    );
}

async function fetchAllFilteredPatients(
    endpointUrl: string,
    params: Record<string, unknown>,
): Promise<Patient[]> {
    const collected = new Map<number, Patient>();
    let page = 1;
    let lastPage = 1;

    do {
        const response = await request<PatientsResponse>({
            url: endpointUrl,
            method: 'get',
            params: {
                ...params,
                with: 'nurse,doctor,branch',
                paginate: true,
                per_page: 100,
                page,
            },
        });
        if (response.error) throw response.error;
        if (disposed) return [];

        const data = response.data.data;
        const items = data?.items ?? data?.data;
        const totalPages = Number(
            data?.meta?.last_page ?? data?.last_page ?? 1,
        );
        if (
            !Array.isArray(items) ||
            !Number.isInteger(totalPages) ||
            totalPages < 1
        ) {
            throw new Error('Neplatná odpoveď zoznamu pacientov.');
        }

        items.forEach((patient) => collected.set(patient.id, patient));
        lastPage = totalPages;
        page += 1;
    } while (page <= lastPage);

    return Array.from(collected.values());
}

async function fetchSelectedPatients(selected: Patient[]): Promise<Patient[]> {
    const result: Patient[] = [];
    for (let offset = 0; offset < selected.length; offset += 10) {
        const batch = await Promise.all(selected.slice(offset, offset + 10).map(async (patient) => {
            const response = await request<{ data: Patient }>({
                url: `/v1/patients/${patient.id}`,
                method: 'get',
            });
            if (response.error) throw response.error;
            if (!response.data?.data || response.data.data.id !== patient.id) {
                throw new Error('Nepodarilo sa načítať údaje vybraného pacienta.');
            }
            return response.data.data;
        }));
        if (disposed) return [];
        result.push(...batch);
    }
    return result;
}

async function preparePreview(): Promise<void> {
    await nextTick();
    if (disposed) return;

    const iframe = document.createElement('iframe');
    iframe.style.cssText =
        'position:fixed;left:-10000px;top:0;width:210mm;height:297mm;visibility:hidden;border:0';
    iframe.setAttribute('aria-hidden', 'true');
    document.body.appendChild(iframe);
    try {
        const frameDocument = iframe.contentDocument;
        if (!frameDocument || !measurementRef.value) {
            throw new Error('Nepodarilo sa pripraviť strany dokumentu.');
        }
        frameDocument.open();
        frameDocument.write(`<!doctype html><html><head><style>${documentStyles}</style></head>
        <body>${measurementRef.value.innerHTML}</body></html>`);
        frameDocument.close();
        await frameDocument.fonts.ready;
        if (disposed) return;

        const sheet = frameDocument.querySelector<HTMLElement>('.print-page');
        const inner = sheet?.querySelector<HTMLElement>('.page-inner');
        const header = sheet?.querySelector<HTMLElement>('.page-header');
        const tableHead = sheet?.querySelector<HTMLElement>('thead');
        const rows = Array.from(
            sheet?.querySelectorAll<HTMLTableRowElement>(
                'tbody.patient-rows tr',
            ) ?? [],
        );
        if (!sheet || !inner || !header || !tableHead) {
            throw new Error('Nepodarilo sa pripraviť strany dokumentu.');
        }

        const capacity =
            inner.clientHeight -
            header.getBoundingClientRect().height -
            tableHead.getBoundingClientRect().height -
            10;
        const pages: HTMLTableRowElement[][] = [[]];
        let usedHeight = 0;

        for (const row of rows) {
            const height = row.getBoundingClientRect().height;
            if (height > capacity) {
                throw new Error(
                    'Záznam pacienta je príliš veľký na jednu stranu.',
                );
            }
            if (
                usedHeight + height > capacity &&
                pages[pages.length - 1]?.length
            ) {
                pages.push([]);
                usedHeight = 0;
            }
            pages[pages.length - 1]?.push(row);
            usedHeight += height;
        }

        const htmlPages = pages
            .map((pageRows, index) => {
                const page = sheet.cloneNode(true) as HTMLElement;
                const body = page.querySelector('tbody.patient-rows');
                const pageNumber = page.querySelector('.page-number');
                if (!body || !pageNumber)
                    throw new Error('Neplatná štruktúra strany dokumentu.');
                body.replaceChildren(
                    ...pageRows.map((row) => row.cloneNode(true)),
                );
                pageNumber.textContent = `${index + 1} / ${pages.length}`;
                page.setAttribute('data-page', String(index + 1));
                if (!pageRows.length) {
                    page.querySelector('.patients-table')?.remove();
                }
                return page.outerHTML;
            })
            .join('');

        const html = `<!doctype html><html lang="sk"><head><meta charset="utf-8">
        <title>Zoznam pacientov</title><style>${documentStyles}</style></head><body>${htmlPages}</body></html>`;
        downloadUrl = URL.createObjectURL(
            new Blob([html], { type: 'text/html;charset=utf-8' }),
        );
        previewHtml.value = html;
    } finally {
        iframe.remove();
    }
}

onMounted(async () => {
    uiOverlayStore.setContentLoading(true);
    try {
        const raw = sessionStorage.getItem('patients-print-payload');
        if (!raw) throw new Error('Chýbajú dáta pre náhľad tlače.');
        const payload: PatientsPrintPayload = JSON.parse(raw);
        if (
            payload.mode === 'selected' &&
            Array.isArray(payload.selectedPatients)
        ) {
            patients.value = await fetchSelectedPatients(payload.selectedPatients);
        } else if (payload.mode === 'filtered' && payload.endpointUrl) {
            patients.value = await fetchAllFilteredPatients(
                payload.endpointUrl,
                payload.params ?? {},
            );
        } else {
            throw new Error('Neplatné dáta pre tlač.');
        }
        if (disposed) return;
        printedAt.value = new Date().toLocaleString('sk-SK');
        await preparePreview();
    } catch (error) {
        if (disposed) return;
        errorMessage.value =
            error instanceof Error
                ? error.message
                : 'Nepodarilo sa pripraviť náhľad tlače.';
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: errorMessage.value,
            life: 5000,
        });
    } finally {
        loading.value = false;
        if (!disposed) uiOverlayStore.setContentLoading(false);
    }
});

onBeforeUnmount(() => {
    disposed = true;
    uiOverlayStore.setContentLoading(false);
    if (downloadUrl) URL.revokeObjectURL(downloadUrl);
});
</script>

<template>
    <DocumentShell
        title="Zoznam pacientov"
        :previewUrl="previewHtml"
        :downloadOptions="downloadOptions"
        :showPrintButton="!!previewHtml && !loading"
        paginatedPreview
    >
        <div v-if="errorMessage" role="alert" class="text-sm text-danger">
            {{ errorMessage }}
        </div>
    </DocumentShell>

    <div
        v-if="loading"
        ref="measurementRef"
        class="measurement-root"
        aria-hidden="true"
        inert
    >
        <div class="print-page">
            <div class="page-inner">
                <div class="page-header">
                    <h1>ZOZNAM PACIENTOV</h1>
                    <table class="metadata">
                        <tbody>
                            <tr>
                                <td>
                                    Dátum tlače:<br /><strong>{{
                                        printedAt
                                    }}</strong>
                                </td>
                                <td>
                                    Počet pacientov:<br /><strong>{{
                                        patients.length
                                    }}</strong>
                                </td>
                                <td>
                                    Strana:<br /><strong class="page-number"
                                        >1 / 1</strong
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <table class="patients-table">
                    <colgroup>
                        <col style="width: 8%" />
                        <col style="width: 24%" />
                        <col style="width: 15%" />
                        <col style="width: 18%" />
                        <col style="width: 18%" />
                        <col style="width: 17%" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Meno a priezvisko</th>
                            <th>Rodné číslo</th>
                            <th>Sestra</th>
                            <th>Lekár</th>
                            <th>Prevádzka</th>
                        </tr>
                    </thead>
                    <tbody class="patient-rows">
                        <tr
                            v-for="(patient, index) in patients"
                            :key="patient.id"
                        >
                            <td>{{ index + 1 }}</td>
                            <td>
                                {{
                                    `${patient.first_name ?? ''} ${patient.last_name ?? ''}`.trim() ||
                                    '-'
                                }}
                            </td>
                            <td>{{ patient.personal_number ?? '-' }}</td>
                            <td>
                                {{
                                    patient.nurse
                                        ? formatUserFullName(patient.nurse)
                                        : '-'
                                }}
                            </td>
                            <td>{{ formatDoctor(patient.doctor) }}</td>
                            <td>
                                {{
                                    patient.branch
                                        ? formatBranchFullName(patient.branch)
                                        : '-'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!patients.length" class="empty">
                    Neboli vybrané žiadne záznamy.
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.measurement-root {
    display: none;
}
</style>
