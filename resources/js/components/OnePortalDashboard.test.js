import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import OnePortalDashboard from './OnePortalDashboard.vue';
import { useDashboardStore } from '../stores/dashboard';

const mountDashboard = async (payload) => {
    const responses = [{
        ok: true,
        json: async () => ({ status: true, message: 'Success', data: [] }),
    }, {
        ok: true,
        json: async () => ({ status: true, message: 'Success', data: payload }),
    }];

    if (payload.user?.role === 'super_admin') {
        responses.push({
            ok: true,
            json: async () => ({
                status: true,
                message: 'Success',
                data: {
                    departments: [{ id: 1, name: 'Information Technology', users_count: 3 }],
                    users: [],
                    serviceProviders: [{ id: 1, name: 'AMIS', status: 'healthy' }],
                    auditLogs: [{ id: 1, action: 'service_provider.created' }],
                    advertisements: [],
                },
            }),
        });
    }

    global.fetch = vi.fn()
    responses.forEach((response) => global.fetch.mockResolvedValueOnce(response));

    const wrapper = mount(OnePortalDashboard, {
        global: {
            plugins: [createPinia()],
        },
    });

    await flushPromises();

    return wrapper;
};

const mountUnauthenticatedDashboard = async (advertisements = []) => {
    global.fetch = vi.fn()
        .mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ status: true, message: 'Success', data: advertisements }),
        })
        .mockResolvedValueOnce({
            ok: false,
            status: 401,
            json: async () => ({ message: 'Unauthenticated.' }),
        });

    const wrapper = mount(OnePortalDashboard, {
        global: {
            plugins: [createPinia()],
        },
    });

    await flushPromises();

    return wrapper;
};

