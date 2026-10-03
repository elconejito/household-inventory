import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import NotesPanel from './NotesPanel.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));

const note = { type: 'notes', id: '51', body: 'Keep the spare batteries in the hall closet.\nCheck the date.', created_at: '2026-10-01T12:00:00Z', updated_at: '2026-10-01T12:00:00Z', is_edited: false, created_by: { type: 'users', id: '4', name: 'Harvey' } };

describe('notes panel', () => {
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        vi.mocked(http.get).mockResolvedValue({ data: { data: [note], meta: { current_page: 1, last_page: 1, total: 1 } } } as never);
        vi.mocked(http.post).mockResolvedValue({ data: { data: { ...note, id: '52', body: 'New note' } } } as never);
        vi.mocked(http.patch).mockResolvedValue({ data: { data: { ...note, body: 'Updated note', is_edited: true } } } as never);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); vi.restoreAllMocks(); });

    function mountPanel(): void {
        wrapper = mount(NotesPanel, { props: { type: 'items', contextId: '7' }, global: { plugins: [[VueQueryPlugin, { queryClient: client }]] } });
    }

    it('loads recent notes with their author and preserves plain text line breaks', async () => {
        mountPanel();
        await flushPromises();
        expect(http.get).toHaveBeenCalledWith('/items/7/notes', { params: { include: 'created_by', per_page: 10, page: 1 } });
        expect(wrapper!.text()).toContain('Harvey');
        expect(wrapper!.text()).toContain('Check the date.');
        expect(wrapper!.find('article').exists()).toBe(true);
    });

    it('adds notes and patches only when the edited body changed', async () => {
        mountPanel();
        await flushPromises();
        await wrapper!.get('#new-note-items-7').setValue('New note');
        await wrapper!.findAll('form')[0].trigger('submit');
        await flushPromises();
        expect(http.post).toHaveBeenCalledWith('/items/7/notes', { data: { body: 'New note' } });

        await wrapper!.findAll('button').find((button) => button.text() === 'Edit note')!.trigger('click');
        await wrapper!.get('#edit-note-51').setValue(note.body);
        await wrapper!.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(http.patch).not.toHaveBeenCalled();

        await wrapper!.findAll('button').find((button) => button.text() === 'Edit note')!.trigger('click');
        await wrapper!.get('#edit-note-51').setValue('Changed detail');
        await wrapper!.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(http.patch).toHaveBeenCalledWith('/notes/51', { data: { body: 'Changed detail' } });
    });

    it('confirms note deletion', async () => {
        mountPanel();
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Delete note')!.trigger('click');
        await flushPromises();
        expect(window.confirm).toHaveBeenCalledWith('Delete this note?');
        expect(http.delete).toHaveBeenCalledWith('/notes/51');
    });

    it('preserves outer whitespace when saving and renders HTML as plain text', async () => {
        const body = '\n  <script>alert("note")</script>\n\n Keep this spacing. \n';
        vi.mocked(http.get).mockResolvedValue({ data: { data: [{ ...note, body }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never);
        mountPanel();
        await flushPromises();

        expect(wrapper!.get('article > p').element.textContent).toBe(body);
        expect(wrapper!.find('script').exists()).toBe(false);
        await wrapper!.get('#new-note-items-7').setValue(body);
        await wrapper!.findAll('form')[0].trigger('submit');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/items/7/notes', { data: { body } });
    });

    it('returns to the previous page after deleting the last note on a page', async () => {
        let deleted = false;
        vi.mocked(http.get).mockImplementation(async (_url, config) => {
            const page = (config?.params as { page?: number } | undefined)?.page ?? 1;
            const rows = page === 1 ? Array.from({ length: 10 }, (_, index) => ({ ...note, id: String(index + 1), body: `Earlier note ${index + 1}` })) : deleted ? [] : [{ ...note, id: '11', body: 'Last note' }];
            return { data: { data: rows, meta: { current_page: page, last_page: deleted ? 1 : 2, total: deleted ? 10 : 11 } } } as never;
        });
        vi.mocked(http.delete).mockImplementation(async () => { deleted = true; return undefined as never; });
        mountPanel();
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Next')!.trigger('click');
        await flushPromises();
        expect(wrapper!.text()).toContain('Last note');

        await wrapper!.findAll('button').find((button) => button.text() === 'Delete note')!.trigger('click');
        await flushPromises();

        expect(wrapper!.text()).toContain('Earlier note 1');
        expect(wrapper!.text()).not.toContain('No notes yet.');
    });

    it('refreshes the original context if navigation happens while a save is pending', async () => {
        let finishSave: ((result: unknown) => void) | undefined;
        vi.mocked(http.post).mockImplementation(() => new Promise((resolve) => { finishSave = resolve; }) as never);
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        mountPanel();
        await flushPromises();
        await wrapper!.get('#new-note-items-7').setValue('Saving on item seven');
        await wrapper!.findAll('form')[0].trigger('submit');
        await flushPromises();
        await wrapper!.setProps({ contextId: '8' });
        await flushPromises();

        finishSave!({ data: { data: note } });
        await flushPromises();

        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['notes', 'items', '7'] });
        expect(invalidate).not.toHaveBeenCalledWith({ queryKey: ['notes', 'items', '8'] });
    });
});
