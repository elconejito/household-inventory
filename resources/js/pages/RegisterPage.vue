<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { useSessionStore } from '../stores/session';

const router = useRouter();
const session = useSessionStore();
const name = ref('');
const householdName = ref('');
const email = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const errors = ref<FormErrors>({ fields: {}, form: '' });
const isSubmitting = ref(false);

async function submit(): Promise<void> {
    errors.value = { fields: {}, form: '' };
    isSubmitting.value = true;

    try {
        await session.register({
            name: name.value,
            household_name: householdName.value,
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });
        await router.push('/');
    } catch (error) {
        errors.value = parseApiErrors(error);
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <main class="grid min-h-screen place-items-center bg-canvas px-5 py-10 sm:px-8">
        <section class="w-full max-w-md" aria-labelledby="register-title">
            <RouterLink to="/register" class="mx-auto flex w-fit items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-sage" aria-label="Household Inventory create account">
                <span class="grid size-11 place-items-center rounded-[10px] bg-sage text-lg font-semibold text-white" aria-hidden="true">H</span>
                <span class="text-base font-semibold tracking-tight text-ink">Household Inventory</span>
            </RouterLink>

            <div class="mt-8 rounded-panel border border-line bg-white p-6 shadow-card sm:p-9">
                <p class="eyebrow">A little more organized</p>
                <h1 id="register-title" class="mt-2 text-2xl font-semibold tracking-tight text-ink">Create your household</h1>
                <p class="mt-2 text-sm leading-6 text-ink-muted">Start with an account for you and a name for your home.</p>

                <div v-if="errors.form" class="mt-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-5 text-rose-800" role="alert">
                    {{ errors.form }}
                </div>

                <form class="mt-7 grid gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <label for="register-name" class="text-sm font-medium text-ink">Your name</label>
                        <input id="register-name" v-model="name" name="name" autocomplete="name" required :aria-invalid="Boolean(errors.fields.name)" :aria-describedby="errors.fields.name ? 'register-name-error' : undefined" class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition focus:border-sage focus:ring-2 focus:ring-sage/20">
                        <p v-if="errors.fields.name" id="register-name-error" class="text-sm text-rose-700">{{ errors.fields.name }}</p>
                    </div>

                    <div class="grid gap-2">
                        <label for="register-household" class="text-sm font-medium text-ink">Household name</label>
                        <input id="register-household" v-model="householdName" name="household_name" autocomplete="organization" required :aria-invalid="Boolean(errors.fields.household_name)" :aria-describedby="errors.fields.household_name ? 'register-household-error' : undefined" class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition focus:border-sage focus:ring-2 focus:ring-sage/20">
                        <p v-if="errors.fields.household_name" id="register-household-error" class="text-sm text-rose-700">{{ errors.fields.household_name }}</p>
                    </div>

                    <div class="grid gap-2">
                        <label for="register-email" class="text-sm font-medium text-ink">Email address</label>
                        <input id="register-email" v-model="email" type="email" name="email" autocomplete="email" required :aria-invalid="Boolean(errors.fields.email)" :aria-describedby="errors.fields.email ? 'register-email-error' : undefined" class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition focus:border-sage focus:ring-2 focus:ring-sage/20">
                        <p v-if="errors.fields.email" id="register-email-error" class="text-sm text-rose-700">{{ errors.fields.email }}</p>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2 sm:gap-4">
                        <div class="grid content-start gap-2">
                            <label for="register-password" class="text-sm font-medium text-ink">Password</label>
                            <input id="register-password" v-model="password" type="password" name="password" autocomplete="new-password" minlength="8" required :aria-invalid="Boolean(errors.fields.password)" :aria-describedby="errors.fields.password ? 'register-password-error' : undefined" class="min-h-12 min-w-0 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition focus:border-sage focus:ring-2 focus:ring-sage/20">
                            <p v-if="errors.fields.password" id="register-password-error" class="text-sm text-rose-700 sm:col-span-2">{{ errors.fields.password }}</p>
                        </div>
                        <div class="grid content-start gap-2">
                            <label for="register-password-confirmation" class="text-sm font-medium text-ink">Confirm password</label>
                            <input id="register-password-confirmation" v-model="passwordConfirmation" type="password" name="password_confirmation" autocomplete="new-password" required :aria-invalid="Boolean(errors.fields.password_confirmation)" :aria-describedby="errors.fields.password_confirmation ? 'register-confirmation-error' : undefined" class="min-h-12 min-w-0 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition focus:border-sage focus:ring-2 focus:ring-sage/20">
                            <p v-if="errors.fields.password_confirmation" id="register-confirmation-error" class="text-sm text-rose-700 sm:col-span-2">{{ errors.fields.password_confirmation }}</p>
                        </div>
                    </div>

                    <button type="submit" :disabled="isSubmitting" class="mt-2 flex min-h-12 items-center justify-center rounded-md bg-sage px-4 text-sm font-semibold text-white transition hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:cursor-wait disabled:opacity-70">
                        {{ isSubmitting ? 'Creating your account…' : 'Create account' }}
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-ink-muted">
                    Already have an account?
                    <RouterLink to="/login" class="font-semibold text-sage-dark underline decoration-sage/40 underline-offset-4 hover:decoration-sage focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">Sign in</RouterLink>
                </p>
            </div>
        </section>
    </main>
</template>
