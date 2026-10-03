<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQueryClient } from '@tanstack/vue-query';
import { RouterLink, useRouter } from 'vue-router';
import { parseApiErrors } from '../lib/api-errors';
import { useSessionStore } from '../stores/session';
import { householdQueryKeys, useHouseholdMutations, useHouseholdQuery, useInvitationsQuery, useMembersQuery } from '../queries/household';

const router = useRouter();
const session = useSessionStore();
const queryClient = useQueryClient();
const owner = computed(() => session.user?.membership?.role === 'owner');
const householdQuery = useHouseholdQuery(computed(() => owner.value));
const mutations = useHouseholdMutations();
const householdName = ref('');
const memberStatus = ref<'without' | 'only'>('without');
const memberPage = ref(1);
const invitationPage = ref(1);
const membersQuery = useMembersQuery(computed(() => memberStatus.value), computed(() => memberPage.value), computed(() => owner.value));
const inviteQuery = useInvitationsQuery(computed(() => invitationPage.value), computed(() => owner.value));
const nameError = ref('');
const memberError = ref('');
const inviteError = ref('');
const inviteSuccess = ref('');
const householdSuccess = ref('');
const email = ref('');
const oneTimeLink = ref('');
const copied = ref(false);
const leaving = ref(false);

watch(() => householdQuery.data.value?.name, (name) => {
    if (name !== undefined) householdName.value = name;
}, { immediate: true });

watch(memberStatus, () => { memberPage.value = 1; });
watch(owner, (isOwner) => { if (!isOwner) invitationPage.value = 1; });
watch(() => inviteQuery.data.value?.meta.last_page, (lastPage) => {
    if (lastPage && invitationPage.value > lastPage) invitationPage.value = lastPage;
});

const members = computed(() => membersQuery.data.value?.data ?? []);
const invitations = computed(() => inviteQuery.data.value?.data ?? []);
async function saveHousehold(): Promise<void> {
    nameError.value = '';
    householdSuccess.value = '';
    if (householdName.value === (householdQuery.data.value?.name ?? session.user?.membership?.household?.name)) {
        householdSuccess.value = 'Household name is unchanged.';
        return;
    }
    try {
        const household = await mutations.updateHousehold.mutateAsync(householdName.value);
        if (session.user?.membership?.household) session.user.membership.household.name = household.name;
        householdSuccess.value = 'Household name saved.';
    } catch (error) {
        nameError.value = parseApiErrors(error).form || parseApiErrors(error).fields.name || 'The household name could not be saved.';
    }
}

async function changeRole(id: string, role: 'owner' | 'member', event: Event): Promise<void> {
    memberError.value = '';
    try {
        await mutations.updateMember.mutateAsync({ id, role });
        const user = await session.refresh();
        if (!user?.membership?.household?.id) await router.replace({ name: 'invitation-accept' });
    } catch (error) {
        await queryClient.invalidateQueries({ queryKey: ['memberships'] });
        const actualRole = members.value.find((member) => member.id === id)?.role;
        if (actualRole && event.target instanceof HTMLSelectElement) event.target.value = actualRole;
        memberError.value = parseApiErrors(error).form || 'That role could not be changed. The final owner must remain an owner.';
    }
}

async function removeMember(id: string, name: string): Promise<void> {
    if (!window.confirm(`Remove ${name} from this household?`)) return;
    memberError.value = '';
    try {
        await mutations.removeMember.mutateAsync(id);
        const user = await session.refresh();
        if (!user?.membership?.household?.id) await router.replace({ name: 'invitation-accept' });
    } catch (error) {
        await queryClient.invalidateQueries({ queryKey: ['memberships'] });
        memberError.value = parseApiErrors(error).form || 'That person could not be removed.';
    }
}

async function restoreMember(id: string): Promise<void> {
    memberError.value = '';
    try {
        await mutations.restoreMember.mutateAsync(id);
        await session.refresh();
    } catch (error) {
        memberError.value = parseApiErrors(error).form || 'That membership could not be restored.';
    }
}

async function inviteMember(): Promise<void> {
    inviteError.value = '';
    inviteSuccess.value = '';
    oneTimeLink.value = '';
    copied.value = false;
    try {
        const invitation = await mutations.createInvitation.mutateAsync(email.value.trim());
        invitationPage.value = 1;
        email.value = '';
        oneTimeLink.value = invitation.invitation_url ?? '';
        inviteSuccess.value = oneTimeLink.value ? 'Invitation created. Copy the one-time link now; it is only shown once.' : 'Invitation created, but the link was unavailable. Resend it to create a new one-time link.';
    } catch (error) {
        inviteError.value = parseApiErrors(error).form || parseApiErrors(error).fields.email || 'The invitation could not be created.';
    }
}

