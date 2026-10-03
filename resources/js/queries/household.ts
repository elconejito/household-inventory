import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, type ComputedRef } from 'vue';
import { acceptInvitation, createInvitation, getArchivedResources, getHousehold, getInvitations, getMembers, leaveHousehold, permanentlyDeleteResource, removeMember, resendInvitation, restoreArchivedResource, restoreMember, revokeInvitation, updateHousehold, updateMember, type ArchivedType } from '../api/household';

export const householdQueryKeys = {
    household: ['household'] as const,
    members: (trashed: 'without' | 'only', page: number) => ['memberships', trashed, page] as const,
    invitations: (page: number) => ['household-invitations', page] as const,
    archived: (type: ArchivedType) => ['archived-resources', type] as const,
};

export function useHouseholdQuery(enabled: ComputedRef<boolean> = computed(() => true)) {
    return useQuery({ queryKey: householdQueryKeys.household, queryFn: getHousehold, enabled });
}

export function useMembersQuery(trashed: ComputedRef<'without' | 'only'>, page: ComputedRef<number>, enabled: ComputedRef<boolean> = computed(() => true)) {
    return useQuery({ queryKey: computed(() => householdQueryKeys.members(trashed.value, page.value)), queryFn: () => getMembers(page.value, trashed.value), enabled });
}

export function useInvitationsQuery(page: ComputedRef<number>, enabled: boolean | ComputedRef<boolean> = true) {
    return useQuery({ queryKey: computed(() => householdQueryKeys.invitations(page.value)), queryFn: () => getInvitations(page.value), enabled });
}

export function useArchivedResourcesQuery(type: ComputedRef<ArchivedType>) {
    return useQuery({ queryKey: computed(() => householdQueryKeys.archived(type.value)), queryFn: () => getArchivedResources(type.value) });
}

export function useHouseholdMutations() {
    const client = useQueryClient();
    const refreshHousehold = async () => client.invalidateQueries({ queryKey: householdQueryKeys.household });
    const refreshMembers = async () => client.invalidateQueries({ queryKey: ['memberships'] });
    const refreshInvitations = async () => client.invalidateQueries({ queryKey: ['household-invitations'] });

    return {
        updateHousehold: useMutation({ mutationFn: updateHousehold, onSuccess: refreshHousehold }),
        updateMember: useMutation({ mutationFn: ({ id, role }: { id: string; role: 'owner' | 'member' }) => updateMember(id, role), onSuccess: refreshMembers }),
        removeMember: useMutation({ mutationFn: removeMember, onSuccess: refreshMembers }),
        restoreMember: useMutation({ mutationFn: restoreMember, onSuccess: refreshMembers }),
        leave: useMutation({ mutationFn: leaveHousehold }),
        createInvitation: useMutation({ mutationFn: createInvitation, onSuccess: refreshInvitations }),
        resendInvitation: useMutation({ mutationFn: resendInvitation, onSuccess: refreshInvitations }),
        revokeInvitation: useMutation({ mutationFn: revokeInvitation, onSuccess: refreshInvitations }),
        acceptInvitation: useMutation({ mutationFn: acceptInvitation }),
        restoreArchived: useMutation({ mutationFn: ({ type, id }: { type: ArchivedType; id: string }) => restoreArchivedResource(type, id), onSuccess: (_data, variables) => Promise.all([client.invalidateQueries({ queryKey: householdQueryKeys.archived(variables.type) }), client.invalidateQueries({ queryKey: [variables.type] })]) }),
        permanentlyDelete: useMutation({ mutationFn: ({ type, id }: { type: ArchivedType; id: string }) => permanentlyDeleteResource(type, id), onSuccess: (_data, variables) => Promise.all([client.invalidateQueries({ queryKey: householdQueryKeys.archived(variables.type) }), client.invalidateQueries({ queryKey: [variables.type] })]) }),
    };
}
