import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import FormModal from './FormModal.vue';

describe('FormModal', () => {
    it('opens a native modal and closes it on unmount', () => {
        const wrapper = mount(FormModal, { props: { label: 'Add item' }, slots: { default: '<form><input aria-label="Item name"></form>' } });
        const dialog = wrapper.get('dialog').element as HTMLDialogElement;
        expect(dialog.open).toBe(true);
        expect(dialog.showModal).toHaveBeenCalled();
        const close = vi.spyOn(dialog, 'close');
        wrapper.unmount();
        expect(close).toHaveBeenCalled();
    });

    it('requests dismissal on Escape and blocks dismissal while saving', async () => {
        const wrapper = mount(FormModal, { props: { label: 'Add item', busy: true } });
        await wrapper.get('dialog').trigger('cancel');
        expect(wrapper.emitted('close')).toBeUndefined();
        await wrapper.setProps({ busy: false });
        await wrapper.get('dialog').trigger('cancel');
        expect(wrapper.emitted('close')).toHaveLength(1);
        wrapper.unmount();
    });
});
