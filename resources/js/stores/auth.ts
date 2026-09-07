import router from '@/router';
import api from '@/services/api';
import { useAccessStore } from '@/stores/access';
import type { Branch, User } from '@/types/models';
import { defineStore } from 'pinia';

const BRANCH_STORAGE_KEY = 'current_branch_id';
const ROLE_STORAGE_KEY = 'current_role';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        storeStatus: 'ready' as 'ready' | 'initializing',
        token: (localStorage.getItem('api_token') as string | null) || null,
        user: null as null | User,
        currentRole: null as null | string,
        currentBranch: null as null | Branch,
        currentCompanyId: null as null | number,
    }),
    getters: {
        isAuthenticated: (state) => !!state.user,
        isManager: (state) => state.currentRole === 'manager',
        isSuperadmin: (state) => state.currentRole === 'superadmin',
    },
    actions: {
        async waitUntilInitialized() {
            if (this.storeStatus === 'ready') return;
            return new Promise<void>((resolve) => {
                const checkInterval = setInterval(() => {
                    if (this.storeStatus === 'ready') {
                        clearInterval(checkInterval);
                        resolve();
                    }
                }, 50);
            });
        },

        async init() {
            this.storeStatus = 'initializing';

            try {
                if (!this.token) {
                    this.user = null;
                    this.currentRole = null;
                    this.currentBranch = null;
                    useAccessStore().clear();
                    return;
                }

                this.user = await this.fetchUserProfile();
                const { initialBranch, initialRole } = this.computeInitialState();

                this.currentBranch = initialBranch;
                this.currentRole = initialRole;
                this.currentCompanyId = initialBranch ? initialBranch.company_id : null;

                // Resolve the application access state once per session; the backend stays
                // authoritative on every request regardless of what is cached here.
                await useAccessStore().load();

            } catch {
                this.clearAuth();
                this.user = null;
                this.currentRole = null;
                this.currentBranch = null;
                router.push({ name: 'login' });
            } finally {
                this.storeStatus = 'ready';
            }
        },

        getSavedState() {
            const savedBranchId = localStorage.getItem(BRANCH_STORAGE_KEY);
            const savedRole = localStorage.getItem(ROLE_STORAGE_KEY);
            return { savedBranchId, savedRole };
        },

        computeInitialState() {
            if (!this.user) throw new Error('User must be loaded to compute initial state');
            const { savedBranchId, savedRole } = this.getSavedState();


            let initialBranch: Branch | null = null;
            let initialRole: string | null = null;

            initialBranch = this.user.branches[0] ?? null;
            initialRole = initialBranch ?
                this.user.branch_roles?.find(br => br.branch_id === this.currentBranch?.id)?.position ?? 'nurse' :
                (this.user?.role?.position ?? null)

            if (savedRole === 'manager' && this.user?.role?.position === 'manager') {
                initialRole = 'manager';
                initialBranch = null;
            } else if (savedBranchId) {
                const branch = this.user?.branches.find(b => b.id === parseInt(savedBranchId));

                if (branch) {
                    initialBranch = branch;
                    initialRole = this.user.branch_roles?.find(br => br.branch_id === branch.id)?.position ?? 'nurse';
                }
            }


            return { initialBranch, initialRole };
        },



        async fetchUserProfile() {
            const user = await api.fetchEntity<User>('/auth/profile');
            return user;
        },

        async setAuth(token: string) {
            if (!token) return;
            this.token = token;
            localStorage.setItem('api_token', token);
            this.init();
        },

        setCurrentRole(role: string) {
            this.currentRole = role;
            localStorage.setItem(ROLE_STORAGE_KEY, role);
        },

        setCurrentCompanyId(companyId: number | null) {
            this.currentCompanyId = companyId;
        },

        setCurrentBranchById(branchId: number) {
            const branch = this.user?.branches.find(b => b.id === branchId);
            if (!branch) throw new Error('Branch not found');
            this.currentBranch = branch;
            localStorage.setItem(BRANCH_STORAGE_KEY, branchId.toString());

        },

        clearAuth() {
            this.token = null;
            this.user = null;
            this.currentRole = null;
            this.currentBranch = null;
            useAccessStore().clear();
            localStorage.removeItem('api_token');
        },

        clearCurrentBranch() {
            this.currentBranch = null;
            localStorage.removeItem(BRANCH_STORAGE_KEY);
        }
    },
});

export default useAuthStore;
