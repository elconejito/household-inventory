import { http } from '../lib/http';

export type ItemImage = {
    type: 'item-images';
    id: string;
    caption: string | null;
    is_primary: boolean;
    uploaded_at: string;
    thumbnail_url: string;
    display_url: string;
    thumbnail_width: number;
    thumbnail_height: number;
    display_width: number;
    display_height: number;
    thumbnail_mime_type: string;
    display_mime_type: string;
    uploaded_by?: { type: 'users'; id: string; name: string } | null;
};
type ItemImagePage = { data: ItemImage[]; meta?: { last_page: number } };
type ItemImageResource = { data: ItemImage };

export async function getItemImages(itemId: string): Promise<ItemImage[]> {
    const firstPage = await http.get<ItemImagePage>(`/items/${itemId}/images`, { params: { include: 'uploaded_by', per_page: 100, page: 1 } });
    const otherPages = await Promise.all(Array.from({ length: Math.max(0, (firstPage.data.meta?.last_page ?? 1) - 1) }, (_, index) =>
        http.get<ItemImagePage>(`/items/${itemId}/images`, { params: { include: 'uploaded_by', per_page: 100, page: index + 2 } }),
    ));
    return [...firstPage.data.data, ...otherPages.flatMap((response) => response.data.data)];
}

export async function uploadItemImage(itemId: string, file: File, caption: string): Promise<ItemImage> {
    const form = new FormData();
    form.append('image', file);
    if (caption.trim()) form.append('data[caption]', caption.trim());
    const response = await http.post<ItemImageResource>(`/items/${itemId}/images`, form);
    return response.data.data;
}

export async function updateItemImage(id: string, changes: { caption?: string; is_primary?: true }): Promise<ItemImage> {
    const response = await http.patch<ItemImageResource>(`/item-images/${id}`, { data: changes });
    return response.data.data;
}

export async function deleteItemImage(id: string): Promise<void> {
    await http.delete(`/item-images/${id}`);
}
