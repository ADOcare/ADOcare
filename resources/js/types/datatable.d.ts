// Types for UniversalDataTable

import type { useRemoteTable } from "@/composables/useRemoteTable";
import type { Ref } from "vue";

// loaders or plain components.
type VueComponent = Component | (() => Promise<Component>);
type ValueFormatter<T = any> = (value: any, row: T) => string | number | null;


export type RemoteTableReturn = ReturnType<typeof useRemoteTable>;
const refUseRemoteTable = ref<RemoteTableReturn>(useRemoteTable('')) // dummy value to extract type; not for actual use
export type RemoteTableReturnDereferenced = typeof refUseRemoteTable.value

interface ColumnDef<T = any> {
    field?: keyof T | string;
    header?: string;
    sortable?: boolean;
    filterable?: boolean;
    width?: string;
    style?: string;
    align?: 'left' | 'center' | 'right';
    component?: VueComponent;
    componentOptions?: Record<string, any>;
    slot?: string;
    render?: ValueFormatter<T>;
    exportable?: boolean;

}

interface ActionDef<T = any> {
    key: string;
    label?: string;
    key: string;
    label?: string;
    position?: 'start' | 'end';
    bordered?: boolean;
    adonis?: boolean;
    class?: string | Ref<string> | ((params: { rows: T[]; selectedRows: T[]; remote: RemoteTableReturn }) => string);
    tooltip?: string | Ref<string> | ((params: { rows: T[]; selectedRows: T[]; remote: RemoteTableReturn }) => string);
    disabled?: boolean | ((params: { rows: T[]; selectedRows: T[]; remote: RemoteTableReturn }) => boolean);
    icon?: string | Ref<string> | ((params: { rows: T[]; selectedRows: T[]; remote: RemoteTableReturn }) => string);
    confirm?: string | boolean;
    handler?: (params: { rows: T[]; selectedRows: T[]; remote: RemoteTableReturn }) => Promise<void> | void;
}

interface DateRangeFilterDef {
    mode?: 'range' | 'single';
    param?: string;
    startParam?: string;
    endParam?: string;
    placeholder?: string;
    startPlaceholder?: string;
    endPlaceholder?: string;
    dateFormat?: string;
    manualInput?: boolean;
    view?: 'date' | 'month' | 'year';
    value?: string | Date | null;
    startValue?: string | Date | null;
    endValue?: string | Date | null;
}

interface DataTableOptions<T = any> {
    endpointUrl: string;
    columns: ColumnDef<T>[];
    localItems?: T[];
    initialSelectedKeys?: Array<string | number>;
    resetPageOnLocalItemsChange?: boolean;
    afterInit?: (args: { remote: RemoteTableReturn }) => void;
    rowKey?: string;
    selectable?: boolean;
    actions?: ActionDef<T>[];
    defaultPageSize?: number;
    pageSizeOptions?: number[];
    sortBy?: string;
    hideSearch?: boolean;
    hidePaginator?: boolean;
    // extra params appended to each request (filters, fixed scope)
    extraParams?: Record<string, any>;
    dateRangeFilter?: DateRangeFilterDef;
}

interface RemoteLoadResult<T = any> {
    items: T[];
    total: number;
}
