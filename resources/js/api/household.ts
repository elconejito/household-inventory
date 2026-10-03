import { http, requestCsrfCookie } from '../lib/http';

export type Household = { type: 'households'; id: string; name: string };
export type HouseholdMember = {
    type: 'memberships';
    id: string;
    role: 'owner' | 'member';
    deleted_at: string | null;
    user?: { type: 'users'; id: string; name: string; email: string } | null;
};
export type HouseholdInvitation = {
    type: 'household-invitations';
    id: string;
    email: string;
    role: 'member' | 'owner';
    created_at: string;
    expires_at: string;
    accepted_at: string | null;
    revoked_at: string | null;
    is_expired: boolean;
    invitation_url?: string;
};
export type Page<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } };
type Resource<T> = { data: T };
type HouseholdResponse = Resource<Household>;

export async function getHousehold(): Promise<Household> {
    const response = await http.get<HouseholdResponse>('/household');
    return response.data.data;
}

export async function updateHousehold(name: string): Promise<Household> {
    const response = await http.patch<HouseholdResponse>('/household', { data: { name } });
    return response.data.data;
}

export async function getMembers(page: number, trashed: 'without' | 'only' = 'without'): Promise<Page<HouseholdMember>> {
    const response = await http.get<Page<HouseholdMember>>('/memberships', {
        params: { include: 'user', 'filter[trashed]': trashed, per_page: 10, page },
    });
    return response.data;
}

export async function updateMember(id: string, role: 'owner' | 'member'): Promise<HouseholdMember> {
    const response = await http.patch<Resource<HouseholdMember>>(`/memberships/${id}`, { data: { role } });
    return response.data.data;
}

export async function removeMember(id: string): Promise<void> {
    await http.delete(`/memberships/${id}`);
}

export async function restoreMember(id: string): Promise<HouseholdMember> {
    const response = await http.post<Resource<HouseholdMember>>(`/memberships/${id}/restore`);
    return response.data.data;
}

export async function leaveHousehold(): Promise<void> {
    await http.post('/membership/leave');
}

export async function getInvitations(page: number): Promise<Page<HouseholdInvitation>> {
    const response = await http.get<Page<HouseholdInvitation>>('/household-invitations', { params: { per_page: 10, page } });
    return response.data;
}

export async function createInvitation(email: string): Promise<HouseholdInvitation> {
    const response = await http.post<Resource<HouseholdInvitation>>('/household-invitations', { data: { email } });
    return response.data.data;
}

export async function resendInvitation(id: string): Promise<HouseholdInvitation> {
    const response = await http.post<Resource<HouseholdInvitation>>(`/household-invitations/${id}/resend`);
    return response.data.data;
}

export async function revokeInvitation(id: string): Promise<void> {
    await http.delete(`/household-invitations/${id}`);
}

export type InvitationAcceptance = {
    token: string;
    email?: string;
    name?: string;
    password?: string;
    password_confirmation?: string;
};

export type AcceptedUser = {
    type: 'users';
    id: string;
    name: string;
    email: string;
    membership: {
        type?: 'memberships';
        id?: string;
        role: 'member' | 'owner';
        household: { type?: 'households'; id: string; name: string };
    };
};

export async function acceptInvitation(data: InvitationAcceptance): Promise<AcceptedUser> {
    await requestCsrfCookie();
    const response = await http.post<Resource<AcceptedUser>>('/household-invitations/accept', { data }, { params: { include: 'membership.household' } });
    return response.data.data;
}

export type ArchivedType = 'items' | 'categories' | 'locations';
export type ArchivedResource = { type: string; id: string; name: string; counting_unit?: string; description?: string | null };

export async function getArchivedResources(type: ArchivedType): Promise<ArchivedResource[]> {
    const fetchPage = (page: number) => http.get<Page<ArchivedResource>>(`/${type}`, {
        params: { 'filter[trashed]': 'only', per_page: 100, page },
    });
    const firstPage = await fetchPage(1);
    const remaining = await Promise.all(Array.from({ length: Math.max(0, firstPage.data.meta.last_page - 1) }, (_, index) => fetchPage(index + 2)));
    return [...firstPage.data.data, ...remaining.flatMap((response) => response.data.data)];
}

export async function restoreArchivedResource(type: ArchivedType, id: string): Promise<void> {
    await http.post(`/${type}/${id}/restore`);
}

export async function permanentlyDeleteResource(type: ArchivedType, id: string): Promise<void> {
    await http.delete(`/${type}/${id}/permanently`);
}
