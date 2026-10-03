<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { postAuthenticationPath } from '../lib/auth-navigation';
import { useSessionStore } from '../stores/session';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const email = ref('');
const password = ref('');
const errors = ref<FormErrors>({ fields: {}, form: '' });
const isSubmitting = ref(false);

async function submit(): Promise<void> {
    errors.value = { fields: {}, form: '' };
    isSubmitting.value = true;

    try {
        await session.login({ email: email.value, password: password.value });
        const destination = postAuthenticationPath(route.query.redirect);
        await router.push(destination === '/invitations/accept' && route.hash ? `${destination}${route.hash}` : destination);
    } catch (error) {
        errors.value = parseApiErrors(error);
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <main class="grid min-h-screen place-items-center bg-canvas px-5 py-10 sm:px-8">
        <section class="w-full max-w-md" aria-labelledby="login-title">
            <RouterLink to="/login" class="mx-auto flex w-fit items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-sage" aria-label="Household Inventory sign in">
                <span class="grid size-11 place-items-center rounded-[10px] bg-sage text-lg font-semibold text-white" aria-hidden="true">H</span>
                <span class="text-base font-semibold tracking-tight text-ink">Household Inventory</span>
            </RouterLink>

            <div class="mt-8 rounded-panel border border-line bg-white p-6 shadow-card sm:p-9">
                <p class="eyebrow">Welcome back</p>
                <h1 id="login-title" class="mt-2 text-2xl font-semibold tracking-tight text-ink">Sign in to your home</h1>
                <p class="mt-2 text-sm leading-6 text-ink-muted">Keep track of what you have and where it lives.</p>

                <div v-if="errors.form" class="mt-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-5 text-rose-800" role="alert">
                    {{ errors.form }}
                </div>

                <form class="mt-7 grid gap-5" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <label for="login-email" class="text-sm font-medium text-ink">Email address</label>
                        <input
                            id="login-email"
                            v-model="email"
                            type="email"
                            name="email"
                            autocomplete="email"
                            required
                            :aria-invalid="Boolean(errors.fields.email)"
                            :aria-describedby="errors.fields.email ? 'login-email-error' : undefined"
                            class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition placeholder:text-ink-muted/70 focus:border-sage focus:ring-2 focus:ring-sage/20"
                        >
                        <p v-if="errors.fields.email" id="login-email-error" class="text-sm text-rose-700">{{ errors.fields.email }}</p>
                    </div>

                    <div class="grid gap-2">
                        <label for="login-password" class="text-sm font-medium text-ink">Password</label>
                        <input
                            id="login-password"
                            v-model="password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                            :aria-invalid="Boolean(errors.fields.password)"
                            :aria-describedby="errors.fields.password ? 'login-password-error' : undefined"
                            class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition placeholder:text-ink-muted/70 focus:border-sage focus:ring-2 focus:ring-sage/20"
                        >
                        <p v-if="errors.fields.password" id="login-password-error" class="text-sm text-rose-700">{{ errors.fields.password }}</p>
                    </div>

                    <button
                        type="submit"
                        :disabled="isSubmitting"
                        class="mt-1 flex min-h-12 items-center justify-center rounded-md bg-sage px-4 text-sm font-semibold text-white transition hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ isSubmitting ? 'Signing in…' : 'Sign in' }}
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-ink-muted">
                    New to Household Inventory?
                    <RouterLink to="/register" class="font-semibold text-sage-dark underline decoration-sage/40 underline-offset-4 hover:decoration-sage focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">Create an account</RouterLink>
                </p>
            </div>
        </section>
    </main>
</template>
