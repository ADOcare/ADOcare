<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'

import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import Dialog from 'primevue/dialog'

import Toolbar from 'primevue/toolbar'
import Paginator from 'primevue/paginator'
import Select from 'primevue/select'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import DataTableToolbarActions from '@/components/datatable/DataTableToolbarActions.vue'

import { useRemoteTable } from '@/composables/useRemoteTable'
import type { ActionDef, DataTableOptions } from '@/types/datatable'
import { parseDateInput, toApiDate, toApiMonth } from '@/utils/dateUtils'

type IBaseModel = any

const props = defineProps<{
    options: DataTableOptions<IBaseModel>
    searchDebounce?: number
}>()

const emits = defineEmits<{
    (e: 'row-selected', row: IBaseModel | IBaseModel[]): void
    (e: 'action', actionKey: string, payload: any): void
}>()

const opt = computed(() => props.options)
const rowKey = opt.value.rowKey ?? 'id'

const remote = useRemoteTable<IBaseModel>(opt.value.endpointUrl, {
    defaultPageSize: opt.value.defaultPageSize,
    extraParams: opt.value.extraParams,
})

const columns = computed(() => opt.value.columns ?? [])
const isLocalMode = computed(() => Array.isArray(opt.value.localItems))
const dateRangeFilter = computed(() => opt.value.dateRangeFilter)
const filterMode = computed(() => dateRangeFilter.value?.mode ?? 'range')
const isSingleDateFilter = computed(() => filterMode.value === 'single')
const startActions = computed(() => (opt.value.actions ?? []).filter((a) => a.position === 'start'))
const endActions = computed(() => (opt.value.actions ?? []).filter((a) => !a.position || a.position === 'end'))

const selectedRows = ref<IBaseModel[]>([])
const selectedRowsMap = ref(new Map<string | number, IBaseModel>())

const dateFilterValue = ref<Date | null>(null)
const dateRangeStart = ref<Date | null>(null)
const dateRangeEnd = ref<Date | null>(null)

let searchTimer: number | null = null
let initialRemoteLoadDone = false
let syncingVisibleSelection = false

const confirmVisible = ref(false)
const pendingAction = ref<ActionDef | null>(null)

const selectedCount = computed(() => selectedRowsMap.value.size)

function getRowId(row: IBaseModel): string | number | undefined {
    if (!row || !rowKey) return undefined
    return row[rowKey]
}

function getCurrentItems(): IBaseModel[] {
    const items = remote.items.value
    return Array.isArray(items) ? items : []
}

function getAllSelectedRows(): IBaseModel[] {
    return Array.from(selectedRowsMap.value.values())
}

function syncVisibleSelectionFromGlobal() {
    const currentItems = getCurrentItems()

    syncingVisibleSelection = true
    selectedRows.value = currentItems.filter((row: IBaseModel) => {
        const id = getRowId(row)
        return id !== undefined && selectedRowsMap.value.has(id)
    })

    queueMicrotask(() => {
        syncingVisibleSelection = false
    })
}

function updateGlobalSelectionFromVisible(newVisibleSelection: IBaseModel[]) {
    const currentItems = getCurrentItems()
    const currentPageIds = new Set<string | number>()

    currentItems.forEach((row: IBaseModel) => {
        const id = getRowId(row)
        if (id !== undefined) {
            currentPageIds.add(id)
        }
    })

    currentPageIds.forEach((id) => {
        selectedRowsMap.value.delete(id)
    })

    newVisibleSelection.forEach((row: IBaseModel) => {
        const id = getRowId(row)
        if (id !== undefined) {
            selectedRowsMap.value.set(id, row)
        }
    })
}

function clearSelection() {
    syncingVisibleSelection = true
    selectedRows.value = []
    selectedRowsMap.value.clear()

    queueMicrotask(() => {
        syncingVisibleSelection = false
    })
}

function applyInitialSelection() {
    const keys = new Set(opt.value.initialSelectedKeys ?? [])

    if (keys.size === 0) return

    const source = isLocalMode.value
        ? (opt.value.localItems ?? [])
        : getCurrentItems()

    selectedRowsMap.value.clear()

    source.forEach((row: IBaseModel) => {
        const id = getRowId(row)

        if (id !== undefined && keys.has(id)) {
            selectedRowsMap.value.set(id, row)
        }
    })

    syncVisibleSelectionFromGlobal()
    emits('row-selected', getAllSelectedRows())
}

function compareLocalValues(a: any, b: any) {
    if (a === b) return 0
    if (a === null || a === undefined) return -1
    if (b === null || b === undefined) return 1

    // Handle Date values explicitly for stable sorting in local mode.
    if (a instanceof Date || b instanceof Date) {
        const at = a instanceof Date ? a.getTime() : new Date(a).getTime()
        const bt = b instanceof Date ? b.getTime() : new Date(b).getTime()
        if (Number.isNaN(at) || Number.isNaN(bt)) {
            return String(a).localeCompare(String(b), 'sk')
        }
        return at - bt
    }

    if (typeof a === 'number' && typeof b === 'number') {
        return a - b
    }

    return String(a).localeCompare(String(b), 'sk', { numeric: true, sensitivity: 'base' })
}

