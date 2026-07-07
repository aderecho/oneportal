import { defineStore } from 'pinia';

const emptyDashboard = () => ({
    user: null,
    navigation: [],
    stats: {},
    portals: [],
    news: [],
    notifications: [],
    departments: [],
});

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const updateCsrfToken = (token) => {
    let meta = document.querySelector('meta[name="csrf-token"]');

    if (!meta) {
        meta = document.createElement('meta');
        meta.setAttribute('name', 'csrf-token');
        document.head.appendChild(meta);
    }

    meta.setAttribute('content', token);
};

const refreshCsrfToken = async () => {
    const response = await fetch('/csrf-token', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    const payload = await response.json();

    if (payload.data?.token) {
        updateCsrfToken(payload.data.token);
    }

    return payload.data?.token ?? csrfToken();
};

const csrfFetch = async (url, options = {}) => {
    const request = () => fetch(url, {
        ...options,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers ?? {}),
        },
    });

    let response = await request();

    if (response.status === 419) {
        await refreshCsrfToken();
        response = await request();
    }

    return response;
};

export const useDashboardStore = defineStore('dashboard', {
    state: () => ({
        dashboard: emptyDashboard(),
        loading: false,
        authenticating: false,
        error: null,
        authError: null,
        unauthenticated: false,
        advertisements: [],
        mvp: {
            departments: [],
            users: [],
            serviceProviders: [],
            auditLogs: [],
            advertisements: [],
        },
        mvpError: null,
        newsMessage: null,
        realtime: {
            connected: false,
            error: null,
            toast: null,
        },
        echo: null,
    }),
    getters: {
        roleLabel: (state) => {
            const role = state.dashboard.user?.role;

            if (role === 'super_admin') return 'Super Administrator';
            if (role === 'department_admin') return 'Department Administrator';

            return 'Standard User';
        },
        canManageIntegrations: (state) => state.dashboard.user?.role === 'super_admin',
        departmentScope: (state) => state.dashboard.user?.department?.name ?? 'Organization',
        allowedPortals: (state) => state.dashboard.portals,
    },
    actions: {
        async fetchDashboard() {
            this.loading = true;
            this.error = null;
            this.unauthenticated = false;

            try {
                const response = await fetch('/api/dashboard', {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.status === 401) {
                    this.unauthenticated = true;
                    this.dashboard = emptyDashboard();

                    return;
                }

                if (!response.ok) {
                    throw new Error('Unable to load dashboard.');
                }

                const payload = await response.json();
                this.dashboard = payload.data;
                this.connectRealtime();

                if (this.canManageIntegrations) {
                    await this.fetchMvpOverview();
                }
            } catch (error) {
                this.error = error.message;
                this.dashboard = emptyDashboard();
            } finally {
                this.loading = false;
            }
        },
        async fetchAdvertisements() {
            const response = await fetch('/api/advertisements/active', {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                this.advertisements = [];
                return;
            }

            const payload = await response.json();
            this.advertisements = payload.data ?? [];
        },
        async fetchMvpOverview() {
            this.mvpError = null;

            const response = await fetch('/api/admin/overview', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                this.mvpError = 'Unable to load MVP admin data.';
                return;
            }

            const payload = await response.json();
            this.mvp = payload.data;
        },
        async createDepartment(payload) {
            return this.postAdmin('/api/admin/departments', payload);
        },
        async createServiceProvider(payload) {
            return this.postAdmin('/api/admin/service-providers', payload);
        },
        async createUser(payload) {
            return this.postAdmin('/api/admin/users', payload);
        },
        async assignUserAccess(payload) {
            return this.postAdmin('/api/admin/user-access', payload);
        },
        async saveAdvertisement(payload, advertisementId = null) {
            const formData = new FormData();

            Object.entries(payload).forEach(([key, value]) => {
                if (value === null || value === undefined || value === '') {
                    return;
                }

                formData.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
            });

            const response = await csrfFetch(advertisementId ? `/api/admin/advertisements/${advertisementId}` : '/api/admin/advertisements', {
                method: 'POST',
                body: formData,
            });

            if (!response.ok) {
                const error = await response.json();
                const validationMessage = error.errors
                    ? Object.values(error.errors).flat().join(' ')
                    : null;

                throw new Error(validationMessage || error.message || 'Unable to save advertisement.');
            }

            await Promise.all([this.fetchMvpOverview(), this.fetchAdvertisements()]);

            return response.json();
        },
        async postAdmin(url, payload) {
            const response = await csrfFetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message ?? 'Unable to save.');
            }

            await this.fetchMvpOverview();

            return response.json();
        },
        async publishNews(payload) {
            this.newsMessage = null;

            const response = await csrfFetch('/api/news-posts', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message ?? 'Unable to publish news.');
            }

            this.newsMessage = 'News post published.';
            await this.fetchDashboard();

            return response.json();
        },
        connectRealtime() {
            const user = this.dashboard.user;

            if (!user || this.echo || typeof window === 'undefined' || typeof window.createOnePortalEcho !== 'function') {
                return;
            }

            try {
                this.echo = window.createOnePortalEcho();

                this.subscribePrivate(`user.${user.id}`);

                if (user.department?.id) {
                    this.subscribePrivate(`department.${user.department.id}`);
                }

                if (user.role === 'super_admin') {
                    this.subscribePrivate('admin.system');
                }

                this.realtime.connected = true;
                this.realtime.error = null;
            } catch (error) {
                this.realtime.connected = false;
                this.realtime.error = 'Realtime notifications are unavailable.';
            }
        },
        subscribePrivate(channelName) {
            this.echo.private(channelName)
                .listen('.oneportal.news-published', (event) => {
                    const isNewPost = this.receiveRealtimeNews(event);

                    if (isNewPost) {
                        this.receiveRealtimeNotification({
                            kind: event.kind,
                            title: 'News published',
                            message: event.title,
                            target_id: event.id,
                        });
                        this.fetchDashboard();
                    }
                })
                .listen('.oneportal.access-changed', (event) => {
                    this.receiveRealtimeNotification({
                        kind: event.kind,
                        title: 'Portal access updated',
                        message: `You now have ${event.portal_count} assigned portal${event.portal_count === 1 ? '' : 's'}.`,
                    });
                    this.fetchDashboard();
                });
        },
        receiveRealtimeNotification(notification) {
            const item = {
                id: `realtime-${Date.now()}`,
                read_at: null,
                created_at: new Date().toISOString(),
                ...notification,
            };

            this.dashboard.notifications = [item, ...(this.dashboard.notifications ?? [])].slice(0, 10);
            this.dashboard.stats = {
                ...this.dashboard.stats,
                unreadNotifications: (this.dashboard.stats?.unreadNotifications ?? 0) + 1,
            };
            this.realtime.toast = item;
        },
        receiveRealtimeNews(event) {
            if (!event?.id || !event?.title) {
                return false;
            }

            const existingNews = this.dashboard.news ?? [];

            if (existingNews.some((post) => post.id === event.id)) {
                return false;
            }

            this.dashboard.news = [{
                id: event.id,
                title: event.title,
                body: event.excerpt ?? '',
                published_at: event.published_at ?? new Date().toISOString(),
            }, ...existingNews].slice(0, 5);

            this.dashboard.stats = {
                ...this.dashboard.stats,
                unreadNews: (this.dashboard.stats?.unreadNews ?? 0) + 1,
            };

            return true;
        },
        async markNotificationRead(notificationId) {
            this.dashboard.notifications = (this.dashboard.notifications ?? []).map((notification) => (
                notification.id === notificationId
                    ? { ...notification, read_at: notification.read_at ?? new Date().toISOString() }
                    : notification
            ));
            this.dashboard.stats = {
                ...this.dashboard.stats,
                unreadNotifications: Math.max((this.dashboard.stats?.unreadNotifications ?? 0) - 1, 0),
            };

            if (!String(notificationId).startsWith('realtime-')) {
                await csrfFetch(`/api/notifications/${notificationId}/read`, {
                    method: 'POST',
                });
            }
        },
        async login(credentials) {
            this.authenticating = true;
            this.authError = null;

            try {
                const response = await csrfFetch('/login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(credentials),
                });

                const payload = await response.json();

                if (!response.ok) {
                    const message = payload.errors?.email?.[0] ?? payload.message ?? 'Unable to sign in.';
                    throw new Error(message);
                }

                if (payload.data?.redirect_url) {
                    window.location.href = payload.data.redirect_url;
                    return;
                }

                await this.fetchDashboard();
            } catch (error) {
                this.authError = error.message;
            } finally {
                this.authenticating = false;
            }
        },
        async logout() {
            await csrfFetch('/logout', {
                method: 'POST',
            });

            if (this.echo) {
                this.echo.disconnect();
                this.echo = null;
            }

            this.dashboard = emptyDashboard();
            this.unauthenticated = true;
            this.realtime.connected = false;
        },
    },
});
