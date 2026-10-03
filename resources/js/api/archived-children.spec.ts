import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { getArchivedChildren, permanentlyDeleteArchivedChild, restoreArchivedChild } from './archived-children';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));

describe('archived child requests', () => {
    beforeEach(() => vi.clearAllMocks());

    it('requests archived notes with pagination and their author', async () => {
        vi.mocked(http.get).mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never);

        await getArchivedChildren('inventory-alerts', '42', 'notes', 2);

        expect(http.get).toHaveBeenCalledWith('/inventory-alerts/42/notes', {
            params: { include: 'created_by', 'filter[trashed]': 'only', per_page: 10, page: 2 },
        });
    });

    it('requests archived item photos with pagination and their uploader', async () => {
        vi.mocked(http.get).mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never);

        await getArchivedChildren('items', '7', 'item-images', 1);

        expect(http.get).toHaveBeenCalledWith('/items/7/images', {
            params: { include: 'uploaded_by', 'filter[trashed]': 'only', per_page: 10, page: 1 },
        });
    });

    it('restores and permanently deletes the selected child resource', async () => {
        await restoreArchivedChild('notes', 'note-4');
        await permanentlyDeleteArchivedChild('item-images', 'image-5');

        expect(http.post).toHaveBeenCalledWith('/notes/note-4/restore');
        expect(http.delete).toHaveBeenCalledWith('/item-images/image-5/permanently');
    });
});
