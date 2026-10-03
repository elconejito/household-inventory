<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useQueryClient } from '@tanstack/vue-query';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { acceptInvitation } from '../api/household';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { useSessionStore, type SessionUser } from '../stores/session';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const queryClient = useQueryClient();
const token = ref(new URLSearchParams(route.hash.slice(1)).get('token') ?? '');
const name = ref('');
const email = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const errors = ref<FormErrors>({ fields: {}, form: '' });
const isSubmitting = ref(false);
const sessionChecked = ref(false);
const isAuthenticated = computed(() => session.status === 'authenticated' && Boolean(session.user));

async function checkSession(): Promise<void> {
    errors.value = { fields: {}, form: '' };
    sessionChecked.value = false;
    try {
        await session.ensureLoaded();
        sessionChecked.value = true;
    } catch {
        errors.value = { fields: {}, form: 'Your session could not be checked. Try again before accepting this invitation.' };
    }
}

onMounted(async () => {
    if (token.value && typeof window !== 'undefined') {
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${window.location.search}`);
    }
    await checkSession();
});

async function signInWithInvitation(): Promise<void> {
    if (!token.value) return;
    if (session.user) {
        try {
            await session.logout();
        } catch {
            errors.value = { fields: {}, form: 'Sign out before accepting an invitation for another account.' };
            return;
        }
    }
    await router.push({ name: 'login', query: { redirect: '/invitations/accept' }, hash: `#token=${encodeURIComponent(token.value)}` });
}

async function signOutWithoutInvitation(): Promise<void> {
    try {
        await session.logout();
        await router.replace({ name: 'login' });
    } catch {
        errors.value = { fields: {}, form: 'You could not be signed out. Please try again.' };
    }
}

async function submit(): Promise<void> {
    if (!token.value || !sessionChecked.value || isSubmitting.value) return;
    errors.value = { fields: {}, form: '' };
    isSubmitting.value = true;
    try {
        const payload = isAuthenticated.value
            ? { token: token.value }
            : { token: token.value, email: email.value, name: name.value, password: password.value, password_confirmation: passwordConfirmation.value };
        const accepted = await acceptInvitation(payload);
        try {
            await session.refresh();
        } catch {
            session.setAuthenticatedUser(accepted as SessionUser);
        }
        await queryClient.clear();
        await router.replace({ name: 'dashboard' });
    } catch (error) {
        errors.value = parseApiErrors(error);
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <main class="grid min-h-screen place-items-center bg-canvas px-5 py-10 sm:px-8">
        <section class="w-full max-w-lg rounded-panel border border-line bg-white p-6 shadow-card sm:p-9" aria-labelledby="accept-invitation-title">
            <RouterLink to="/login" class="text-sm font-medium text-sage-dark hover:underline">Household Inventory</RouterLink>
            <p class="eyebrow mt-7">You’re invited</p>
            <h1 id="accept-invitation-title" class="mt-2 text-2xl font-semibold tracking-tight text-ink">Join a household</h1>
            <p class="mt-2 text-sm leading-6 text-ink-muted">Accept this invitation to share a household inventory.</p>

            <p v-if="errors.form" class="mt-5 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ errors.form }}</p>
            <div v-if="!token && isAuthenticated && !session.user?.membership?.household?.id" class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <p>You don’t have an active household membership. Ask the household owner to restore your archived membership before rejoining.</p>
                <button type="button" class="mt-3 font-semibold underline" @click="signOutWithoutInvitation">Sign out</button>
            </div>
            <div v-else-if="!token && isAuthenticated" class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">This invitation link is missing its token. <RouterLink to="/" class="font-semibold underline">Return to your household</RouterLink>.</div>
            <div v-else-if="!token" class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">This invitation link is missing its token. Ask the household owner to resend it.</div>
            <div v-if="token && !sessionChecked" class="mt-5 rounded-md bg-surface-soft px-4 py-3 text-sm text-ink-muted" role="status">
                <span v-if="!errors.form">Checking your sign-in status…</span>
                <button v-else type="button" class="font-semibold text-sage-dark underline" @click="checkSession">Try checking again</button>
            </div>
            <form v-if="token && sessionChecked" class="mt-6 grid gap-4" @submit.prevent="submit">
                <template v-if="!isAuthenticated">
                    <div class="grid gap-2"><label for="accept-name" class="text-sm font-medium text-ink">Your name</label><input id="accept-name" v-model="name" autocomplete="name" required class="min-h-11 rounded-md border border-line px-3 text-sm outline-none focus:border-sage focus:ring-2 focus:ring-sage/20"><p v-if="errors.fields.name" class="text-sm text-rose-700">{{ errors.fields.name }}</p></div>
                    <div class="grid gap-2"><label for="accept-email" class="text-sm font-medium text-ink">Email address</label><input id="accept-email" v-model="email" type="email" autocomplete="email" required class="min-h-11 rounded-md border border-line px-3 text-sm outline-none focus:border-sage focus:ring-2 focus:ring-sage/20"><p v-if="errors.fields.email" class="text-sm text-rose-700">{{ errors.fields.email }}</p></div>
                    <div class="grid gap-2"><label for="accept-password" class="text-sm font-medium text-ink">Password</label><input id="accept-password" v-model="password" type="password" autocomplete="new-password" required class="min-h-11 rounded-md border border-line px-3 text-sm outline-none focus:border-sage focus:ring-2 focus:ring-sage/20"><p v-if="errors.fields.password" class="text-sm text-rose-700">{{ errors.fields.password }}</p></div>
                    <div class="grid gap-2"><label for="accept-password-confirmation" class="text-sm font-medium text-ink">Confirm password</label><input id="accept-password-confirmation" v-model="passwordConfirmation" type="password" autocomplete="new-password" required class="min-h-11 rounded-md border border-line px-3 text-sm outline-none focus:border-sage focus:ring-2 focus:ring-sage/20"></div>
                    <button type="submit" :disabled="isSubmitting" class="mt-1 min-h-12 rounded-md bg-sage px-4 text-sm font-semibold text-white hover:bg-sage-dark disabled:opacity-60">{{ isSubmitting ? 'Joining…' : 'Create account and join' }}</button>
                    <p class="text-center text-sm text-ink-muted">Already have an account? <button type="button" class="font-semibold text-sage-dark underline" @click="signInWithInvitation">Sign in to accept</button></p>
                </template>
                <template v-else>
                    <p class="rounded-md bg-surface-soft px-4 py-3 text-sm text-ink">Accepting as {{ session.user?.email }}.</p>
                    <button type="submit" :disabled="isSubmitting" class="min-h-12 rounded-md bg-sage px-4 text-sm font-semibold text-white hover:bg-sage-dark disabled:opacity-60">{{ isSubmitting ? 'Accepting…' : 'Accept invitation' }}</button>
                    <button type="button" class="text-center text-sm font-medium text-sage-dark underline" @click="signInWithInvitation">Use a different account</button>
                </template>
            </form>
        </section>
    </main>
</template>
