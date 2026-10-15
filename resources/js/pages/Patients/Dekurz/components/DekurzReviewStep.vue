<script setup lang="ts">
import { computed, ref } from 'vue'
import Dialog from 'primevue/dialog'
import type {
    DekurzDay,
    DekurzProcedure,
    DekurzScoringChanges,
    DekurzSupportingDocument,
} from '@/types/dekurz'

const props = defineProps<{
    days: DekurzDay[]
    procedures: DekurzProcedure[]
    documents: DekurzSupportingDocument[]
    scoringChanges: DekurzScoringChanges
    completed: boolean
    finalizing: boolean
    finalDocumentId: number | null
    finalizationErrors: string[]
}>()

const emit = defineEmits<{
    finalize: []
}>()
const confirmationVisible = ref(false)

const entries = computed(() => props.days.flatMap((day) => day.entries.map((entry) => ({ day, entry }))))
const expectedCount = computed(() => props.procedures.reduce((total, procedure) => total + procedure.scoring_rows_count, 0))
const filledCount = computed(() => entries.value.filter(({ entry }) => entry.text.trim()).length)
const missingDocuments = computed(() => props.documents.filter((document) => !document.document))

const errors = computed(() => {
    const issues: string[] = []
    if (props.days.some((day) => day.id === null)) {
        issues.push('Niektoré dni bodovania nie sú zahrnuté v koncepte.')
    }
    if (entries.value.length !== expectedCount.value) {
        issues.push(`Počet záznamov (${entries.value.length}) nesúhlasí s bodovaním (${expectedCount.value}).`)
    }
    for (const { day, entry } of entries.value) {
        if (!entry.id) issues.push(`${day.date}: výkon ${entry.procedure_code} nie je zahrnutý v koncepte.`)
        else if (!entry.text.trim()) issues.push(`${day.date}: výkon ${entry.procedure_code} nemá vyplnený text.`)
    }
    if (props.scoringChanges.has_changes) {
        issues.push(
            `Bodovanie sa od vytvorenia konceptu zmenilo: pridané ${props.scoringChanges.added}, odstránené ${props.scoringChanges.removed}, zmenené ${props.scoringChanges.changed}.`,
        )
    }
    return issues
})

const canComplete = computed(() => errors.value.length === 0)

function viewDocument() {
    if (props.finalDocumentId) window.open(`/documents/dekurz/${props.finalDocumentId}`, '_blank')
}

function confirmFinalization() {
    confirmationVisible.value = false
    emit('finalize')
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <section v-if="completed" class="rounded-md border border-success bg-success/5 p-6">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <div class="mb-2 flex items-center gap-2 font-medium text-success">
                        <i class="bi bi-file-earmark-check" />
                        Dekurz bol finalizovaný
                    </div>
                    <p class="text-normal text-darkgrey">
                        Historický dokument je uložený ako nemenný snapshot. Neskoršie zmeny bodovania ani šablón ho neovplyvnia.
                    </p>
                </div>
                <Button
                    v-if="finalDocumentId"
                    type="button"
                    icon="bi bi-box-arrow-up-right"
                    label="Zobraziť dokument"
                    class="shrink-0! border-success! bg-success!"
                    @click="viewDocument"
                />
            </div>
        </section>

        <template v-else>
        <section class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-md border border-tag3 bg-white p-4">
                <p class="text-mini text-lightgrey">Dni</p>
                <p class="mt-1 text-2xl font-semibold text-darkgrey">{{ days.length }}</p>
            </div>
            <div class="rounded-md border border-tag3 bg-white p-4">
                <p class="text-mini text-lightgrey">Denné záznamy</p>
                <p class="mt-1 text-2xl font-semibold text-darkgrey">{{ filledCount }} / {{ expectedCount }}</p>
            </div>
            <div class="rounded-md border border-tag3 bg-white p-4">
                <p class="text-mini text-lightgrey">Podporné dokumenty</p>
                <p class="mt-1 text-2xl font-semibold text-darkgrey">
                    {{ documents.length - missingDocuments.length }} / {{ documents.length }}
                </p>
            </div>
        </section>

        <section v-if="errors.length" class="rounded-md border border-danger bg-danger/5 p-4">
            <div class="mb-2 flex items-center gap-2 font-medium text-danger">
                <i class="bi bi-x-circle" />
                Pred dokončením opravte chyby
            </div>
            <ul class="space-y-1 text-normal text-darkgrey">
                <li v-for="issue in errors" :key="issue">{{ issue }}</li>
            </ul>
        </section>

        <section v-if="finalizationErrors.length" class="rounded-md border border-danger bg-danger/5 p-4">
            <div class="mb-2 flex items-center gap-2 font-medium text-danger">
                <i class="bi bi-x-circle" />
                Finalizácia neprešla serverovou kontrolou
            </div>
            <ul class="space-y-1 text-normal text-darkgrey">
                <li v-for="issue in finalizationErrors" :key="issue">{{ issue }}</li>
            </ul>
        </section>

        <section v-if="missingDocuments.length" class="rounded-md border border-warning bg-warning/5 p-4">
            <div class="mb-2 flex items-center gap-2 font-medium text-warning">
                <i class="bi bi-exclamation-triangle" />
                Upozornenia
            </div>
            <p class="text-normal text-darkgrey">
                Chýbajúce dokumenty:
                {{ missingDocuments.map((document) => document.label).join(', ') }}. Kontrolu môžete dokončiť aj bez nich.
            </p>
        </section>

        <section v-if="canComplete" class="rounded-md border border-success bg-success/5 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <p class="font-medium text-success">Dekurz je pripravený na finalizáciu</p>
                    <p class="text-mini text-darkgrey">Finalizáciou vznikne nemenný historický dokument a PDF.</p>
                </div>
                <Button
                    type="button"
                    icon="bi bi-file-earmark-check"
                    label="Finalizovať Dekurz"
                    :loading="finalizing"
                    class="shrink-0! border-success! bg-success!"
                    @click="confirmationVisible = true"
                />
            </div>
        </section>
        </template>
    </div>

    <Dialog v-model:visible="confirmationVisible" modal header="Finalizovať Dekurz" :style="{ width: 'min(520px, 94vw)' }">
        <p class="text-normal text-darkgrey">
            Po finalizácii už nebude možné meniť denné texty ani poznámky. Vytvorí sa historický dokument a PDF zo súčasného obsahu konceptu.
        </p>
        <template #footer>
            <Button type="button" label="Zrušiť" severity="secondary" text @click="confirmationVisible = false" />
            <Button
                type="button"
                icon="bi bi-file-earmark-check"
                label="Finalizovať"
                :loading="finalizing"
                class="border-success! bg-success!"
                @click="confirmFinalization"
            />
        </template>
    </Dialog>
</template>