function applyLocalRows() {
    if (!isLocalMode.value) return

    const source = [...(opt.value.localItems ?? [])]
    const query = (remote.q.value ?? '').trim().toLowerCase()
    const sortValue = (remote.sort.value ?? '').trim()
    const shouldPaginate = !(opt.value.hidePaginator ?? false)

    let rows = source

    if (query) {
        rows = rows.filter((row) => JSON.stringify(row)?.toLowerCase().includes(query))
    }

    if (sortValue) {
        const isDesc = sortValue.startsWith('-')
        const field = isDesc ? sortValue.slice(1) : sortValue

        rows = [...rows].sort((left: any, right: any) => {
            const result = compareLocalValues(left?.[field], right?.[field])
            return isDesc ? -result : result
        })
    }

    remote.total.value = rows.length

    if (!shouldPaginate) {
        remote.items.value = rows
        return
    }

    const currentPage = Math.max(1, Number(remote.page.value || 1))
    const pageSize = Math.max(1, Number(remote.per_page.value || 10))
    const start = (currentPage - 1) * pageSize
    remote.items.value = rows.slice(start, start + pageSize)
}

function getConfirmText(action: ActionDef): string {
    if (!action.confirm) return ''
    if (typeof action.confirm === 'string') return action.confirm
    return 'Naozaj si prajete vykonať túto akciu?'
}

async function runAction(action: ActionDef) {
    const rows = getCurrentItems()
    const allSelectedRows = getAllSelectedRows()

    if (action.handler) {
        const result = await action.handler({
            rows,
            selectedRows: allSelectedRows,
            remote,
        })
        if (action.key === 'delete') {
            clearSelection()
        }
        return result
    }

    emits('action', action.key, { rows, selectedRows: allSelectedRows, remote })

    if (action.key === 'delete') {
        clearSelection()
    }
}

function onAction(action: ActionDef) {
    if (action.confirm) {
        pendingAction.value = action
        confirmVisible.value = true
        return
    }

    return runAction(action)
}

function confirmNo() {
    confirmVisible.value = false
    pendingAction.value = null
}

async function confirmYes() {
    const action = pendingAction.value
    confirmVisible.value = false
    pendingAction.value = null

    if (!action) return
    await runAction(action)
}

function onSort(e: any) {
    remote.setSort(
        e.sortField ? (e.sortOrder === -1 ? '-' + e.sortField : e.sortField) : undefined,
    )

    if (isLocalMode.value) {
        remote.page.value = 1
        applyLocalRows()
        syncVisibleSelectionFromGlobal()
        return
    }

    remote.loadPage(1)
}

function onPage(e: any) {
    if (isLocalMode.value) {
        remote.page.value = e.page + 1
        applyLocalRows()
        syncVisibleSelectionFromGlobal()
        return
    }

    remote.loadPage(e.page + 1)
}

function formatFilterValue(date: Date | null) {
    return dateRangeFilter.value?.view === 'month' ? toApiMonth(date) : toApiDate(date)
}

function syncDateRangeParams() {
    if (isLocalMode.value) return

    if (isSingleDateFilter.value) {
        const param = dateRangeFilter.value?.param ?? 'period'
        remote.setExtraParam(param, formatFilterValue(dateFilterValue.value))
        return
    }

    const startParam = dateRangeFilter.value?.startParam ?? 'date_from'
    const endParam = dateRangeFilter.value?.endParam ?? 'date_to'

    remote.setExtraParam(startParam, formatFilterValue(dateRangeStart.value))
    remote.setExtraParam(endParam, formatFilterValue(dateRangeEnd.value))
}

watch(
    () => [
        dateRangeFilter.value?.mode ?? 'range',
        dateRangeFilter.value?.param ?? 'period',
        dateRangeFilter.value?.value ?? null,
        dateRangeFilter.value?.startValue ?? null,
        dateRangeFilter.value?.endValue ?? null,
        dateRangeFilter.value?.startParam ?? 'date_from',
        dateRangeFilter.value?.endParam ?? 'date_to',
    ],
    ([, , value, startValue, endValue]) => {
        if (!dateRangeFilter.value) return

        dateFilterValue.value = parseDateInput(value)
        dateRangeStart.value = parseDateInput(startValue)
        dateRangeEnd.value = parseDateInput(endValue)
        syncDateRangeParams()
    },
    { immediate: true },
)