async function resendInvitation(id: string): Promise<void> {
    inviteError.value = '';
    inviteSuccess.value = '';
    oneTimeLink.value = '';
    copied.value = false;
    try {
        const invitation = await mutations.resendInvitation.mutateAsync(id);
        oneTimeLink.value = invitation.invitation_url ?? '';
        inviteSuccess.value = oneTimeLink.value ? 'A fresh one-time invitation link is ready to copy.' : 'The invitation was renewed, but the link was unavailable.';
    } catch (error) {
        inviteError.value = parseApiErrors(error).form || 'The invitation could not be resent.';
    }
}

async function revokeInvitation(id: string, inviteEmail: string): Promise<void> {
    if (!window.confirm(`Revoke the invitation for ${inviteEmail}?`)) return;
    inviteError.value = '';
    try {
        await mutations.revokeInvitation.mutateAsync(id);
        if (oneTimeLink.value) oneTimeLink.value = '';
        inviteSuccess.value = 'Invitation revoked.';
    } catch (error) {
        inviteError.value = parseApiErrors(error).form || 'The invitation could not be revoked.';
    }
}

async function copyInviteLink(): Promise<void> {
    if (!oneTimeLink.value) return;
    try {
        await navigator.clipboard.writeText(oneTimeLink.value);
        copied.value = true;
    } catch {
        inviteError.value = 'Copying failed. Select and copy the link manually.';
    }
}

async function leaveHousehold(): Promise<void> {
    if (leaving.value || !window.confirm(owner.value ? 'Leave this household? The final owner must transfer ownership first.' : 'Leave this household? An owner must restore your archived membership before you can rejoin.')) return;
    leaving.value = true;
    memberError.value = '';
    try {
        await mutations.leave.mutateAsync();
        const user = await session.refresh();
        if (user) await queryClient.invalidateQueries({ queryKey: householdQueryKeys.household });
        await router.replace({ name: 'invitation-accept' });
    } catch (error) {
        memberError.value = parseApiErrors(error).form || 'You could not leave this household. The final owner must transfer ownership first.';
    } finally {
        leaving.value = false;
    }
}

function memberName(member: (typeof members.value)[number]): string {
    return member.user?.name ?? 'Household member';
}

function invitationState(invitation: (typeof invitations.value)[number]): string {
    if (invitation.accepted_at) return 'Accepted';
    if (invitation.revoked_at) return 'Revoked';
    if (invitation.is_expired) return 'Expired';
    return 'Pending';
}

function canManageInvitation(invitation: (typeof invitations.value)[number]): boolean {
    return !invitation.accepted_at && !invitation.revoked_at;
}
</script>

