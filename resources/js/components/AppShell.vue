<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router';

const navigation = [
    { label: 'Dashboard', to: '/', icon: '⌂' },
    { label: 'Inventory', to: '/inventory', icon: '▤' },
    { label: 'Activity', to: '/activity', icon: '↻' },
];
</script>

<template>
    <div class="min-h-screen pb-24 md:pb-0">
        <header class="border-b border-line bg-white/90">
            <div class="mx-auto flex h-[72px] max-w-7xl items-center justify-between px-5 sm:px-8">
                <RouterLink to="/" class="flex items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-sage" aria-label="Household Inventory home">
                    <span class="grid size-10 place-items-center rounded-[10px] bg-sage text-lg font-semibold text-white" aria-hidden="true">H</span>
                    <span class="hidden text-[15px] font-semibold tracking-tight sm:inline">Household Inventory</span>
                    <span class="text-[15px] font-semibold tracking-tight sm:hidden">Home inventory</span>
                </RouterLink>

                <nav class="hidden items-center gap-1 md:flex" aria-label="Main navigation">
                    <RouterLink
                        v-for="item in navigation"
                        :key="item.label"
                        :to="item.to"
                        exact-active-class="nav-link-active"
                        class="nav-link"
                    >
                        {{ item.label }}
                    </RouterLink>
                </nav>

                <button class="grid size-10 place-items-center rounded-md border border-line bg-white text-sm font-semibold text-ink-muted transition hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" aria-label="Account menu">
                    JR
                </button>
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl px-5 py-8 sm:px-8 sm:py-10">
            <RouterView />
        </main>

        <nav class="fixed inset-x-0 bottom-0 z-10 grid grid-cols-3 border-t border-line bg-white/95 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-2 backdrop-blur md:hidden" aria-label="Main navigation">
            <RouterLink
                v-for="item in navigation"
                :key="item.label"
                :to="item.to"
                exact-active-class="mobile-link-active"
                class="mobile-link"
            >
                <span class="text-xl leading-none" aria-hidden="true">{{ item.icon }}</span>
                <span>{{ item.label }}</span>
            </RouterLink>
        </nav>
    </div>
</template>
