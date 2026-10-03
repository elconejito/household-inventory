import { http } from '../lib/http';

export type NoteAuthor = { type: 'users'; id: string; name: string };
export type Note = {
    type: 'notes';
    id: string;
    body: string;
    created_at: string;
    updated_at: string;
    is_edited: boolean;
    created_by: NoteAuthor | null;
};
export type NoteContextType = 'items' | 'categories' | 'locations' | 'inventory-movements' | 'inventory-alerts';
export type NotePage = { data: Note[]; meta: { current_page: number; last_page: number; total: number } };
type NoteResource = { data: Note };

export async function getNotes(type: NoteContextType, id: string, page: number): Promise<NotePage> {
    const response = await http.get<NotePage>(`/${type}/${id}/notes`, { params: { include: 'created_by', per_page: 10, page } });
    return response.data;
}

export async function createNote(type: NoteContextType, id: string, body: string): Promise<Note> {
    const response = await http.post<NoteResource>(`/${type}/${id}/notes`, { data: { body } });
    return response.data.data;
}

export async function updateNote(id: string, body: string): Promise<Note> {
    const response = await http.patch<NoteResource>(`/notes/${id}`, { data: { body } });
    return response.data.data;
}

export async function deleteNote(id: string): Promise<void> {
    await http.delete(`/notes/${id}`);
}
