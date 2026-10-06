import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

/*
 * Moderating an account from the record the administrator is already editing.
 *
 * The hard part of this screen is that two things want the same click. Approve/Reject is a POST that the
 * unsaved guard's router hook cannot see (it only intercepts GETs), so the page has to route it through
 * `guard()` by hand. If it ever stopped doing that, an administrator who had half-typed a new email address
 * would approve the account and silently lose the edit — the account is moderated, the record is not saved,
 * and nothing anywhere says so. So: the unsaved prompt comes first, the approval confirm second, and nothing
 * is posted until both have been answered.
 */
const post = vi.fn();
const put = vi.fn();
const formPost = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ name: 'Head', setup: () => () => null }),
    Link: defineComponent({
        name: 'Link',
        setup:
            (_props, { slots }) =>
            () =>
                h('a', slots.default?.()),
    }),
    router: {
        post: (...args: unknown[]) => post(...args),
        visit: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({
        props: {
            locales: { en: { name: 'English', dir: 'ltr' } },
            auth: {
                permissions: ['admin.users.manage', 'admin.users.approve'],
            },
        },
    }),
    /**
     * Enough of Inertia's form helper for this page: the fields it binds, the dirty flag the guard reads, and
     * the three bookkeeping calls `load()` makes when the record changes.
     */
    useForm: (initial: Record<string, unknown>) => {
        let defaults = { ...initial };
        const form: Record<string, unknown> = reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            isDirty: false,
            defaults(next: Record<string, unknown>) {
                defaults = { ...next };
            },
            reset() {
                Object.assign(form, defaults);
                form.isDirty = false;
            },
            clearErrors() {
                form.errors = {};
            },
            post: (...args: unknown[]) => formPost(...args),
            put: (...args: unknown[]) => put(...args),
        });

        return form;
    },
}));

vi.mock('@/components/forms/DataGrid.vue', () => ({
    default: defineComponent({
        name: 'DataGrid',
        props: { rows: { type: Array, default: () => [] } },
        setup: (props) => () =>
            h('div', { 'data-testid': 'grid' }, String(props.rows.length)),
    }),
}));

vi.mock('@/components/forms/FormsToolbar.vue', () => ({
    default: defineComponent({ name: 'FormsToolbar', setup: () => () => null }),
}));

/** Each dialog answers through the buttons it renders, so the page's own wiring is what is under test. */
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
    dialogStub('ReasonDialog', 'reason-dialog', 'Left the company'),
);

/** The save/discard/cancel prompt, with a button per answer. */
vi.mock('@/components/forms/ConfirmSaveDialog.vue', () => ({
    default: defineComponent({
        name: 'ConfirmSaveDialog',
        props: { open: { type: Boolean, default: false } },
        emits: ['choose'],
        setup:
            (props, { emit }) =>
            () =>
                props.open
                    ? h('div', { 'data-testid': 'unsaved-dialog' }, [
                          h(
                              'button',
                              {
                                  'data-testid': 'unsaved-save',
                                  onClick: () => emit('choose', 'save'),
                              },
                              'save',
                          ),
                          h(
                              'button',
                              {
                                  'data-testid': 'unsaved-discard',
                                  onClick: () => emit('choose', 'discard'),
                              },
                              'discard',
                          ),
                          h(
                              'button',
                              {
                                  'data-testid': 'unsaved-cancel',
                                  onClick: () => emit('choose', 'cancel'),
                              },
                              'cancel',
                          ),
                      ])
                    : null,
    }),
}));

type UserRow = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    locale: string;
    roles: string[];
    department_ids: number[];
    approval_status: string;
    approved_at: string | null;
    approved_by: string | null;
    rejected_reason: string | null;
    email_verified: boolean;
    registered_at: string | null;
};

function user(overrides: Partial<UserRow> = {}): UserRow {
    return {
        id: 12,
        name: 'Asif Mehmood',
        email: 'asif.mehmood@signup.test',
        is_active: true,
        locale: 'en',
        roles: ['ppc'],
        department_ids: [],
        approval_status: 'pending',
        approved_at: null,
        approved_by: null,
        rejected_reason: null,
        email_verified: true,
        registered_at: '2026-09-23T09:15:00+05:00',
        ...overrides,
    };
}

