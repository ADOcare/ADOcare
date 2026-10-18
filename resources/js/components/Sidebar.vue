<script setup lang="ts">
import { computed, markRaw, ref, watch } from 'vue';
import { RouterLink, useRouter, type RouteRecordRaw } from 'vue-router';
import Popover from 'primevue/popover';
import appRouter from '@/router';
import FormField from '@/components/FormField.vue';
import ChangePasswordModal from '@/pages/Settings/Users/ChangePasswordModal.vue';
import PersonalDataModal from '@/pages/Settings/Users/PersonalDataModal.vue';
import { openModal } from '@/composables/useModal';
import { capitalize } from '@/utils/formatUtils';
import useAuthStore from '@/stores/auth';
import type { User } from '@/types/models';

const auth = useAuthStore();
const router = useRouter();
const companyPopover = ref<InstanceType<typeof Popover> | null>(null);

const user = computed<User | null>(() => auth.user as User | null);
const company = computed(() => user.value?.company ?? null);
const fullName = computed(() => user.value
    ? `${user.value.title ?? ''} ${user.value.first_name ?? ''} ${user.value.last_name ?? ''}`.trim()
    : '');
const currentRoleLabel = computed(() => {
    if (auth.isSuperadmin) return 'Superadministrátor';
    return auth.isManager ? 'Manažér' : 'Zdravotná sestra';
});
const companyAddress = computed(() => {
    if (!company.value) return '';

    return [
        company.value.address,
        [company.value.psc, company.value.city].filter(Boolean).join(' '),
    ].filter(Boolean).join(', ');
});

function toggleCompanyPopover(event: Event) {
    companyPopover.value?.toggle(event);
}

async function personalData() {
    if (!user.value) return;

    const updatedUser = await openModal(
        markRaw(PersonalDataModal),
        { user: user.value },
        {
            header: 'Osobné údaje',
            style: { width: '640px', maxWidth: 'calc(100vw - 2rem)' },
        },
    ) as User | null;

    if (updatedUser) {
        auth.user = updatedUser;
    }
}

async function changePassword() {
    if (!user.value) return;

    await openModal(
        markRaw(ChangePasswordModal),
        { userId: user.value.id },
        { header: 'Zmeniť heslo', style: { width: '500px' } },
    );
}

async function logout() {
    auth.clearAuth();
    await router.replace({ name: 'login' });
}

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
    <aside class="flex h-full w-64 flex-col bg-darkgrey! p-4 py-2 text-tag2! border-y border-l border-tag2!">
        <div class="flex-1 space-y-4 overflow-y-auto">
            <RouterLink v-if="auth.currentRole === 'nurse'" :to="{ name: 'patients' }"
                class="w-full flex items-center justify-between px-3 py-1 rounded-md bg-tag2 text-white! hover:bg-tag2 hover:!text-white text-normal">
                Pacienti
                <i class="bi bi-chevron-right"></i>
            </RouterLink>

            <RouterLink v-if="auth.currentRole === 'nurse'" to="/data/monthly-export"
                class="w-full flex items-center justify-between px-3 py-1 rounded-md bg-tag2 text-white! hover:bg-tag2 hover:!text-white text-normal">
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
        </div>

        <div class="mt-4 flex flex-col gap-4">
            <!-- Company -->
            <button
                v-if="company"
                type="button"
                class="w-full flex items-center justify-between px-3 py-1 rounded-md bg-tag2 text-white hover:bg-tag2 cursor-pointer text-normal"
                @click="toggleCompanyPopover"
            >
                <span class="truncate">
                    {{ company.name }}
                </span>

                <i class="bi bi-chevron-right"></i>
            </button>

            <!-- Nurse -->
            <div v-if="user">
                <button
                    type="button"
                    class="w-full flex items-center justify-between px-3 py-1 rounded-md bg-tag2 text-white cursor-pointer text-normal"
                    @click="toggle('user-menu')"
                >
                    <span class="truncate">
                        {{ fullName }}
                    </span>

                    <i
                        :class="
                            isOpen('user-menu')
                                ? 'bi bi-chevron-up'
                                : 'bi bi-chevron-down'
                        "
                    ></i>
                </button>

                <ul
                    v-show="isOpen('user-menu')"
                    class="mt-1 space-y-1 text-mini"
                >
                    <li>
                        <button
                            type="button"
                            class="block w-full px-3 py-1 rounded-md text-left text-white cursor-pointer hover:bg-tag2"
                            @click="personalData"
                        >
                            Osobné údaje
                        </button>
                    </li>

                    <li>
                        <button
                            type="button"
                            class="block w-full px-3 py-1 rounded-md text-left text-white cursor-pointer hover:bg-tag2"
                            @click="changePassword"
                        >
                            Zmeniť heslo
                        </button>
                    </li>

                    <li>
                        <button
                            type="button"
                            class="block w-full px-3 py-1 rounded-md text-left text-white cursor-pointer hover:bg-tag2"
                            @click="logout"
                        >
                            Odhlásiť sa
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </aside>

    <Popover ref="userPopover">
        <div v-if="user" class="flex w-80 flex-col gap-10">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-tag2 text-white">
                    <i class="bi bi-person-fill text-lg" />
                </div>
                <div class="min-w-0">
                    <div class="truncate text-normal font-medium text-tag2">{{ fullName }}</div>
                    <div class="text-mini text-accent">{{ currentRoleLabel }}</div>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <FormField v-if="user.email" label="E-mail" :model-value="user.email" disabled />
                <FormField v-if="user.phone_number" label="Telefón" :model-value="user.phone_number" disabled />
                <FormField v-if="user.code" label="Kód pracovníka" :model-value="user.code" disabled />
            </div>
        </div>
    </Popover>

    <Popover ref="companyPopover">
        <div v-if="company" class="flex w-96 flex-col gap-10">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-tag2 text-white">
                    <i class="bi bi-building text-lg" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-normal font-medium text-tag2">{{ company.name }}</div>
                    <div class="text-mini text-accent">Poskytovateľ zdravotnej starostlivosti</div>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <FormField v-if="company.ico" label="IČO" :model-value="company.ico" disabled />
                <FormField v-if="company.dic" label="DIČ" :model-value="company.dic" disabled />
                <FormField v-if="company.ic_dph" label="IČ DPH" :model-value="company.ic_dph" disabled />
                <FormField v-if="companyAddress" label="Sídlo" :model-value="companyAddress" disabled class="col-span-3" />
                <FormField v-if="company.email" label="E-mail" :model-value="company.email" disabled class="col-span-3" />
                <FormField v-if="company.phone" label="Telefón" :model-value="company.phone" disabled class="col-span-3" />
            </div>
        </div>
    </Popover>
</template>