watch(dateFilterValue, () => {
    if (!dateRangeFilter.value || !isSingleDateFilter.value) return

    syncDateRangeParams()

    if (!initialRemoteLoadDone) return
    clearSelection()
    remote.loadPage(1)
})

watch([dateRangeStart, dateRangeEnd], () => {
    if (!dateRangeFilter.value || isSingleDateFilter.value) return

    syncDateRangeParams()

    if (!initialRemoteLoadDone) return
    clearSelection()
    remote.loadPage(1)
})

onMounted(async () => {
    syncDateRangeParams()
    if (!isLocalMode.value && opt.value.sortBy) {
        remote.setSort(opt.value.sortBy)
    }
    if (isLocalMode.value) {
        applyLocalRows()
    } else {
        await remote.loadPage(1)
    }
    applyInitialSelection()
    syncVisibleSelectionFromGlobal()
    initialRemoteLoadDone = true
    opt.value.afterInit?.({ remote })
})

watch(
    () => opt.value.localItems,
    () => {
        if (!isLocalMode.value) return

        if (opt.value.resetPageOnLocalItemsChange) {
            remote.page.value = 1
        }

        applyLocalRows()
        syncVisibleSelectionFromGlobal()
    },
    { immediate: true, deep: true },
)

watch(
    () => remote.q.value,
    () => {
        if (isLocalMode.value) {
            remote.page.value = 1
            applyLocalRows()
            syncVisibleSelectionFromGlobal()
            return
        }

        if (searchTimer) window.clearTimeout(searchTimer)
        searchTimer = window.setTimeout(() => {
            clearSelection()
            remote.loadPage(1)
        }, props.searchDebounce ?? 300)
    },
)

watch(
    () => remote.per_page.value,
    () => {
        if (isLocalMode.value) {
            remote.page.value = 1
            applyLocalRows()
            return
        }
        remote.loadPage(1)
    },
)

watch(
    () => remote.items.value,
    () => {
        syncVisibleSelectionFromGlobal()
    },
    { deep: true },
)

watch(
    selectedRows,
    (val) => {
        if (syncingVisibleSelection) return
        updateGlobalSelectionFromVisible(val)
        emits('row-selected', getAllSelectedRows())
    },
    { deep: true },
)
</script>