async function mountUsers(rows: UserRow[] = [user()]) {
    const { default: Users } = await import('@/pages/admin/Users.vue');
    const wrapper = mount(Users, {
        attachTo: document.body,
        props: {
            users: rows,
            roles: ['ppc', 'manager'],
            departments: [{ value: 1, label: 'Dyeing' }],
        },
    });
    await flushPromises();

    return wrapper;
}

/** The page's own form object, so a test can make the record dirty the way typing would. */
function formOf(wrapper: { vm: unknown }): { isDirty: boolean } {
    return (wrapper.vm as unknown as { form: { isDirty: boolean } }).form;
}

beforeEach(() => {
    post.mockClear();
    put.mockClear();
    formPost.mockClear();
});
afterEach(() => {
    document.body.innerHTML = '';
});

describe('Users screen — moderating the record being edited', () => {
    it('asks about the unsaved record before it asks about the approval', async () => {
        const wrapper = await mountUsers();
        formOf(wrapper).isDirty = true;
        await flushPromises();

        await wrapper.get('[data-test="approve-selected"]').trigger('click');
        await flushPromises();

        // The unsaved prompt first, and the approval confirm not yet raised at all.
        expect(wrapper.find('[data-testid="unsaved-dialog"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(
            false,
        );
        expect(post).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('posts nothing at all when the unsaved prompt is cancelled', async () => {
        const wrapper = await mountUsers();
        formOf(wrapper).isDirty = true;
        await flushPromises();

        await wrapper.get('[data-test="approve-selected"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="unsaved-cancel"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(
            false,
        );
        expect(post).not.toHaveBeenCalled();
        expect(put).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('raises the approval confirm only after the edit is discarded, and still confirms', async () => {
        const wrapper = await mountUsers();
        formOf(wrapper).isDirty = true;
        await flushPromises();

        await wrapper.get('[data-test="approve-selected"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="unsaved-discard"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(
            true,
        );
        expect(post).not.toHaveBeenCalled();

        await wrapper.get('[data-testid="confirm-dialog-ok"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/admin/users/12/approve');
        wrapper.unmount();
    });

    it('goes straight to the approval confirm when nothing is unsaved', async () => {
        const wrapper = await mountUsers();

        await wrapper.get('[data-test="approve-selected"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="unsaved-dialog"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(
            true,
        );
        wrapper.unmount();
    });

    it('never rejects without a reason, and guards that path too', async () => {
        const wrapper = await mountUsers();
        formOf(wrapper).isDirty = true;
        await flushPromises();

        await wrapper.get('[data-test="reject-selected"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="reason-dialog"]').exists()).toBe(
            false,
        );

        await wrapper.get('[data-testid="unsaved-discard"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="reason-dialog"]').exists()).toBe(
            true,
        );
        expect(post).not.toHaveBeenCalled();

        await wrapper.get('[data-testid="reason-dialog-ok"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/admin/users/12/reject');
        expect(post.mock.calls[0][1]).toEqual({
            rejected_reason: 'Left the company',
        });
        wrapper.unmount();
    });
});

describe('Users screen — which decisions are offered', () => {
    it('offers no Approve on an account that is already approved', async () => {
        const wrapper = await mountUsers([
            user({ approval_status: 'approved' }),
        ]);

        expect(wrapper.find('[data-test="approve-selected"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-test="reject-selected"]').exists()).toBe(
            true,
        );
        wrapper.unmount();
    });

    it('offers no Reject on an account that is already rejected', async () => {
        const wrapper = await mountUsers([
            user({ approval_status: 'rejected', rejected_reason: 'No badge' }),
        ]);

        expect(wrapper.find('[data-test="reject-selected"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-test="approve-selected"]').exists()).toBe(
            true,
        );
        expect(wrapper.text()).toContain('No badge');
        wrapper.unmount();
    });

    it('offers both on an account still waiting', async () => {
        const wrapper = await mountUsers();

        expect(wrapper.find('[data-test="approve-selected"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('[data-test="reject-selected"]').exists()).toBe(
            true,
        );
        wrapper.unmount();
    });
});
