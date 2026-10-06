import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

/*
 * The dead end an unapproved account lands in (decision A4), and the only screen `EnsureUserIsApproved` lets
 * it reach. It shows exactly one of three states, and the order they outrank each other in is the whole
 * screen: a rejected account whose email address is also unconfirmed must be told it was rejected, not invited
 * to confirm an address — confirming it would change nothing, and offering the link would be a lie about what
 * happens next. There is no link anywhere but logout, because every other route bounces straight back here.
 */
vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ name: 'Head', setup: () => () => null }),
    setLayoutProps: vi.fn(),
    /** Renders its slot with a non-processing scope, which is all this page asks of it. */
    Form: defineComponent({
        name: 'Form',
        setup:
            (_props, { slots }) =>
            () =>
                h('form', slots.default?.({ processing: false })),
    }),
}));

vi.mock('@/routes', () => ({ logout: { form: () => ({}) } }));
vi.mock('@/routes/verification', () => ({ send: { form: () => ({}) } }));

function render(overrides: Partial<Props> = {}) {
    return mount(PendingApproval, {
        props: {
            status: 'pending',
            rejected: false,
            rejectedReason: null,
            emailVerified: true,
            email: 'asif.mehmood@signup.test',
            ...overrides,
        },
    });
}

type Props = {
    status: string;
    rejected: boolean;
    rejectedReason: string | null;
    emailVerified: boolean;
    email: string;
};

const { default: PendingApproval } =
    await import('@/pages/auth/PendingApproval.vue');

afterEach(() => {
    document.body.innerHTML = '';
});

describe('PendingApproval', () => {
    it('waits on the moderator once the address is confirmed', () => {
        const wrapper = render();

        expect(wrapper.text()).toContain('approval.pending.title');
        expect(wrapper.text()).not.toContain('approval.verify.title');
        expect(
            wrapper.find('[data-test="resend-verification-button"]').exists(),
        ).toBe(false);
        wrapper.unmount();
    });

    it('asks for the address first while it is unconfirmed', () => {
        const wrapper = render({ emailVerified: false });

        expect(wrapper.text()).toContain('approval.verify.title');
        expect(
            wrapper.find('[data-test="resend-verification-button"]').exists(),
        ).toBe(true);
        wrapper.unmount();
    });

    it('lets rejection outrank an unconfirmed address', () => {
        const wrapper = render({
            rejected: true,
            emailVerified: false,
            rejectedReason: 'Not a mill employee',
        });

        expect(wrapper.text()).toContain('approval.rejected.title');
        expect(wrapper.text()).not.toContain('approval.verify.title');
        expect(wrapper.text()).not.toContain('approval.pending.title');
        // No resend: there is nothing left for confirming the address to unlock.
        expect(
            wrapper.find('[data-test="resend-verification-button"]').exists(),
        ).toBe(false);
        wrapper.unmount();
    });

    it('gives the reason and the way to ask about it', () => {
        const wrapper = render({
            rejected: true,
            rejectedReason: 'Not a mill employee',
        });

        expect(wrapper.text()).toContain('Not a mill employee');
        expect(wrapper.text()).toContain('approval.rejected.contact');
        wrapper.unmount();
    });

    it('still says it was rejected when no reason was recorded', () => {
        const wrapper = render({ rejected: true, rejectedReason: null });

        expect(wrapper.text()).toContain('approval.rejected.title');
        expect(wrapper.text()).not.toContain('approval.rejected.reason');
        wrapper.unmount();
    });

    it('always names the account and always offers the way out', () => {
        for (const props of [
            {},
            { emailVerified: false },
            { rejected: true, rejectedReason: 'No' },
        ]) {
            const wrapper = render(props);

            expect(wrapper.text()).toContain('asif.mehmood@signup.test');
            expect(
                wrapper.find('[data-test="pending-logout-button"]').exists(),
            ).toBe(true);
            wrapper.unmount();
        }
    });
});