describe('OnePortalDashboard', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.restoreAllMocks();
        delete window.createOnePortalEcho;
    });

    it('renders Super Admin navigation and all active portals', async () => {
        const wrapper = await mountDashboard({
            user: { name: 'Super Admin', role: 'super_admin', department: null },
            navigation: ['Dashboard', 'Units & Departments', 'Integration Management', 'Logs', 'User Access'],
            stats: { integratedSystems: 2, activeDepartments: 1, totalUsers: 12 },
            portals: [
                { id: 1, name: 'AMIS', launch_url: '/sso/amis', status: 'healthy' },
                { id: 2, name: 'HRIS', launch_url: '/sso/hris', status: 'healthy' },
            ],
            news: [{ id: 1, title: 'System Maintenance Advisory', body: 'Maintenance window.' }],
        });

        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Units & Departments');
        expect(wrapper.text()).toContain('Integration Management');
        expect(wrapper.text()).toContain('All active systems');
        expect(wrapper.text()).toContain('AMIS');
        expect(wrapper.text()).toContain('System Maintenance Advisory');

        await wrapper.findAll('button').find((button) => button.text() === 'Units & Departments').trigger('click');
        expect(wrapper.text()).toContain('Create and review organization departments');
        expect(wrapper.text()).toContain('Information Technology');

        await wrapper.findAll('button').find((button) => button.text() === 'Integration Management').trigger('click');
        expect(wrapper.text()).toContain('Download Metadata');
    });

    it('renders Department Admin scoped navigation without Super Admin modules', async () => {
        const wrapper = await mountDashboard({
            user: {
                name: 'Dept Admin',
                role: 'department_admin',
                department: { id: 1, name: 'Information Technology' },
            },
            navigation: ['Dashboard', 'Department Users', 'News Feed', 'Notifications'],
            stats: { departmentUsers: 8, activePortals: 1 },
            portals: [{ id: 1, name: 'HRIS', launch_url: '/sso/hris', status: 'healthy' }],
            news: [{ id: 1, title: 'Department Enrollment', body: 'A new user joined.' }],
            departments: [
                { id: 1, name: 'Information Technology', code: 'IT' },
                { id: 2, name: 'Human Resources', code: 'HR' },
            ],
        });

        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Department Users');
        expect(wrapper.text()).toContain('Notifications');
        expect(wrapper.text()).not.toContain('Integration Management');
        expect(wrapper.text()).not.toContain('Logs');
        expect(wrapper.text()).toContain('Information Technology');

        await wrapper.findAll('button').find((button) => button.text() === 'News Feed').trigger('click');
        expect(wrapper.text()).toContain('Publish News');
        expect(wrapper.text()).toContain('Human Resources');
    });

    it('renders Standard User applications and excludes admin navigation', async () => {
        const wrapper = await mountDashboard({
            user: {
                name: 'Jane User',
                role: 'user',
                department: { id: 1, name: 'Library Services' },
            },
            navigation: ['My Dashboard', 'My Applications', 'News Feed', 'Recent Activity'],
            stats: { allowedPortals: 1, unreadNews: 2 },
            portals: [{ id: 1, name: 'Library', launch_url: '/sso/library', status: 'healthy' }],
            news: [{ id: 1, title: 'Password Security', body: 'Review the policy.' }],
        });

        expect(wrapper.text()).toContain('My Dashboard');
        expect(wrapper.text()).toContain('My Applications');
        expect(wrapper.text()).toContain('Library');
        expect(wrapper.text()).not.toContain('User Management');
        expect(wrapper.text()).not.toContain('User Access');

        await wrapper.findAll('button').find((button) => button.text() === 'My Applications').trigger('click');
        expect(wrapper.text()).toContain('One-click portal launcher');
    });

    it('renders a credential login form when the dashboard API is unauthenticated', async () => {
        const wrapper = await mountUnauthenticatedDashboard();

        expect(wrapper.text()).toContain('One secure login for all your connected systems');
        expect(wrapper.text()).toContain('Sign in to your OnePortal account');
        expect(wrapper.find('input[type="email"]').exists()).toBe(true);
        expect(wrapper.find('input[type="password"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Unable to load dashboard.');
    });

    it('rotates multiple active advertisements as a login banner', async () => {
        vi.useFakeTimers();

        try {
            const wrapper = await mountUnauthenticatedDashboard([
                {
                    id: 1,
                    title: 'Enrollment Week',
                    body: 'Complete your portal updates.',
                    media_url: '/advertisements/enrollment.jpg',
                    media_type: 'image',
                    is_forever: true,
                },
                {
                    id: 2,
                    title: 'Campus Fair',
                    body: 'Visit the booths this Friday.',
                    media_url: '/advertisements/fair.jpg',
                    media_type: 'image',
                    is_forever: true,
                },
            ]);

        expect(wrapper.text()).toContain('Enrollment Week');
        expect(wrapper.text()).toContain('1 / 2');
        expect(wrapper.find('[aria-label="Advertisement banner controls"]').exists()).toBe(true);
        expect(wrapper.find('.ad-banner-slide').exists()).toBe(true);

            await wrapper.find('button[aria-label="Next advertisement"]').trigger('click');
            expect(wrapper.text()).toContain('Campus Fair');
            expect(wrapper.text()).toContain('2 / 2');

            await wrapper.find('button[aria-label="Previous advertisement"]').trigger('click');
            expect(wrapper.text()).toContain('Enrollment Week');

            vi.advanceTimersByTime(10000);
            await wrapper.vm.$nextTick();
            expect(wrapper.text()).toContain('Campus Fair');
        } finally {
            vi.useRealTimers();
        }
    });

    it('opens public legal information pages from the login footer', async () => {
        const wrapper = await mountUnauthenticatedDashboard();

        await wrapper.findAll('a').find((link) => link.text() === 'Privacy Policy').trigger('click');
        expect(wrapper.text()).toContain('How OnePortal collects, uses, protects, and retains');
        expect(wrapper.text()).toContain('Information We Process');

        await wrapper.find('button.legal-back').trigger('click');
        expect(wrapper.text()).toContain('Sign in to your OnePortal account');
    });

    it('submits login credentials through the session endpoint', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ status: true, message: 'Success', data: [] }),
            })
            .mockResolvedValueOnce({
                ok: false,
                status: 401,
                json: async () => ({ message: 'Unauthenticated.' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ status: true, message: 'Authenticated.', data: { user: { email: 'standard.user@oneportal.test' } } }),
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({
                    status: true,
                    message: 'Success',
                    data: {
                        user: { name: 'Standard User', role: 'user', department: { name: 'Information Technology' } },
                        navigation: ['My Dashboard', 'My Applications', 'News Feed', 'Recent Activity'],
                        stats: { allowedPortals: 1, unreadNews: 1 },
                        portals: [{ id: 1, name: 'AMIS', launch_url: '/sso/amis', status: 'healthy' }],
                        news: [{ id: 1, title: 'System Maintenance Advisory', body: 'Maintenance window.' }],
                    },
                }),
            });

        const wrapper = mount(OnePortalDashboard, {
            global: {
                plugins: [createPinia()],
            },
        });

        await flushPromises();
        await wrapper.find('input[type="email"]').setValue('standard.user@oneportal.test');
        await wrapper.find('input[type="password"]').setValue('password');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledWith('/login', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({
                email: 'standard.user@oneportal.test',
                password: 'password',
                remember: false,
            }),
        }));
        expect(wrapper.text()).toContain('Welcome back, Standard User');
        expect(wrapper.text()).toContain('AMIS');
    });

    it('subscribes to authenticated private Reverb channels and updates notifications from events', async () => {
        const listeners = {};
        const privateChannel = {
            listen: vi.fn((event, callback) => {
                listeners[event] = callback;
                return privateChannel;
            }),
        };
        const echo = {
            private: vi.fn(() => privateChannel),
            disconnect: vi.fn(),
        };
        window.createOnePortalEcho = vi.fn(() => echo);

        const wrapper = await mountDashboard({
            user: {
                id: 10,
                name: 'Dept Admin',
                role: 'department_admin',
                department: { id: 4, name: 'Information Technology' },
            },
            navigation: ['Dashboard', 'Department Users', 'News Feed', 'Notifications'],
            stats: { departmentUsers: 8, activePortals: 1, unreadNotifications: 0 },
            portals: [{ id: 1, name: 'HRIS', launch_url: '/sso/hris', status: 'healthy' }],
            news: [],
            notifications: [],
        });

        expect(window.createOnePortalEcho).toHaveBeenCalled();
        expect(echo.private).toHaveBeenCalledWith('user.10');
        expect(echo.private).toHaveBeenCalledWith('department.4');
        expect(privateChannel.listen).toHaveBeenCalledWith('.oneportal.news-published', expect.any(Function));

        global.fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                status: true,
                message: 'Success',
                data: {
                    user: {
                        id: 10,
                        name: 'Dept Admin',
                        role: 'department_admin',
                        department: { id: 4, name: 'Information Technology' },
                    },
                    navigation: ['Dashboard', 'Department Users', 'News Feed', 'Notifications'],
                    stats: { departmentUsers: 8, activePortals: 1, unreadNotifications: 1 },
                    portals: [],
                    news: [{ id: 9, title: 'Enrollment Update', body: 'A new user joined.' }],
                    notifications: [{ id: 'n1', title: 'News published', message: 'Enrollment Update', target_id: 9, read_at: null }],
                },
            }),
        });

        const newsEvent = {
            kind: 'news_post.published',
            id: 9,
            title: 'Enrollment Update',
            excerpt: 'A new user joined.',
            published_at: '2026-06-27T00:00:00.000Z',
        };

        listeners['.oneportal.news-published'](newsEvent);
        listeners['.oneportal.news-published'](newsEvent);
        await flushPromises();

        const store = useDashboardStore();
        expect(store.dashboard.news.filter((post) => post.id === 9)).toHaveLength(1);
        expect(store.dashboard.notifications.filter((notification) => notification.target_id === 9)).toHaveLength(1);

        await wrapper.findAll('button').find((button) => button.text() === 'News Feed').trigger('click');
        expect(wrapper.text()).toContain('Enrollment Update');
        expect(wrapper.text()).toContain('A new user joined.');

        await wrapper.findAll('button').find((button) => button.text().includes('Notifications')).trigger('click');
        expect(wrapper.text()).toContain('News published');
        expect(wrapper.text()).toContain('Enrollment Update');
    });

    it('disconnects Echo on logout', async () => {
        const privateChannel = { listen: vi.fn(() => privateChannel) };
        const echo = { private: vi.fn(() => privateChannel), disconnect: vi.fn() };
        window.createOnePortalEcho = vi.fn(() => echo);

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ status: true, message: 'Success', data: { token: 'new-token' } }),
        });

        const store = useDashboardStore();
        store.dashboard = {
            user: { id: 3, name: 'Standard User', role: 'user', department: { id: 1, name: 'IT' } },
            navigation: ['My Dashboard', 'My Applications', 'News Feed', 'Recent Activity'],
            stats: {},
            portals: [],
            news: [],
            notifications: [],
        };

        store.connectRealtime();
        expect(store.realtime.connected).toBe(true);

        await store.logout();
        expect(echo.disconnect).toHaveBeenCalled();
        expect(store.realtime.connected).toBe(false);
    });
});