<template>
    <div class="h-full flex flex-col min-h-0 max-h-full overflow-auto">
        <Toolbar class="bg-transparent! border-0! p-0! py-3! shadow-none! flex items-center justify-between">
            <template #start>
                <div class="flex items-center gap-2 flex-wrap">
                    <DataTableToolbarActions
                        :actions="startActions"
                        :rows="remote.items.value"
                        :selectedRows="getAllSelectedRows()"
                        :remote="remote"
                        @action="onAction"
                    />
                </div>
            </template>

            <template #end>
                <div class="flex items-center gap-2 flex-wrap justify-end">
                    <div
                        v-if="selectedCount > 0"
                        class="text-normal px-2 py-1 rounded-md bg-tag3 text-accent flex items-center gap-4"
                    >
                        Vybrané: {{ selectedCount }}

                        <button
                            type="button"
                            class="inline-flex items-center justify-center text-accent hover:text-darkgrey"
                            @click="clearSelection"
                            title="Zrušiť výber"
                            aria-label="Zrušiť výber"
                        >
                            <i class="bi bi-x-lg" />
                        </button>
                    </div>

                    

                    <IconField v-if="!opt.hideSearch">
                        <InputText v-model="remote.q.value" class="w-64" />
                        <InputIcon>
                            <i class="bi bi-search text-darkgrey" />
                        </InputIcon>
                    </IconField>

                    <template v-if="!isLocalMode && dateRangeFilter && isSingleDateFilter">
                        <IconField>
                            <DatePicker
                                v-model="dateFilterValue"
                                :placeholder="dateRangeFilter.placeholder"
                                :view="dateRangeFilter.view ?? 'month'"
                                :dateFormat="dateRangeFilter.dateFormat ?? 'mm/yy'"
                                :manualInput="dateRangeFilter.manualInput ?? false"
                                inputClass="w-full! pr-10!"
                                class="w-50"
                            />
                            <InputIcon>
                                <i class="bi bi-filter text-darkgrey" />
                            </InputIcon>
                        </IconField>
                    </template>

                    <template v-else-if="!isLocalMode && dateRangeFilter">
                        <IconField>
                            <DatePicker
                                v-model="dateRangeStart"
                                :placeholder="dateRangeFilter.startPlaceholder ?? 'Od dátumu'"
                                :view="dateRangeFilter.view ?? 'date'"
                                :dateFormat="dateRangeFilter.dateFormat ?? 'dd.mm.yy'"
                                :manualInput="dateRangeFilter.manualInput ?? false"
                                inputClass="w-full! pr-10!"
                                class="w-50"
                            />
                            <InputIcon>
                                <i class="bi bi-calendar-frame text-darkgrey" />
                            </InputIcon>
                        </IconField>

                        <IconField>
                            <DatePicker
                                v-model="dateRangeEnd"
                                :placeholder="dateRangeFilter.endPlaceholder ?? 'Do dátumu'"
                                :view="dateRangeFilter.view ?? 'date'"
                                :dateFormat="dateRangeFilter.dateFormat ?? 'dd.mm.yy'"
                                :manualInput="dateRangeFilter.manualInput ?? false"
                                inputClass="w-full! pr-10!"
                                class="w-50"
                            />
                            <InputIcon>
                                <i class="bi bi-calendar-frame text-darkgrey" />
                            </InputIcon>
                        </IconField>
                    </template>

                    <slot
                        name="toolbar-extra"
                        :remote="remote"
                        :rows="remote.items.value"
                        :selectedRows="getAllSelectedRows()"
                    />

                    <DataTableToolbarActions
                        :actions="endActions"
                        :rows="remote.items.value"
                        :selectedRows="getAllSelectedRows()"
                        :remote="remote"
                        @action="onAction"
                    />
                </div>
            </template>
        </Toolbar>

        <Dialog
            v-model:visible="confirmVisible"
            :style="{ width: '600px' }"
            :modal="true"
            :closable="false"
            header="Upozornenie"
        >
            <div class="flex items-center justify-between w-full">
                <span class="text-heading">
                    {{ pendingAction ? getConfirmText(pendingAction) : '' }}
                </span>

                <div class="flex items-center gap-2">
                    <Button
                        label="Nie"
                        text
                        @click="confirmNo"
                        class="bg-accent! px-4! text-white! hover:bg-darkgrey! border-0!"
                    />
                    <Button
                        label="Áno"
                        text
                        @click="confirmYes"
                        class="bg-danger! px-4! text-white!"
                    />
                </div>
            </div>
        </Dialog>

        <DataTable
            ref="dt"
            :value="remote.items.value"
            :paginator="!(opt.hidePaginator ?? false)"
            :first="(remote.page.value - 1) * remote.per_page.value"
            :rows="remote.per_page.value"
            :totalRecords="remote.total.value"
            :lazy="true"
            :loading="remote.loading.value"
            :dataKey="rowKey"
            v-on:page="(e) => onPage(e)"
            v-on:sort="(e) => onSort(e)"
            v-model:selection="selectedRows"
            :selectionMode="opt.selectable ? 'multiple' : undefined"
            class="h-full min-h-0 max-h-full overflow-auto"
            scrollable
            scrollHeight="flex"
            :rowsPerPageOptions="opt.pageSizeOptions ?? [10, 25, 50]"
        >
            <template #paginatorcontainer="state">
                <div class="flex items-center justify-between w-full px-2">
                    <div class="flex items-center gap-2 flex-1 text-xs text-white">
                        <span>Zobraziť</span>
                        <Select
                            class="text-xs! p-0 h-auto!"
                            labelClass="text-xs!"
                            v-model="remote.per_page.value"
                            :options="opt.pageSizeOptions ?? [10, 25, 50]"
                        />
                        <span>záznamov na stranu</span>
                    </div>

                    <div class="flex-1">
                        <Paginator
                            v-bind="state"
                            paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown"
                            v-on:page="(e) => onPage(e)"
                        />
                    </div>

                    <div class="flex-1 text-right text-xs text-white">
                        Výsledky {{ state.first }} - {{ state.last }} z celkových
                        <b>{{ state.totalRecords }}</b> záznamov
                    </div>
                </div>
            </template>

            <Column
                v-if="opt.selectable"
                :selectionMode="opt.selectable ? 'multiple' : undefined"
                style="width: 3rem"
            />

            <Column
                v-for="col in columns"
                :key="col.field ?? col.header"
                :field="col.field ? String(col.field) : undefined"
                :header="col.header"
                :sortable="col.sortable"
                :style="`width: ${(col.width ?? 'auto')}; ${col.style}`"
                :data-style="col"
            >
                <template #body="{ data }">
                    <slot
                        :name="col.slot ?? 'col-' + String(col.field)"
                        :row="data"
                        :value="col.field ? data[col.field] : '-'"
                    >
                        <component
                            v-if="col.component"
                            :is="col.component"
                            :value="col.field ? data[col.field] : null"
                            :row="data"
                            :customOptions="col?.componentOptions ?? {}"
                        />
                        <span
                            v-else-if="col.render"
                            v-html="col.render(col.field ? data[col.field] : null, data)"
                        >
                        </span>
                        <span v-else>
                            {{ col.field ? data[col.field] : '-' }}
                        </span>
                    </slot>
                </template>
            </Column>
        </DataTable>
    </div>
</template>

<style scoped lang="scss">
.text-muted {
    color: #6b7280;
}
</style>
