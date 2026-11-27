import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import type { InventoryItem } from '../api/items';
import { http } from '../lib/http';
import ShelfSessionPage from './ShelfSessionPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }));

const shelf = { id: '10', name: 'Shelf', description: null };
const closet = { id: '11', name: 'Closet', description: null };
const paper = { id: '2', name: 'Paper Towels', counting_unit: 'roll', counting_unit_plural: 'rolls', description: null };
const tape = { id: '3', name: 'Tape', counting_unit: 'pack', counting_unit_plural: 'packs', description: null };


describe('shelf sessions', () => {
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount>;
    let balances: Map<string, number>;
    let items: InventoryItem[];

    beforeEach(() => {
        vi.clearAllMocks();
        items = [paper, tape];
        balances = new Map([['2', 12], ['3', 1]]);
        client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        vi.mocked(http.get).mockImplementation(async (url) => {
            const collection = (data: unknown[]) => ({ data: { data, meta: { last_page: 1 } } });
            if (url === '/locations') return collection([shelf, closet]);
            if (url === '/locations/10') return { data: { data: shelf } };
            if (url === '/items') return collection(items);
            const levels = items.map((item) => ({ id: 'level-' + item.id, quantity: balances.get(item.id), location: shelf, item }));
            if (url === '/inventory-levels') return collection(levels.reverse());
            const item = items.find((candidate) => url === '/items/' + candidate.id);
            if (item) return { data: { data: { ...item, inventory_levels: [...levels.filter((level) => level.item === item), { quantity: 17, location: closet }] } } };
            throw new Error(url);
        });
        vi.mocked(http.put).mockImplementation(async (url, body) => {
            const id = url.split('/')[4]!;
            const old = balances.get(id);
            const quantity = (body as { data: { observed_quantity: number } }).data.observed_quantity;
            balances.set(id, quantity);
            return { data: { data: { quantity }, meta: { previous_quantity: old } } };
        });
    });

    afterEach(() => {
        wrapper?.unmount();
        client.clear();
    });

    async function open(mode: 'intake' | 'count') {
        const router = createRouter({ history: createMemoryHistory(), routes: [
            { path: '/locations/:location/intake', name: 'location-intake', component: ShelfSessionPage },
            { path: '/locations/:location/count', name: 'location-count', component: ShelfSessionPage },
            { path: '/locations/:location', name: 'location-detail', component: { template: '<p>Shelf details</p>' } },
            { path: '/locations', name: 'catalog-locations', component: { template: '<p>Locations</p>' } },
        ] });
        await router.push('/locations/10/' + mode);
        wrapper = mount(RouterView, { global: { plugins: [router, [VueQueryPlugin, { queryClient: client }]] } });
        await flushPromises();
        return router;
    }

    async function click(text: string) {
        const button = wrapper.findAll('button').find((candidate) => candidate.text() === text);
        expect(button, text).toBeDefined();
        await button!.trigger('click');
        if (button!.attributes('type') === 'submit') await wrapper.get('form[id]').trigger('submit');
        await flushPromises();
    }

    it('retains the selected shelf while recording existing stock and creating a new zero-quantity item', async () => {
        const router = await open('intake');
        await wrapper.get('input[type="search"]').setValue('paper');
        await click('Paper TowelsCounted in rolls');
        await wrapper.get('input[type="number"][inputmode="numeric"]').setValue(14);
        expect(wrapper.text()).toContain('Closet keeps its 17 rolls.');
        await click('Save & add another');
        expect(http.put).toHaveBeenCalledWith('/locations/10/items/2/count', { data: { observed_quantity: 14 } });
        expect(wrapper.get('[role="status"]').text()).toContain('Saved Paper Towels · 14 rolls');
        await wrapper.get('input[type="search"]').setValue('Freezer Labels');
        await click('Create “Freezer Labels”');
        await wrapper.get('input[placeholder="e.g. pack"]').setValue('pack');
        await wrapper.get('input[type="number"][inputmode="numeric"]').setValue('0');
        vi.mocked(http.post).mockResolvedValue({ data: { data: { ...tape, id: '4', name: 'Freezer Labels' } } });
        await click('Save & finish');
        expect(http.post).toHaveBeenCalledWith('/items', { data: { name: 'Freezer Labels', counting_unit: 'pack', description: null, opening_stock: { location_id: '10', quantity: 0 } } });
        const result = wrapper.get('dialog');
        expect(result.text()).toContain('2 items recorded');
        expect(result.text()).toContain('Created and tracked at zero');
        await click('Done');
        expect(router.currentRoute.value.fullPath).toBe('/locations/10');
    });

    it('holds the current count after failure, advances in shelf order on success and summarizes skipped items', async () => {
        await open('count');
        expect(wrapper.get('h3').text()).toBe('Paper Towels');
        expect(wrapper.get('button[type="submit"][form]').attributes('disabled')).toBeDefined();
        await wrapper.get('input[type="number"][inputmode="numeric"]').setValue(0);
        vi.mocked(http.put).mockRejectedValueOnce(new Error('offline'));
        await click('Save & next');
        expect(wrapper.get('[role="alert"]').text()).toContain('try again');
        expect(wrapper.get('h3').text()).toBe('Paper Towels');
        expect((wrapper.get('input[type="number"][inputmode="numeric"]').element as HTMLInputElement).value).toBe('0');
        await click('Save & next');
        expect(wrapper.get('h3').text()).toBe('Tape');
        expect(wrapper.get('[role="status"]').text()).toContain('0 rolls');
        await click('Review previous');
        expect(wrapper.get('h3').text()).toBe('Paper Towels');
        await click('Save & next');
        await click('Skip item');
        expect(wrapper.get('dialog').text()).toContain('1 item counted at this shelf. 1 skipped. 0 remaining.');
        expect(wrapper.get('dialog').text()).toContain('TapeSkipped');
        expect(http.put).not.toHaveBeenCalledWith('/locations/10/items/3/count', expect.anything());
    });
    it('saves a typed count when finishing before the end of the queue', async () => {
        await open('count');
        await wrapper.get('input[type="number"][inputmode="numeric"]').setValue(9);
        await click('Save & finish');
        expect(http.put).toHaveBeenCalledWith('/locations/10/items/2/count', { data: { observed_quantity: 9 } });
        expect(wrapper.get('dialog').text()).toContain('1 item counted at this shelf. 0 skipped. 1 remaining.');
    });

    it('follows the physical count across pages and allows reviewing the entire receipt', async () => {
        items = Array.from({ length: 12 }, (_, index) => ({ ...tape, name: 'Box ' + (index + 1), id: String(index + 1) }));
        for (const item of items) balances.set(item.id, 1);
        await open('count');
        const order = wrapper.get('aside');
        expect(order.findAll('li')).toHaveLength(10);
        await order.get('button[aria-label="Next"]').trigger('click');
        expect(order.findAll('li')).toHaveLength(2);
        expect(wrapper.get('h3').text()).toBe('Box 1');
        for (let index = 0; index < 10; index++) await click('Skip item');
        expect(wrapper.get('h3').text()).toBe('Box 11');
        expect(order.get('[aria-current="step"]').text()).toContain('Box 11');
        await click('Skip item');
        await click('Skip item');
        const summary = wrapper.get('dialog');
        expect(summary.text()).toContain('12 skipped');
        expect(summary.findAll('li')).toHaveLength(10);
        await summary.get('button[aria-label="Next"]').trigger('click');
        expect(summary.findAll('li')).toHaveLength(2);
        expect(summary.findAll('li')[1]!.text()).toContain('Box 12');
    });

    it('lets an item beyond the first page of matches be selected', async () => {
        items = Array.from({ length: 14 }, (_, index) => ({ ...tape, name: 'Tape ' + (index + 1), id: String(index + 1) }));
        await open('intake');
        await wrapper.get('input[type="search"]').setValue('Tape');
        await wrapper.get('nav[aria-label="Matching items pagination"] button[aria-label="Next"]').trigger('click');
        await click('Tape 14Counted in packs');
        expect(wrapper.get('form[id]').text()).toContain('Counted on this shelf');
        expect(wrapper.text()).toContain('Tape 14Existing item');
    });

});
