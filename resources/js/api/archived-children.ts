import { http } from '../lib/http';
import type { NoteContextType, NotePage } from './notes';
import type { ItemImage } from './item-images';

export type ArchivedChildKind = 'notes' | 'item-images';
export type ArchivedItemImagePage = {
    data: ItemImage[];
    meta: { current_page: number; last_page: number; total: number };
};
export type ArchivedChildPage = NotePage | ArchivedItemImagePage;

export async function getArchivedChildren(
    contextType: NoteContextType,
    contextId: string,
    kind: ArchivedChildKind,
    page: number,
): Promise<ArchivedChildPage> {
    const isNote = kind === 'notes';
    const url = isNote
        ? `/${contextType}/${contextId}/notes`
        : `/items/${contextId}/images`;
    const include = isNote ? 'created_by' : 'uploaded_by';
    const response = await http.get<ArchivedChildPage>(url, {
        params: { include, 'filter[trashed]': 'only', per_page: 10, page },
    });

    return response.data;
}

export async function restoreArchivedChild(kind: ArchivedChildKind, id: string): Promise<void> {
    const collection = kind === 'notes' ? 'notes' : 'item-images';
    await http.post(`/${collection}/${id}/restore`);
}

export async function permanentlyDeleteArchivedChild(kind: ArchivedChildKind, id: string): Promise<void> {
    const collection = kind === 'notes' ? 'notes' : 'item-images';
    await http.delete(`/${collection}/${id}/permanently`);
}
