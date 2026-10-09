<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RouterLink, type RouteRecordRaw } from 'vue-router';
import appRouter from '@/router';
import { capitalize } from '@/utils/formatUtils';
import useAuthStore from '@/stores/auth';

const auth = useAuthStore();

interface SidebarChild {
    key: string;
    label: string;
    path: string;
}

interface SidebarItem {
    key: string;
    label: string;
    path: string;
    children?: SidebarChild[];
}

function buildSidebarItems(routes: readonly RouteRecordRaw[]): SidebarItem[] {
    const items: SidebarItem[] = [];


    for (const r of routes) {
        const meta = r.meta ?? {};
        if (typeof meta?.sidebar === 'function' && !meta.sidebar())
            continue;
        else if (!meta.sidebar) continue;

        const children: SidebarChild[] = (r.children ?? [])
            .filter((c) => {
                // If is a function, call it
                if (typeof c.meta?.sidebar === 'function')
                    return c.meta.sidebar();

                return c.meta?.sidebar
            })
            .map((c) => {
                const childMeta = c.meta ?? {};

                const rawLabel: string =
                    childMeta.link as string ??
                    childMeta.title as string ??
                    String(c.name ?? c.path);

                const label = capitalize(rawLabel);

                const fullPath = c.path.startsWith('/')
                    ? c.path
                    : `${r.path.replace(/\/$/, '')}/${c.path}`;

                return {
                    key: String(c.name ?? c.path),
                    label,
                    path: fullPath,
                };
            });

        const rawLabel =
            meta.title as string ??
            meta.link as string ??
            String(r.name ?? r.path);

        const label = capitalize(rawLabel);

        const item: SidebarItem = {
            key: String(meta.sectionRoot ?? r.name ?? r.path),
            label,
            path: r.path,
            children: children.length ? children : undefined,
        };

        items.push(item);
    }

    return items;
}


// use the same hierarchy you defined in router/index.ts
const rawRoutes = appRouter.options.routes;
// const sidebarItems = ref<SidebarItem[]>(buildSidebarItems(rawRoutes));
const sidebarItems = computed(() => buildSidebarItems(rawRoutes));

// open/close state per section (dose/settings/etc.), persisted
type OpenState = Record<string, boolean>;

const openState = ref<OpenState>(
    JSON.parse(localStorage.getItem('sidebar.openState') ?? '{}'),
);

function isOpen(key: string): boolean {
    return !!openState.value[key];
}

function toggle(key: string) {
    openState.value[key] = !isOpen(key);
}

watch(
    openState,
    (val) => {
        localStorage.setItem('sidebar.openState', JSON.stringify(val));
    },
    { deep: true },
);

</script>

<template>
    <aside class="flex flex-col w-64 h-full bg-darkgrey! border-0! text-darkgrey! p-4 space-y-4 overflow-y-auto">
        <RouterLink v-if="auth.currentRole === 'nurse'" to="/overview/patients"
            class="w-full flex items-center justify-between px-3 py-1 rounded-md bg-white text-tag2! hover:bg-tag2 hover:!text-white text-normal">
            Pacienti
            <i class="bi bi-chevron-right"></i>
        </RouterLink>

        <RouterLink v-if="auth.currentRole === 'nurse'" to="/data/monthly-export"
            class="w-full flex items-center justify-between px-3 py-1 rounded-md bg-white text-tag2! hover:bg-tag2 hover:!text-white text-normal">
            Mesačná uzávierka
            <i class="bi bi-chevron-right"></i>
        </RouterLink>

        <template v-for="item in sidebarItems" :key="item.key">
            <div>
                <button
                    class="w-full flex items-center justify-between px-3 py-1 rounded-md bg-tag2 text-white! hover:bg-tag2 cursor-pointer text-normal"
                    @click="toggle(item.key)">
                    <span>{{ item.label }}</span>
                    <i :class="isOpen(item.key) ? 'bi bi-chevron-up' : 'bi bi-chevron-down'"></i>
                </button>

                <ul v-show="isOpen(item.key)" class="mt-1 space-y-1 text-mini">
                    <li v-for="child in item.children" :key="child.key">
                        <RouterLink :to="child.path" class="block px-3 py-1 rounded-md text-white hover:bg-tag2">
                            {{ child.label }}
                        </RouterLink>
                    </li>
                </ul>
            </div>
        </template>
    </aside>
</template>
