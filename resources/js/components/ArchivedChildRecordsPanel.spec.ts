import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { useSessionStore } from '../stores/session';
import ArchivedChildRecordsPanel from './ArchivedChildRecordsPanel.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));

const note = {
    type: 'notes', id: 'note-1', body: 'Line one\nLine two', created_at: '2026-10-01T12:00:00Z',
    updated_at: '2026-10-01T12:00:00Z', is_edited: false, created_by: { type: 'users', id: 'u-1', name: 'Harvey' },
};
const image = {
    type: 'item-images', id: 'image-1', caption: 'Storage box label', is_primary: false,
    uploaded_at: '2026-10-01T12:00:00Z', uploaded_by: { type: 'users', id: 'u-1', name: 'Harvey' },
    thumbnail_url: '/api/item-images/1/thumbnail', display_url: '/api/item-images/1/display',
    thumbnail_width: 320, thumbnail_height: 240, display_width: 1280, display_height: 960,
    thumbnail_mime_type: 'image/webp', display_mime_type: 'image/webp',
};

describe('archived child records panel', () => {
    let client: QueryClient;
    let pinia: ReturnType<typeof createPinia>;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        pinia = createPinia();
        vi.mocked(http.get).mockResolvedValue({ data: { data: [note], meta: { current_page: 1, last_page: 1, total: 1 } } } as never);
        vi.mocked(http.post).mockResolvedValue(undefined as never);
        vi.mocked(http.delete).mockResolvedValue(undefined as never);
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); vi.restoreAllMocks(); });

    function mountPanel(kind: 'notes' | 'item-images' = 'notes', role: 'owner' | 'member' = 'member'): void {
        useSessionStore(pinia).setAuthenticatedUser({
            id: 'u-1', name: 'Harvey', email: 'harvey@example.com',
            membership: { role, household: { id: 'h-1', name: 'Home' } },
        });
        wrapper = mount(ArchivedChildRecordsPanel, {
            props: { contextType: 'items', contextId: '7', kind },
            global: { plugins: [pinia, [VueQueryPlugin, { queryClient: client }]] },
        });
    }

    async function expand(): Promise<void> {
        const details = wrapper!.get('details');
        (details.element as HTMLDetailsElement).open = true;
        await details.trigger('toggle');
        await flushPromises();
    }

    it('does not fetch until the archived records disclosure is expanded', async () => {
        mountPanel();
        await flushPromises();

        expect(http.get).not.toHaveBeenCalled();
        await expand();
        expect(http.get).toHaveBeenCalledWith('/items/7/notes', {
            params: { include: 'created_by', 'filter[trashed]': 'only', per_page: 10, page: 1 },
        });
    });

    it('renders archived note text as plain text with preserved line breaks', async () => {
        mountPanel();
        await expand();

        expect(wrapper!.get('ol li p.whitespace-pre-wrap').element.textContent).toBe(note.body);
        expect(wrapper!.find('img').exists()).toBe(false);
    });

    it('lets a member restore a note and hides permanent deletion', async () => {
        mountPanel('notes', 'member');
        await expand();
        expect(wrapper!.text()).toContain('Restore note');
        expect(wrapper!.text()).not.toContain('Permanently delete');

        await wrapper!.get('button').trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/notes/note-1/restore');
    });

    it('requires typed owner confirmation before permanent deletion', async () => {
        mountPanel('notes', 'owner');
        await expand();
        await wrapper!.findAll('button').find((button) => button.text() === 'Permanently delete')!.trigger('click');

        expect(wrapper!.text()).toContain('This cannot be undone.');
        expect(wrapper!.get('button[type="submit"]').attributes('disabled')).toBeDefined();
        await wrapper!.get('input[aria-label="Type DELETE to confirm"]').setValue('DELETE');
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        expect(http.delete).toHaveBeenCalledWith('/notes/note-1/permanently');
    });

    it('shows a useful blocker returned by the server', async () => {
        mountPanel('item-images', 'owner');
        vi.mocked(http.get).mockResolvedValue({ data: { data: [image], meta: { current_page: 1, last_page: 1, total: 1 } } } as never);
        await expand();
        expect(wrapper!.text()).toContain('Storage box label');
        expect(wrapper!.find('img').exists()).toBe(false);
        vi.mocked(http.delete).mockRejectedValue(Object.assign(new Error('Conflict'), {
            isAxiosError: true,
            response: { data: { errors: [{ detail: 'The photo cannot be permanently deleted while it is still referenced.' }] } },
        }));
        await wrapper!.findAll('button').find((button) => button.text() === 'Permanently delete')!.trigger('click');
        await wrapper!.get('input[aria-label="Type DELETE to confirm"]').setValue('DELETE');
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper!.get('[role="alert"]').text()).toContain('The photo cannot be permanently deleted while it is still referenced.');
    });

    it('returns to the previous page after permanently deleting its final row', async () => {
        let deleted = false;
        vi.mocked(http.get).mockImplementation(async (_url, config) => {
            const requestedPage = (config?.params as { page?: number } | undefined)?.page ?? 1;
            const rows = requestedPage === 1
                ? Array.from({ length: 10 }, (_, index) => ({ ...note, id: `note-${index + 1}`, body: `Earlier note ${index + 1}` }))
                : deleted ? [] : [{ ...note, id: 'note-11', body: 'Last archived note' }];

            return { data: { data: rows, meta: { current_page: requestedPage, last_page: deleted ? 1 : 2, total: deleted ? 10 : 11 } } } as never;
        });
        vi.mocked(http.delete).mockImplementation(async () => { deleted = true; return undefined as never; });
        mountPanel('notes', 'owner');
        await expand();
        const nextPage = wrapper!.findAll('button').find((button) => button.text() === 'Next')!;
        expect(nextPage.attributes('disabled')).toBeUndefined();
        await nextPage.trigger('click');
        await flushPromises();
        expect(vi.mocked(http.get).mock.calls.map((call) => call[1]?.params)).toEqual(expect.arrayContaining([
            expect.objectContaining({ page: 2 }),
        ]));
        expect(wrapper!.text()).toContain('Last archived note');

        await wrapper!.findAll('button').find((button) => button.text() === 'Permanently delete')!.trigger('click');
        await wrapper!.get('input[aria-label="Type DELETE to confirm"]').setValue('DELETE');
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper!.text()).toContain('Earlier note 1');
        expect(wrapper!.findAll('button').some((button) => button.text() === 'Next')).toBe(false);
    });

    it('invalidates the parent context that started a pending mutation', async () => {
        let finishDelete: (() => void) | undefined;
        vi.mocked(http.delete).mockImplementation(() => new Promise((resolve) => {
            finishDelete = () => resolve(undefined);
        }) as never);
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        mountPanel('notes', 'owner');
        await expand();
        await wrapper!.findAll('button').find((button) => button.text() === 'Permanently delete')!.trigger('click');
        await wrapper!.get('input[aria-label="Type DELETE to confirm"]').setValue('DELETE');
        await wrapper!.get('form').trigger('submit');
        await flushPromises();
        await wrapper!.setProps({ contextId: '8' });
        finishDelete!();
        await flushPromises();

        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['archived-child-records', 'notes', 'items', '7'] });
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['notes', 'items', '7'] });
        expect(invalidate).not.toHaveBeenCalledWith({ queryKey: ['notes', 'items', '8'] });
    });
});
