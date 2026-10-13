import api from './api';
import { useAuthStore } from '@/stores/auth';

export interface LoginPayload {
    login?: string;
    pin?: string;
}

export async function login(payload: LoginPayload) {
    const res = await api.post('/auth/login', payload);
    const token = res.data?.data?.token

    const store = useAuthStore();
    if (!token) throw new Error('No token received');
    await store.setAuth(token);

    return res;
}

export async function logout() {
    const store = useAuthStore();
    await api.post('/auth/logout')
    store.clearAuth();
}

export function getToken() {
    const store = useAuthStore();
    return store.token;
}


export function isAuthenticated() {
    const store = useAuthStore();
    return store.isAuthenticated;
}

export default { login, logout, getToken, isAuthenticated };
