import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

/*
 * The queue's one hard rule: a rejection cannot be sent without a reason, because the endpoint refuses one
 * without it. So rejecting must open the reason dialog and post nothing until it comes back with a value —
 * cancelling has to leave the account exactly where it was.
 */
const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ name: 'Head', setup: () => () => null }),
    Link: defineComponent({
        name: 'Link',
        setup:
            (_props, { slots }) =>
            () =>
                h('a', slots.default?.()),
    }),
    router: { post: (...args: unknown[]) => post(...args) },
    usePage: () => ({
        props: { auth: { permissions: ['admin.users.manage'] } },
    }),
}));

/** Each dialog stub answers through the buttons it renders, so the page's own wiring is what is under test. */
function dialogStub(name: string, testid: string, confirmWith?: string) {
    return {
        default: defineComponent({
            name,
            props: { open: { type: Boolean, default: false } },
            emits: ['confirm', 'cancel'],
            setup:
                (props, { emit }) =>
                () =>
                    props.open
                        ? h('div', { 'data-testid': testid }, [
                              h(
                                  'button',
                                  {
                                      'data-testid': `${testid}-ok`,
                                      onClick: () =>
                                          confirmWith === undefined
                                              ? emit('confirm')
                                              : emit('confirm', confirmWith),
                                  },
                                  'ok',
                              ),
                              h(
                                  'button',
                                  {
                                      'data-testid': `${testid}-cancel`,
                                      onClick: () => emit('cancel'),
                                  },
                                  'cancel',
                              ),
                          ])
                        : null,
        }),
    };
}

vi.mock('@/components/forms/ConfirmDialog.vue', () =>
    dialogStub('ConfirmDialog', 'confirm-dialog'),
);
vi.mock('@/components/forms/ReasonDialog.vue', () =>
    dialogStub('ReasonDialog', 'reason-dialog', 'Not a mill employee'),
);

const pending = {
    id: 12,
    name: 'Asif Mehmood',
    email: 'asif.mehmood@signup.test',
    email_verified: true,
    registered_at: '2026-09-23T09:15:00+05:00',
    ip_address: '10.20.30.41',
    computer_name: 'PPC-OFFICE-01',
};

async function mountQueue(rows = [pending]) {
    const { default: Approvals } = await import('@/pages/admin/Approvals.vue');
    const wrapper = mount(Approvals, {
        attachTo: document.body,
        props: { pending: rows },
    });
    await flushPromises();
    return wrapper;
}

beforeEach(() => post.mockClear());
afterEach(() => {
    document.body.innerHTML = '';
});

describe('Approvals queue', () => {
    it('never posts a rejection without a reason', async () => {
        const wrapper = await mountQueue();

        await wrapper.get('[data-test="reject-12"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="reason-dialog"]').exists()).toBe(
            true,
        );
        expect(post).not.toHaveBeenCalled();

        await wrapper
            .get('[data-testid="reason-dialog-cancel"]')
            .trigger('click');
        await flushPromises();
        expect(post).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('posts the reason once the dialog is answered', async () => {
        const wrapper = await mountQueue();

        await wrapper.get('[data-test="reject-12"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="reason-dialog-ok"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/admin/users/12/reject');
        expect(post.mock.calls[0][1]).toEqual({
            rejected_reason: 'Not a mill employee',
        });
        wrapper.unmount();
    });

    it('confirms before approving, and posts nothing when the answer is no', async () => {
        const wrapper = await mountQueue();

        await wrapper.get('[data-test="approve-12"]').trigger('click');
        await flushPromises();
        expect(post).not.toHaveBeenCalled();

        await wrapper
            .get('[data-testid="confirm-dialog-cancel"]')
            .trigger('click');
        await flushPromises();
        expect(post).not.toHaveBeenCalled();

        await wrapper.get('[data-test="approve-12"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="confirm-dialog-ok"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/admin/users/12/approve');
        wrapper.unmount();
    });

    it('says so plainly when nothing is waiting', async () => {
        const wrapper = await mountQueue([]);

        expect(wrapper.text()).toContain('approval.queue.empty');
        expect(wrapper.find('[data-test="approve-12"]').exists()).toBe(false);
        wrapper.unmount();
    });
});
