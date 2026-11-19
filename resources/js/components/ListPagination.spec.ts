import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ListPagination from './ListPagination.vue';

describe('ListPagination', () => {
    it('uses compact labelled arrow controls and closes the page jump popup after submitting', async () => {
        const wrapper = mount(ListPagination, {
            props: {
                meta: { current_page: 2, last_page: 4, total: 40 },
                page: 2,
            },
        });

        expect(wrapper.get('button[aria-label="Previous"]').text()).toContain('←');
        expect(wrapper.get('button[aria-label="Next"]').text()).toContain('→');

        const popup = wrapper.get('details');
        popup.element.setAttribute('open', '');
        await wrapper.get('input[type="number"]').setValue('4');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('change')).toEqual([[4]]);
        expect(popup.attributes('open')).toBeUndefined();
    });
});