<template>
    <section aria-labelledby="settings-title">
        <p class="eyebrow">Your household</p>
        <h1 id="settings-title" class="page-title mt-2">Settings</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted sm:text-base">Manage your household, people, and invitations.</p>

        <div v-if="owner && householdQuery.isPending.value" class="mt-6 grid min-h-40 place-items-center rounded-panel border border-line bg-white text-sm text-ink-muted" role="status">Loading household…</div>
        <div v-else-if="owner && householdQuery.isError.value" class="mt-6 rounded-panel border border-line bg-white p-5" role="alert">
            <p class="font-semibold text-ink">Household details could not be loaded</p>
            <button type="button" class="mt-3 rounded-md border border-line px-3 py-2 text-sm" @click="householdQuery.refetch()">Try again</button>
        </div>
        <template v-else>
            <section class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-6" aria-labelledby="household-heading">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><h2 id="household-heading" class="text-lg font-semibold text-ink">{{ session.user?.membership?.household?.name ?? householdQuery.data.value?.name ?? 'Household details' }}</h2><p class="mt-1 text-sm text-ink-muted">You’re signed in as {{ session.user?.email }}.</p></div>
                    <span class="rounded-full bg-sage-soft px-3 py-1 text-xs font-semibold capitalize text-sage-dark">{{ session.user?.membership?.role }}</span>
                </div>
                <form v-if="owner" class="mt-5 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="saveHousehold">
                    <div class="grid gap-2"><label for="household-name" class="text-sm font-medium text-ink">Household name</label><input id="household-name" v-model="householdName" required maxlength="255" class="min-h-11 min-w-0 rounded-md border border-line px-3 text-sm outline-none focus:border-sage focus:ring-2 focus:ring-sage/20"><p v-if="nameError" class="text-sm text-rose-700" role="alert">{{ nameError }}</p></div>
                    <button type="submit" :disabled="mutations.updateHousehold.isPending.value" class="self-end rounded-md bg-sage px-4 py-3 text-sm font-semibold text-white hover:bg-sage-dark disabled:opacity-60">{{ mutations.updateHousehold.isPending.value ? 'Saving…' : 'Save name' }}</button>
                </form>
                <p v-else class="mt-5 rounded-md bg-surface-soft px-4 py-3 text-sm text-ink">Household summary for {{ session.user?.membership?.household?.name }}.</p>
                <p v-if="householdSuccess" class="mt-4 text-sm font-medium text-sage-dark" role="status">{{ householdSuccess }}</p>
            </section>

            <section class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-6" aria-labelledby="members-heading">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="members-heading" class="text-lg font-semibold text-ink">People</h2><p class="mt-1 text-sm text-ink-muted">{{ owner ? 'Owners and members who share this household.' : 'Your household membership.' }}</p></div>
                    <label v-if="owner" class="flex items-center gap-2 text-sm text-ink-muted">Show <select v-model="memberStatus" class="min-h-10 rounded-md border border-line bg-white px-2 text-sm text-ink"><option value="without">Active</option><option value="only">Archived</option></select></label>
                </div>
                <p v-if="memberError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ memberError }}</p>
                <div v-if="!owner" class="mt-4 divide-y divide-line rounded-md border border-line">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"><div><p class="font-medium text-ink">{{ session.user?.name }}</p><p class="text-sm text-ink-muted">{{ session.user?.email }} · {{ session.user?.membership?.role }}</p></div><button type="button" :disabled="leaving" class="rounded-md border border-line px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50 disabled:opacity-50" @click="leaveHousehold">{{ leaving ? 'Leaving…' : 'Leave household' }}</button></div>
                </div>
                <div v-else-if="membersQuery.isPending.value" class="py-8 text-center text-sm text-ink-muted" role="status">Loading people…</div>
                <div v-else-if="membersQuery.isError.value" class="py-6 text-sm text-rose-700" role="alert">People could not be loaded. <button type="button" class="underline" @click="membersQuery.refetch()">Try again</button></div>
                <div v-else-if="!members.length" class="py-8 text-center text-sm text-ink-muted">No {{ memberStatus === 'only' ? 'archived' : 'active' }} memberships.</div>
                <ul v-else class="mt-4 divide-y divide-line rounded-md border border-line">
                    <li v-for="member in members" :key="member.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0"><p class="truncate font-medium text-ink">{{ memberName(member) }}</p><p class="truncate text-sm text-ink-muted">{{ member.user?.email ?? 'Account unavailable' }}</p></div>
                        <div class="flex flex-wrap items-center gap-2">
                            <select v-if="memberStatus === 'without'" :value="member.role" :disabled="mutations.updateMember.isPending.value" :aria-label="`Role for ${memberName(member)}`" class="min-h-10 rounded-md border border-line bg-white px-2 text-sm" @change="changeRole(member.id, ($event.target as HTMLSelectElement).value as 'owner' | 'member', $event)"><option value="member">Member</option><option value="owner">Owner</option></select>
                            <span v-else class="rounded-full bg-surface-soft px-3 py-1 text-xs font-medium text-ink-muted">Archived</span>
                            <button v-if="memberStatus === 'without'" type="button" :disabled="mutations.removeMember.isPending.value" class="min-h-10 rounded-md px-3 text-sm font-medium text-rose-700 hover:bg-rose-50 disabled:opacity-50" @click="removeMember(member.id, memberName(member))">Remove</button>
                            <button v-else type="button" :disabled="mutations.restoreMember.isPending.value" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-sage-dark hover:bg-sage-soft disabled:opacity-50" @click="restoreMember(member.id)">Restore</button>
                        </div>
                    </li>
                </ul>
                <div v-if="owner && membersQuery.data.value && membersQuery.data.value.meta.last_page > 1" class="mt-4 flex items-center justify-between gap-3"><p class="text-sm text-ink-muted">Page {{ membersQuery.data.value.meta.current_page }} of {{ membersQuery.data.value.meta.last_page }}</p><div class="flex gap-2"><button type="button" :disabled="memberPage <= 1" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="memberPage--">Previous</button><button type="button" :disabled="memberPage >= membersQuery.data.value.meta.last_page" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="memberPage++">Next</button></div></div>
            </section>

            <section v-if="owner" class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-6" aria-labelledby="invitations-heading">
                <div><h2 id="invitations-heading" class="text-lg font-semibold text-ink">Invitations</h2><p class="mt-1 text-sm text-ink-muted">Invite someone as a household member. Copy the one-time link before leaving this page.</p></div>
                <form class="mt-5 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="inviteMember"><div class="grid gap-2"><label for="invite-email" class="text-sm font-medium text-ink">Invitation email</label><input id="invite-email" v-model="email" type="email" autocomplete="email" required class="min-h-11 min-w-0 rounded-md border border-line px-3 text-sm outline-none focus:border-sage focus:ring-2 focus:ring-sage/20"></div><button type="submit" :disabled="mutations.createInvitation.isPending.value" class="self-end rounded-md bg-sage px-4 py-3 text-sm font-semibold text-white hover:bg-sage-dark disabled:opacity-60">{{ mutations.createInvitation.isPending.value ? 'Creating…' : 'Create invitation' }}</button></form>
                <p v-if="inviteError" class="mt-3 text-sm text-rose-700" role="alert">{{ inviteError }}</p>
                <p v-if="inviteSuccess" class="mt-3 text-sm font-medium text-sage-dark" role="status">{{ inviteSuccess }}</p>
                <div v-if="oneTimeLink" class="mt-4 rounded-md border border-sage/20 bg-sage-soft p-4">
                    <label for="one-time-invitation-link" class="text-sm font-semibold text-sage-dark">One-time invitation link</label>
                    <div class="mt-2 flex flex-wrap gap-2"><input id="one-time-invitation-link" :value="oneTimeLink" readonly class="min-h-10 min-w-0 flex-1 rounded-md border border-line bg-white px-3 text-sm text-ink"><button type="button" class="min-h-10 rounded-md border border-line bg-white px-3 text-sm font-semibold text-ink" @click="copyInviteLink">{{ copied ? 'Copied' : 'Copy link' }}</button></div>
                </div>
                <div v-if="inviteQuery.isPending.value" class="py-6 text-sm text-ink-muted" role="status">Loading invitations…</div>
                <div v-else-if="inviteQuery.isError.value" class="py-5 text-sm text-rose-700" role="alert">Invitations could not be loaded. <button type="button" class="underline" @click="inviteQuery.refetch()">Try again</button></div>
                <div v-else-if="invitations.length" class="mt-5 divide-y divide-line rounded-md border border-line">
                    <div v-for="invitation in invitations" :key="invitation.id" :data-testid="`invitation-row-${invitation.id}`" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0"><p class="truncate font-medium text-ink">{{ invitation.email }}</p><p class="text-sm text-ink-muted">{{ invitationState(invitation) }} · expires {{ new Date(invitation.expires_at).toLocaleDateString() }}</p></div>
                        <div v-if="canManageInvitation(invitation)" class="flex gap-2"><button type="button" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-ink" :disabled="mutations.resendInvitation.isPending.value" @click="resendInvitation(invitation.id)">Resend</button><button type="button" class="min-h-10 rounded-md px-3 text-sm font-medium text-rose-700 hover:bg-rose-50" :disabled="mutations.revokeInvitation.isPending.value" @click="revokeInvitation(invitation.id, invitation.email)">Revoke</button></div>
                    </div>
                </div>
                <p v-else class="mt-5 text-sm text-ink-muted">There are no invitations yet.</p>
                <div v-if="inviteQuery.data.value && inviteQuery.data.value.meta.last_page > 1" class="mt-4 flex items-center justify-between gap-3"><p class="text-sm text-ink-muted">Page {{ inviteQuery.data.value.meta.current_page }} of {{ inviteQuery.data.value.meta.last_page }}</p><div class="flex gap-2"><button type="button" :disabled="invitationPage <= 1 || inviteQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="invitationPage--">Previous</button><button type="button" :disabled="invitationPage >= inviteQuery.data.value.meta.last_page || inviteQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="invitationPage++">Next</button></div></div>
            </section>

            <section class="mt-6 grid gap-3 sm:grid-cols-2">
                <RouterLink to="/settings/archives" class="rounded-panel border border-line bg-white p-5 shadow-card transition hover:border-sage/50"><h2 class="font-semibold text-ink">Archived inventory</h2><p class="mt-1 text-sm leading-5 text-ink-muted">Restore archived items, categories, and locations{{ owner ? ', or permanently delete them' : '' }}.</p></RouterLink>
                <button v-if="owner" type="button" :disabled="leaving" class="rounded-panel border border-line bg-white p-5 text-left shadow-card transition hover:border-rose-200 disabled:opacity-60" @click="leaveHousehold"><h2 class="font-semibold text-rose-800">Leave household</h2><p class="mt-1 text-sm leading-5 text-ink-muted">The final owner must transfer ownership before leaving.</p></button>
                <button v-else type="button" :disabled="leaving" class="rounded-panel border border-line bg-white p-5 text-left shadow-card transition hover:border-rose-200 disabled:opacity-60" @click="leaveHousehold"><h2 class="font-semibold text-rose-800">Leave household</h2><p class="mt-1 text-sm leading-5 text-ink-muted">An owner must restore your archived membership before you can rejoin.</p></button>
            </section>
        </template>
    </section>
</template>
