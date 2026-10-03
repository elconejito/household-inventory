import { http } from '../lib/http';
import type { ItemCategory, ItemLocation } from './items';

type Resource<T> = { data: T };

export async function getCategory(id: string): Promise<ItemCategory> {
    const response = await http.get<Resource<ItemCategory>>(`/categories/${id}`);
    return response.data.data;
}

export async function getLocation(id: string): Promise<ItemLocation> {
    const response = await http.get<Resource<ItemLocation>>(`/locations/${id}`, { params: { include: 'parent' } });
    return response.data.data;
}
