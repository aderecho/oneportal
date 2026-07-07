<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useDashboardStore } from '../stores/dashboard';

const store = useDashboardStore();
const activePage = ref('Dashboard');
const globalSearch = ref('');
const activityMode = ref('activity');
const selectedPortalId = ref(null);
const activeLegalPage = ref(null);
const activeAdIndex = ref(0);
const adRotationTimer = ref(null);
const selectedAdvertisementMediaName = ref('');
const advertisementMediaError = ref('');
const loginForm = reactive({ email: '', password: '', remember: false });
const departmentForm = reactive({ name: '', code: '' });
const integrationForm = reactive({ name: '', slug: '', entity_id: '', acs_url: '' });
const userForm = reactive({ name: '', email: '', password: '', role: 'user', department_id: '' });
const accessForm = reactive({ user_id: '', service_provider_ids: [] });
const newsForm = reactive({ title: '', body: '', scope: 'department', department_ids: [] });
const advertisementForm = reactive({
    id: null,
    title: '',
    body: '',
    link_url: '',
    starts_at: '',
    ends_at: '',
    is_forever: true,
    status: 'active',
    media: null,
});
const adminMessage = reactive({ text: '', error: '' });

onMounted(() => {
    store.fetchAdvertisements();
    store.fetchDashboard();
});

watch(
    () => store.dashboard.navigation,
    (navigation) => {
        if (navigation.length && !navigation.includes(activePage.value)) {
            activePage.value = navigation[0];
        }
    },
);

const isDashboardPage = computed(() => activePage.value.includes('Dashboard'));
const dashboardTitle = computed(() => {
    if (activePage.value.includes('Dashboard')) {
        if (isSuperAdmin.value) return 'Super Admin Dashboard';
        if (isDepartmentAdmin.value) return 'Department Dashboard';
        return 'My Dashboard';
    }

    return activePage.value || 'My Dashboard';
});
const isSuperAdmin = computed(() => store.dashboard.user?.role === 'super_admin');
const isDepartmentAdmin = computed(() => store.dashboard.user?.role === 'department_admin');
const canPublishNews = computed(() => isSuperAdmin.value || isDepartmentAdmin.value);

const statCards = computed(() => {
    const stats = store.dashboard.stats;

    if (isSuperAdmin.value) {
        return [
            ['Total Integrated Systems', stats.integratedSystems ?? 0],
            ['Active Departments', stats.activeDepartments ?? 0],
            ['Total Users', stats.totalUsers ?? 0],
            ['Recent Logins (24h)', store.mvp.auditLogs.filter((log) => log.action === 'auth.login').length],
        ];
    }

    if (isDepartmentAdmin.value) {
        return [
            ['Department Users', stats.departmentUsers ?? 0],
            ['Active Portals', stats.activePortals ?? 0],
            ['Scope', store.departmentScope],
        ];
    }

    return [
        ['Allowed Portals', stats.allowedPortals ?? 0],
        ['Unread News', stats.unreadNews ?? 0],
        ['Scope', store.departmentScope],
    ];
});

const healthRows = computed(() => {
    const providers = isSuperAdmin.value ? store.mvp.serviceProviders : store.allowedPortals;
    return providers.map((provider) => ({
        ...provider,
        statusLabel: provider.status ?? 'healthy',
    }));
});

const departmentUsers = computed(() => store.dashboard.departmentUsers ?? []);
const visibleUsers = computed(() => isSuperAdmin.value ? store.mvp.users : departmentUsers.value);
const visibleNews = computed(() => store.dashboard.news ?? []);
const publishableDepartments = computed(() => store.dashboard.departments?.length ? store.dashboard.departments : store.mvp.departments);
const visibleNotifications = computed(() => store.dashboard.notifications ?? []);
const unreadNotifications = computed(() => visibleNotifications.value.filter((notification) => !notification.read_at).length);
const searchTerm = computed(() => globalSearch.value.trim().toLowerCase());

const includesSearch = (...values) => {
    if (!searchTerm.value) return true;

    return values
        .filter((value) => value !== null && value !== undefined)
        .some((value) => String(value).toLowerCase().includes(searchTerm.value));
};

const filteredPortals = computed(() => store.allowedPortals.filter((portal) => (
    includesSearch(portal.name, portal.status, portal.launch_url)
)));
const filteredNews = computed(() => visibleNews.value.filter((post) => includesSearch(post.title, post.body)));
const filteredUsers = computed(() => visibleUsers.value.filter((user) => (
    includesSearch(user.name, user.email, user.role, user.department?.name, user.status)
)));
const filteredDepartments = computed(() => store.mvp.departments.filter((department) => (
    includesSearch(department.name, department.code, department.users_count)
)));
const filteredIntegrations = computed(() => store.mvp.serviceProviders.filter((provider) => (
    includesSearch(provider.name, provider.entity_id, provider.status)
)));
const filteredLogs = computed(() => store.mvp.auditLogs.filter((log) => (
    includesSearch(log.action, log.target_type, log.department_id, log.created_at)
)));
const filteredAdvertisements = computed(() => (store.mvp.advertisements ?? []).filter((advertisement) => (
    includesSearch(advertisement.title, advertisement.body, advertisement.status, advertisement.starts_at, advertisement.ends_at)
)));
const selectedPortal = computed(() => (
    filteredPortals.value.find((portal) => portal.id === selectedPortalId.value) ?? filteredPortals.value[0] ?? null
));
const activeLoginAds = computed(() => store.advertisements ?? []);
const hasMultipleLoginAds = computed(() => activeLoginAds.value.length > 1);
const activeLoginAd = computed(() => activeLoginAds.value[activeAdIndex.value] ?? activeLoginAds.value[0] ?? null);
const activityRows = computed(() => {
    if (activityMode.value === 'notifications') {
        return visibleNotifications.value.slice(0, 5).map((notification) => ({
            id: notification.id,
            title: notification.title,
            detail: notification.message,
            meta: notification.read_at ? 'Read' : 'Unread',
        }));
    }

    if (isDepartmentAdmin.value) {
        return departmentUsers.value.slice(0, 5).map((user) => ({
            id: user.id,
            title: user.name,
            detail: user.email,
            meta: user.status,
        }));
    }

    return filteredLogs.value.slice(0, 5).map((log) => ({
        id: log.id,
        title: log.action,
        detail: log.target_type ?? 'System event',
        meta: log.created_at,
    }));
});
const quickActions = computed(() => [
    { label: 'Launch Apps', page: isSuperAdmin.value ? 'Integration Management' : 'My Applications' },
    { label: canPublishNews.value ? 'Publish News' : 'Read News', page: 'News Feed' },
    { label: unreadNotifications.value ? 'Review Alerts' : 'Recent Activity', page: store.dashboard.navigation.includes('Notifications') ? 'Notifications' : 'Recent Activity' },
]);

const brandLogoSrc = '/images/oneportal-up-logo.png';
const portalAccentClasses = ['blue', 'green', 'orange', 'purple', 'teal', 'indigo', 'rose', 'sky'];
const legalPages = {
    privacy: {
        title: 'Privacy Policy',
        subtitle: 'How OnePortal collects, uses, protects, and retains account, access, and activity information.',
        updated: 'Effective June 28, 2026',
        sections: [
            {
                heading: 'Information We Process',
                items: [
                    'Account profile details such as name, institutional email address, role, department, unit, and assigned access scope.',
                    'Authentication and session records including sign-in timestamps, logout events, failed login attempts, device metadata, and security audit entries.',
                    'Portal access information such as assigned systems, SAML attributes released to service providers, department-level permissions, and access change history.',
                    'Operational content including news posts, notifications, publication visibility, and administrator actions performed inside OnePortal.',
                ],
            },
            {
                heading: 'Purpose of Use',
                items: [
                    'Provide single sign-on access to approved university systems and department-scoped resources.',
                    'Enforce role-based access controls for super administrators, department administrators, and standard users.',
                    'Deliver relevant news, alerts, and service notifications to the correct organization or department audience.',
                    'Maintain audit trails for security monitoring, support, compliance review, incident investigation, and service reliability.',
                ],
            },
            {
                heading: 'Sharing and Disclosure',
                items: [
                    'OnePortal releases only configured SAML attributes required by each connected system, such as email, name, role, and department scope.',
                    'Department-scoped news and notifications are visible only to intended users unless an organization-wide publication is selected.',
                    'Access logs may be reviewed by authorized administrators and security personnel for legitimate operational or compliance purposes.',
                    'OnePortal does not sell user information or use institutional account data for advertising.',
                ],
            },
            {
                heading: 'Retention and User Rights',
                items: [
                    'Account and access records are retained while the user needs service access or while required for audit and compliance obligations.',
                    'Security logs, notifications, and administrative activity may be retained for a defined audit period to support accountability.',
                    'Users may request correction of inaccurate profile or department information through their department administrator or system administrator.',
                    'Requests involving access review, account deactivation, or data handling questions should be routed to the OnePortal support or security team.',
                ],
            },
        ],
    },
    terms: {
        title: 'Terms of Service',
        subtitle: 'Rules for responsible use of OnePortal and the connected systems available through it.',
        updated: 'Effective June 28, 2026',
        sections: [
            {
                heading: 'Authorized Use',
                items: [
                    'OnePortal is intended for authorized university users, administrators, and approved service owners only.',
                    'Users must access only the systems, records, and department resources that match their assigned role and official duties.',
                    'Shared accounts, credential sharing, unauthorized delegation, or attempts to bypass access controls are not permitted.',
                    'Use of connected systems remains subject to each system owner’s policies, data classification rules, and acceptable-use standards.',
                ],
            },
            {
                heading: 'Administrator Responsibilities',
                items: [
                    'Administrators must keep department, user, service provider, and access assignments accurate and current.',
                    'Department administrators are responsible for publishing news only to appropriate department audiences and reviewing content before release.',
                    'Super administrators must validate SAML configuration, metadata, certificate status, and attribute release settings before enabling integrations.',
                    'Access changes should follow approved institutional processes and be removed promptly when no longer required.',
                ],
            },
            {
                heading: 'Content and Communications',
                items: [
                    'News posts, alerts, and notifications must be accurate, relevant, respectful, and related to official operations or services.',
                    'Users may not publish misleading notices, confidential information to the wrong audience, malicious links, or content that violates university policy.',
                    'OnePortal may record publication metadata, target department scope, and author information for auditability.',
                    'Administrators may remove or correct content that is inaccurate, unauthorized, outdated, or published to the wrong audience.',
                ],
            },
            {
                heading: 'Service Availability and Enforcement',
                items: [
                    'OnePortal may be updated, restricted, or temporarily unavailable during maintenance, security response, or integration changes.',
                    'Misuse may result in access suspension, administrative review, revocation of privileges, or referral under applicable institutional procedures.',
                    'The service is provided to support official work; users are responsible for complying with university technology and data protection policies.',
                    'Continued use of OnePortal means the user accepts these terms and any role-specific responsibilities assigned to their account.',
                ],
            },
        ],
    },
    security: {
        title: 'Security',
        subtitle: 'Security controls and practices used to protect OnePortal accounts, SSO flows, and connected services.',
        updated: 'Effective June 28, 2026',
        sections: [
            {
                heading: 'Authentication and SSO',
                items: [
                    'OnePortal uses session-backed authentication and supports SAML 2.0 integrations for connected service providers.',
                    'Service provider metadata, ACS URLs, entity IDs, certificates, and attribute release settings should be validated before production use.',
                    'Administrators should use strong account credentials and follow institutional identity management requirements.',
                    'Failed login attempts, successful sign-ins, and logout activity are recorded to support monitoring and incident investigation.',
                ],
            },
            {
                heading: 'Access Control',
                items: [
                    'Role-based permissions separate super administrator, department administrator, and standard user capabilities.',
                    'Department-scoped data limits visibility of users, portals, news, and notifications to the relevant organizational context.',
                    'Portal assignments should follow least-privilege principles and be reviewed when users change roles or departments.',
                    'Administrative actions such as creating users, publishing news, changing access, and managing integrations are auditable.',
                ],
            },
            {
                heading: 'Monitoring and Incident Response',
                items: [
                    'Security-relevant events are captured in audit logs, notifications, and integration health records.',
                    'Suspicious activity, incorrect access, exposed credentials, or unexpected SSO behavior should be reported immediately.',
                    'Administrators should disable affected access, preserve relevant logs, and coordinate with the appropriate security or IT team.',
                    'Connected systems should be reviewed after certificate changes, metadata updates, or failed SSO validation events.',
                ],
            },
            {
                heading: 'Operational Safeguards',
                items: [
                    'Keep certificates, SAML metadata, environment secrets, and database credentials protected and rotated according to policy.',
                    'Apply application updates, dependency patches, and server security updates through a controlled release process.',
                    'Limit production database and log access to authorized personnel with a legitimate operational need.',
                    'Backups, recovery procedures, and maintenance windows should be documented and tested for critical deployments.',
                ],
            },
        ],
    },
};
const legalPage = computed(() => (activeLegalPage.value ? legalPages[activeLegalPage.value] : null));

watch(filteredPortals, (portals) => {
    if (!portals.some((portal) => portal.id === selectedPortalId.value)) {
        selectedPortalId.value = portals[0]?.id ?? null;
    }
});

const stopAdRotation = () => {
    if (adRotationTimer.value) {
        window.clearInterval(adRotationTimer.value);
        adRotationTimer.value = null;
    }
};

const showLoginAd = (index) => {
    const adCount = activeLoginAds.value.length;

    if (!adCount) {
        activeAdIndex.value = 0;
        return;
    }

    activeAdIndex.value = (index + adCount) % adCount;
};

const showNextLoginAd = () => showLoginAd(activeAdIndex.value + 1);
const showPreviousLoginAd = () => showLoginAd(activeAdIndex.value - 1);

const startAdRotation = () => {
    stopAdRotation();

    if (!hasMultipleLoginAds.value) {
        return;
    }

    adRotationTimer.value = window.setInterval(showNextLoginAd, 10000);
};

watch(
    () => activeLoginAds.value.map((advertisement) => advertisement.id).join(':'),
    () => {
        activeAdIndex.value = 0;

        startAdRotation();
    },
    { immediate: true },
);

onBeforeUnmount(stopAdRotation);

const submitLogin = () => store.login(loginForm);

const openLegalPage = (page) => {
    activeLegalPage.value = page;
};

const closeLegalPage = () => {
    activeLegalPage.value = null;
};

const setPage = (item) => {
    activePage.value = item;
    adminMessage.text = '';
    adminMessage.error = '';
};

const runAction = async (action, message) => {
    adminMessage.text = '';
    adminMessage.error = '';

    try {
        await action();
        adminMessage.text = message;
    } catch (error) {
        adminMessage.error = error.message;
    }
};

const saveDepartment = async () => {
    await runAction(() => store.createDepartment(departmentForm), 'Department created.');
    Object.assign(departmentForm, { name: '', code: '' });
};

const saveIntegration = async () => {
    await runAction(() => store.createServiceProvider({
        ...integrationForm,
        launch_url: `/sso/${integrationForm.slug}`,
        attribute_release: ['email', 'name', 'role'],
    }), 'Integration created.');
    Object.assign(integrationForm, { name: '', slug: '', entity_id: '', acs_url: '' });
};

const saveUser = async () => {
    await runAction(() => store.createUser({
        ...userForm,
        department_id: userForm.department_id ? Number(userForm.department_id) : null,
    }), 'User created.');
    Object.assign(userForm, { name: '', email: '', password: '', role: 'user', department_id: '' });
};

const saveAccess = async () => {
    await runAction(() => store.assignUserAccess({
        user_id: Number(accessForm.user_id),
        service_provider_ids: accessForm.service_provider_ids.map(Number),
    }), 'Portal access updated.');
    Object.assign(accessForm, { user_id: '', service_provider_ids: [] });
};

const publishNews = async () => {
    const departmentIds = newsForm.department_ids.length
        ? newsForm.department_ids.map(Number)
        : (isDepartmentAdmin.value ? [store.dashboard.user.department.id] : []);

    await runAction(() => store.publishNews({
        title: newsForm.title,
        body: newsForm.body,
        scope: newsForm.scope,
        department_ids: newsForm.scope === 'department' ? departmentIds : [],
    }), 'News post published.');
    Object.assign(newsForm, { title: '', body: '', scope: 'department', department_ids: [] });
};

const saveAdvertisement = async () => {
    advertisementMediaError.value = '';

    await runAction(() => store.saveAdvertisement({
        title: advertisementForm.title,
        body: advertisementForm.body,
        link_url: advertisementForm.link_url,
        starts_at: advertisementForm.starts_at,
        ends_at: advertisementForm.is_forever ? '' : advertisementForm.ends_at,
        is_forever: advertisementForm.is_forever,
        status: advertisementForm.status,
        media: advertisementForm.media,
    }, advertisementForm.id), advertisementForm.id ? 'Advertisement updated.' : 'Advertisement created.');

    Object.assign(advertisementForm, {
        id: null,
        title: '',
        body: '',
        link_url: '',
        starts_at: '',
        ends_at: '',
        is_forever: true,
        status: 'active',
        media: null,
    });
    selectedAdvertisementMediaName.value = '';
};

const editAdvertisement = (advertisement) => {
    Object.assign(advertisementForm, {
        id: advertisement.id,
        title: advertisement.title ?? '',
        body: advertisement.body ?? '',
        link_url: advertisement.link_url ?? '',
        starts_at: advertisement.starts_at ? advertisement.starts_at.slice(0, 16) : '',
        ends_at: advertisement.ends_at ? advertisement.ends_at.slice(0, 16) : '',
        is_forever: Boolean(advertisement.is_forever),
        status: advertisement.status ?? 'active',
        media: null,
    });
    selectedAdvertisementMediaName.value = advertisement.media_url ? 'Current media will be kept unless you choose a new file.' : '';
    advertisementMediaError.value = '';
    setPage('Advertisements');
};

const compressImageForUpload = async (file) => {
    if (!file.type.startsWith('image/') || file.size <= 1_900_000) {
        return file;
    }

    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, 1920 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));

    const context = canvas.getContext('2d');
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    const blob = await new Promise((resolve) => {
        canvas.toBlob(resolve, 'image/jpeg', 0.82);
    });

    bitmap.close?.();

    if (!blob || blob.size >= file.size) {
        return file;
    }

    const filename = file.name.replace(/\.[^.]+$/, '.jpg');

    return new File([blob], filename, { type: 'image/jpeg' });
};

const setAdvertisementMedia = async (event) => {
    const file = event.target.files?.[0] ?? null;
    advertisementMediaError.value = '';
    advertisementForm.media = null;
    selectedAdvertisementMediaName.value = '';

    if (!file) {
        return;
    }

    try {
        const uploadFile = await compressImageForUpload(file);

        if (uploadFile.size > 1_900_000) {
            advertisementMediaError.value = 'This file is too large for the current server upload limit. Choose an image under 2 MB.';
            event.target.value = '';
            return;
        }

        advertisementForm.media = uploadFile;
        selectedAdvertisementMediaName.value = uploadFile.name === file.name
            ? uploadFile.name
            : `${file.name} compressed to ${uploadFile.name}`;
    } catch (error) {
        advertisementMediaError.value = 'Unable to prepare this image. Choose a smaller JPG or PNG file.';
        event.target.value = '';
    }
};
</script>

<template>
    <main v-if="store.loading" class="loading-screen">
        <div class="brand login-brand">
            <img class="brand-logo" :src="brandLogoSrc" alt="University of the Philippines logo" />
            <span>OnePortal</span>
        </div>
        <div class="state-panel">Loading dashboard...</div>
    </main>

    <main v-else-if="legalPage" class="legal-screen">
        <header class="login-header legal-header">
            <div class="brand login-brand">
                <img class="brand-logo" :src="brandLogoSrc" alt="University of the Philippines logo" />
                <span>OnePortal</span>
            </div>
            <button class="legal-back" type="button" @click="closeLegalPage">Back to sign in</button>
        </header>

        <article class="legal-page">
            <header class="legal-hero">
                <p>{{ legalPage.updated }}</p>
                <h1>{{ legalPage.title }}</h1>
                <span>{{ legalPage.subtitle }}</span>
            </header>

            <section class="legal-summary" aria-label="Policy summary">
                <p><strong>Applies to:</strong> OnePortal users, department administrators, super administrators, and approved service owners.</p>
                <p><strong>Scope:</strong> Account access, SSO integrations, department-scoped resources, news publication, notifications, audit logs, and administrative workflows.</p>
                <p><strong>Contact:</strong> For questions or reports, coordinate with the OnePortal administrator or the designated university IT/security office.</p>
            </section>

            <section class="legal-section" v-for="section in legalPage.sections" :key="section.heading">
                <h2>{{ section.heading }}</h2>
                <ul>
                    <li v-for="item in section.items" :key="item">{{ item }}</li>
                </ul>
            </section>
        </article>
    </main>

    <main v-else-if="store.unauthenticated" class="login-screen">
        <header class="login-header">
            <div class="brand login-brand">
                <img class="brand-logo" :src="brandLogoSrc" alt="University of the Philippines logo" />
                <span>OnePortal</span>
            </div>
        </header>

        <section class="login-stage">
            <div class="login-copy">
                <section v-if="activeLoginAd" class="login-ad-showcase" aria-label="Featured advertisement">
                    <Transition name="ad-banner-fade" mode="out-in">
                        <div :key="activeLoginAd.id ?? activeAdIndex" class="ad-banner-slide">
                            <div class="ad-banner-topline">
                                <span class="security-chip">Campus Announcement</span>
                                <div v-if="hasMultipleLoginAds" class="ad-banner-count">{{ activeAdIndex + 1 }} / {{ activeLoginAds.length }}</div>
                            </div>
                            <div class="ad-media-frame">
                                <video v-if="activeLoginAd.media_type === 'video'" :src="activeLoginAd.media_url" muted loop autoplay playsinline controls></video>
                                <img v-else-if="activeLoginAd.media_url" :src="activeLoginAd.media_url" :alt="activeLoginAd.title" />
                                <span v-else class="ad-media-placeholder">Ad</span>
                            </div>
                            <h1>{{ activeLoginAd.title }}</h1>
                            <p>{{ activeLoginAd.body }}</p>
                            <div class="ad-banner-actions">
                                <a v-if="activeLoginAd.link_url" class="ad-link" :href="activeLoginAd.link_url" target="_blank" rel="noopener">View details</a>
                                <div v-if="hasMultipleLoginAds" class="ad-banner-controls" aria-label="Advertisement banner controls">
                                    <button type="button" aria-label="Previous advertisement" @click="showPreviousLoginAd">‹</button>
                                    <button type="button" aria-label="Next advertisement" @click="showNextLoginAd">›</button>
                                </div>
                            </div>
                            <div v-if="hasMultipleLoginAds" class="ad-banner-dots" aria-label="Advertisement banners">
                                <button
                                    v-for="(advertisement, index) in activeLoginAds"
                                    :key="advertisement.id ?? index"
                                    type="button"
                                    :class="{ active: index === activeAdIndex }"
                                    :aria-label="`Show advertisement ${index + 1}`"
                                    :aria-current="index === activeAdIndex ? 'true' : null"
                                    @click="showLoginAd(index)"
                                ></button>
                            </div>
                            <small class="ad-schedule">{{ activeLoginAd.is_forever ? 'Always available' : `Scheduled until ${activeLoginAd.ends_at ?? 'configured end date'}` }}</small>
                        </div>
                    </Transition>
                </section>

                <template v-else>
                    <span class="security-chip">Centralized. Secure. Connected.</span>
                    <h1>One secure login for all your connected systems</h1>
                    <p>Access all your enterprise applications with a single, secure sign-in. OnePortal connects your people to the tools and data they need to do their best work.</p>

                    <div class="connected-portals" aria-label="Connected portals">
                        <article
                            v-for="(portal, index) in (store.allowedPortals.length ? store.allowedPortals : [
                                { id: 'amis', name: 'AMIS', status: 'active', launch_url: '#' },
                                { id: 'hris', name: 'HRIS', status: 'active', launch_url: '#' },
                                { id: 'dtr', name: 'DTR', status: 'active', launch_url: '#' },
                                { id: 'library', name: 'Library', status: 'active', launch_url: '#' },
                                { id: 'inventory', name: 'Inventory', status: 'active', launch_url: '#' },
                                { id: 'reports', name: 'Reports', status: 'active', launch_url: '#' },
                            ])"
                            :key="portal.id"
                            class="login-portal-card"
                        >
                            <span :class="['portal-icon', portalAccentClasses[index % portalAccentClasses.length]]">{{ portal.name.slice(0, 1) }}</span>
                            <strong>{{ portal.name }}</strong>
                            <small>Open portal</small>
                        </article>
                    </div>
                </template>

                <div class="login-benefits">
                    <p><span class="benefit-icon security" aria-hidden="true"></span><strong>Enterprise Security</strong><small>SAML 2.0 SSO with encryption and advanced protection</small></p>
                    <p><span class="benefit-icon access" aria-hidden="true"></span><strong>Centralized Access</strong><small>One login for all approved applications and resources</small></p>
                    <p><span class="benefit-icon productivity" aria-hidden="true"></span><strong>Productive by Design</strong><small>Faster access, fewer passwords, better productivity</small></p>
                </div>
            </div>

            <form class="login-form" @submit.prevent="submitLogin">
                <div class="login-form-heading">
                    <h2>Welcome back</h2>
                    <p>Sign in to your OnePortal account</p>
                </div>
                <label>Email address <input v-model="loginForm.email" type="email" placeholder="name@company.com" autocomplete="email" required /></label>
                <label>Password <input v-model="loginForm.password" type="password" placeholder="Enter your password" autocomplete="current-password" required /></label>
                <div class="login-options">
                    <label class="remember-row"><input v-model="loginForm.remember" type="checkbox" /> Remember me</label>
                    <a href="#">Forgot password?</a>
                </div>
                <p v-if="store.authError" class="form-error">{{ store.authError }}</p>
                <button type="submit" :disabled="store.authenticating">{{ store.authenticating ? 'Signing in...' : 'Sign in with SSO' }}</button>
                <div class="divider"><span>or</span></div>
                <button class="secondary-login" type="submit" :disabled="store.authenticating">Sign in with email</button>
                <aside class="saml-note">
                    <strong>Secure access with SAML 2.0</strong>
                    <span>Your connection is encrypted and protected by enterprise-grade security.</span>
                </aside>
            </form>
        </section>

        <footer class="login-footer">
            <span>© 2026 OnePortal. All rights reserved.</span>
            <a href="#privacy-policy" @click.prevent="openLegalPage('privacy')">Privacy Policy</a>
            <a href="#terms-of-service" @click.prevent="openLegalPage('terms')">Terms of Service</a>
            <a href="#security" @click.prevent="openLegalPage('security')">Security</a>
        </footer>
    </main>

    <main v-else class="oneportal-shell">
        <aside class="sidebar">
            <div class="brand">
                <img class="brand-logo" :src="brandLogoSrc" alt="University of the Philippines logo" />
                <span>OnePortal</span>
            </div>

            <p class="sidebar-label">Navigation</p>
            <nav aria-label="Primary">
                <button
                    v-for="item in store.dashboard.navigation"
                    :key="item"
                    type="button"
                    :class="['nav-item', { active: activePage === item }]"
                    @click="setPage(item)"
                >
                    <span class="nav-icon"></span>
                    {{ item }}
                    <span v-if="item === 'Notifications' && unreadNotifications" class="nav-badge">{{ unreadNotifications }}</span>
                </button>
            </nav>

            <section class="system-status" aria-label="System status">
                <p>System Status</p>
                <strong>All systems operational</strong>
                <a href="#">View system health</a>
                <span class="status-art"></span>
            </section>
        </aside>

        <section class="content">
            <header class="topbar">
                <div>
                    <span class="page-kicker">{{ store.departmentScope }}</span>
                    <h1>{{ dashboardTitle }}</h1>
                </div>
                <label class="search">
                    <span class="sr-only">Search</span>
                    <input v-model="globalSearch" type="search" placeholder="Search portals, users, news, logs..." />
                </label>
                <button class="notification-button" type="button" @click="setPage('Notifications')">
                    <span class="sr-only">Notifications</span>
                    <span v-if="unreadNotifications" class="notification-count">{{ unreadNotifications }}</span>
                </button>
                <div class="profile" v-if="store.dashboard.user">
                    <span>{{ store.dashboard.user.name.charAt(0) }}</span>
                    <div>
                        <strong>{{ store.dashboard.user.name }}</strong>
                        <small>{{ store.roleLabel }}</small>
                    </div>
                    <button class="logout-link" type="button" @click="store.logout">Sign out</button>
                </div>
            </header>
            <aside v-if="store.realtime.toast" class="toast" role="status">
                <strong>{{ store.realtime.toast.title }}</strong>
                <span>{{ store.realtime.toast.message }}</span>
            </aside>

            <div v-if="store.error" class="state-panel error">{{ store.error }}</div>

            <template v-else>
                <section v-if="isDashboardPage" class="page-stack">
                    <section class="welcome">
                        <div>
                            <h2>Welcome back, {{ store.dashboard.user?.name ?? 'OnePortal User' }}</h2>
                            <p>{{ store.departmentScope }} access is scoped by role and department.</p>
                            <div class="quick-actions" aria-label="Quick actions">
                                <button v-for="action in quickActions" :key="action.label" type="button" @click="setPage(action.page)">
                                    {{ action.label }}
                                </button>
                            </div>
                        </div>
                        <div class="hero-art" aria-hidden="true">
                            <span></span><span></span><span></span>
                            <strong>{{ filteredPortals.length }}</strong>
                            <small>available portals</small>
                        </div>
                    </section>

                    <section class="stats-grid" aria-label="Dashboard statistics">
                        <article v-for="[label, value] in statCards" :key="label" class="stat-card">
                            <span class="stat-icon">{{ String(label).slice(0, 1) }}</span>
                            <div><p>{{ label }}</p><strong>{{ value }}</strong></div>
                        </article>
                    </section>

                    <section class="dashboard-grid">
                        <article class="panel portal-panel">
                            <div class="panel-header">
                                <h2>Application / Portal Access</h2>
                                <span>{{ isSuperAdmin ? 'All active systems' : 'Allowed systems' }}</span>
                            </div>
                            <div v-if="filteredPortals.length" class="portal-grid">
                                <a
                                    v-for="(portal, index) in filteredPortals"
                                    :key="portal.id"
                                    :class="['portal-tile', { selected: selectedPortal?.id === portal.id }]"
                                    :href="portal.launch_url"
                                    @focus="selectedPortalId = portal.id"
                                    @mouseenter="selectedPortalId = portal.id"
                                >
                                    <span :class="['portal-icon', portalAccentClasses[index % portalAccentClasses.length]]">{{ portal.name.slice(0, 1) }}</span>
                                    <span><strong>{{ portal.name }}</strong><small>{{ portal.status }} · Open portal</small></span>
                                </a>
                            </div>
                            <p v-else class="empty-state">No portals assigned.</p>
                            <div v-if="selectedPortal" class="portal-preview">
                                <span class="status-dot" :class="selectedPortal.status"></span>
                                <div>
                                    <strong>{{ selectedPortal.name }}</strong>
                                    <p>{{ selectedPortal.status }} status. Launch opens {{ selectedPortal.launch_url }}.</p>
                                </div>
                                <a :href="selectedPortal.launch_url">Open</a>
                            </div>
                        </article>

                        <article class="panel news-panel">
                            <div class="panel-header"><h2>News Feed</h2><button type="button" @click="setPage('News Feed')">View all</button></div>
                            <div v-if="filteredNews.length" class="news-list">
                                <article v-for="post in filteredNews.slice(0, 4)" :key="post.id" class="news-item">
                                    <span class="news-icon"></span>
                                    <div><strong>{{ post.title }}</strong><p>{{ post.body }}</p></div>
                                </article>
                            </div>
                            <p v-else class="empty-state">No relevant news yet.</p>
                        </article>
                    </section>

                    <section class="dashboard-grid">
                        <article class="panel">
                            <div class="panel-header"><h2>Integration Health</h2><button type="button" @click="setPage(isSuperAdmin ? 'Integration Management' : 'My Applications')">View systems</button></div>
                            <div class="status-list">
                                <p v-for="provider in healthRows" :key="provider.id">
                                    <span :class="['status-dot', provider.statusLabel]"></span>
                                    {{ provider.name }} <strong>{{ provider.statusLabel }}</strong>
                                </p>
                            </div>
                        </article>
                        <article class="panel">
                            <div class="panel-header"><h2>{{ isDepartmentAdmin ? 'Department Users' : 'Recent Activity' }}</h2></div>
                            <div class="segmented" aria-label="Activity view">
                                <button type="button" :class="{ active: activityMode === 'activity' }" @click="activityMode = 'activity'">
                                    Activity
                                </button>
                                <button type="button" :class="{ active: activityMode === 'notifications' }" @click="activityMode = 'notifications'">
                                    Alerts
                                </button>
                            </div>
                            <div class="compact-table">
                                <p v-for="row in activityRows" :key="row.id"><strong>{{ row.title }}</strong><span>{{ row.detail }}</span><em>{{ row.meta }}</em></p>
                                <p v-if="!activityRows.length"><span>No activity recorded yet.</span></p>
                            </div>
                        </article>
                    </section>
                </section>

                <section v-else-if="activePage === 'Units & Departments'" class="page-panel">
                    <div class="panel-header"><h2>Units & Departments</h2><span>Create and review organization departments</span></div>
                    <p v-if="adminMessage.text" class="form-success">{{ adminMessage.text }}</p>
                    <p v-if="adminMessage.error" class="form-error">{{ adminMessage.error }}</p>
                    <form class="inline-form" @submit.prevent="saveDepartment">
                        <input v-model="departmentForm.name" placeholder="Department name" required />
                        <input v-model="departmentForm.code" placeholder="Code" required />
                        <button type="submit">Add Department</button>
                    </form>
                    <div class="data-table">
                        <p class="table-head"><span>Department</span><span>Code</span><span>Users</span><span>Status</span></p>
                        <p v-for="department in filteredDepartments" :key="department.id">
                            <span>{{ department.name }}</span><span>{{ department.code }}</span><span>{{ department.users_count }}</span><span class="pill active">Active</span>
                        </p>
                    </div>
                </section>

                <section v-else-if="activePage === 'User Management' || activePage === 'Department Users'" class="page-panel">
                    <div class="panel-header"><h2>{{ activePage }}</h2><span>{{ isSuperAdmin ? 'Organization-wide users' : store.departmentScope }}</span></div>
                    <form v-if="isSuperAdmin" class="inline-form user-form" @submit.prevent="saveUser">
                        <input v-model="userForm.name" placeholder="Full name" required />
                        <input v-model="userForm.email" placeholder="Email" type="email" required />
                        <input v-model="userForm.password" placeholder="Temporary password" type="password" required />
                        <select v-model="userForm.role"><option value="user">Standard User</option><option value="department_admin">Department Admin</option><option value="super_admin">Super Admin</option></select>
                        <select v-model="userForm.department_id"><option value="">No department</option><option v-for="department in store.mvp.departments" :key="department.id" :value="department.id">{{ department.name }}</option></select>
                        <button type="submit">Add User</button>
                    </form>
                    <p v-if="adminMessage.text" class="form-success">{{ adminMessage.text }}</p>
                    <p v-if="adminMessage.error" class="form-error">{{ adminMessage.error }}</p>
                    <div class="data-table users-table">
                        <p class="table-head"><span>Name</span><span>Email</span><span>Role</span><span>Department/Status</span></p>
                        <p v-for="user in filteredUsers" :key="user.id">
                            <span>{{ user.name }}</span><span>{{ user.email }}</span><span>{{ user.role }}</span><span>{{ user.department?.name ?? user.status ?? 'Unassigned' }}</span>
                        </p>
                    </div>
                </section>

                <section v-else-if="activePage === 'Integration Management' || activePage === 'System Integration'" class="page-panel">
                    <div class="panel-header"><h2>{{ activePage }}</h2><a href="/saml2/metadata" target="_blank">Download Metadata</a></div>
                    <form class="inline-form integration-form" @submit.prevent="saveIntegration">
                        <input v-model="integrationForm.name" placeholder="System name" required />
                        <input v-model="integrationForm.slug" placeholder="slug" required />
                        <input v-model="integrationForm.entity_id" placeholder="Entity ID URL" required />
                        <input v-model="integrationForm.acs_url" placeholder="ACS URL" required />
                        <button type="submit">Add Integration</button>
                    </form>
                    <p v-if="adminMessage.text" class="form-success">{{ adminMessage.text }}</p>
                    <p v-if="adminMessage.error" class="form-error">{{ adminMessage.error }}</p>
                    <div class="integration-cards">
                        <article v-for="provider in filteredIntegrations" :key="provider.id" class="integration-card">
                            <strong>{{ provider.name }}</strong>
                            <span>{{ provider.entity_id }}</span>
                            <span :class="['pill', provider.status]">{{ provider.status }}</span>
                        </article>
                    </div>
                </section>

                <section v-else-if="activePage === 'User Access'" class="page-panel">
                    <div class="panel-header"><h2>User Access</h2><span>Assign portal access to users</span></div>
                    <form class="access-form" @submit.prevent="saveAccess">
                        <select v-model="accessForm.user_id" required>
                            <option value="">Select user</option>
                            <option v-for="user in store.mvp.users" :key="user.id" :value="user.id">{{ user.name }} · {{ user.email }}</option>
                        </select>
                        <div class="checkbox-grid">
                            <label v-for="provider in store.mvp.serviceProviders" :key="provider.id">
                                <input v-model="accessForm.service_provider_ids" type="checkbox" :value="provider.id" />
                                {{ provider.name }}
                            </label>
                        </div>
                        <button type="submit">Save Access</button>
                    </form>
                    <p v-if="adminMessage.text" class="form-success">{{ adminMessage.text }}</p>
                    <p v-if="adminMessage.error" class="form-error">{{ adminMessage.error }}</p>
                </section>

                <section v-else-if="activePage === 'Advertisements'" class="page-panel">
                    <div class="panel-header"><h2>Advertisements</h2><span>Create login page ads, media, and schedules</span></div>
                    <p v-if="adminMessage.text" class="form-success">{{ adminMessage.text }}</p>
                    <p v-if="adminMessage.error" class="form-error">{{ adminMessage.error }}</p>
                    <form class="advertisement-form" @submit.prevent="saveAdvertisement">
                        <input v-model="advertisementForm.title" placeholder="Advertisement title" required />
                        <textarea v-model="advertisementForm.body" placeholder="Advertisement message or update"></textarea>
                        <input v-model="advertisementForm.link_url" placeholder="Optional link URL" type="url" />
                        <label class="file-field">Media image or video
                            <input type="file" accept="image/*,video/*,.pdf" @change="setAdvertisementMedia" />
                        </label>
                        <small v-if="selectedAdvertisementMediaName" class="file-help">{{ selectedAdvertisementMediaName }}</small>
                        <small v-if="advertisementMediaError" class="form-error">{{ advertisementMediaError }}</small>
                        <div class="schedule-grid">
                            <label>Start date <input v-model="advertisementForm.starts_at" type="datetime-local" /></label>
                            <label v-if="!advertisementForm.is_forever">End date <input v-model="advertisementForm.ends_at" type="datetime-local" /></label>
                            <label class="remember-row"><input v-model="advertisementForm.is_forever" type="checkbox" /> Show forever</label>
                            <label>Status
                                <select v-model="advertisementForm.status">
                                    <option value="active">Active</option>
                                    <option value="draft">Draft</option>
                                    <option value="paused">Paused</option>
                                </select>
                            </label>
                        </div>
                        <button type="submit">{{ advertisementForm.id ? 'Update Advertisement' : 'Create Advertisement' }}</button>
                    </form>

                    <div class="advertisement-calendar">
                        <article v-for="advertisement in filteredAdvertisements" :key="advertisement.id" class="advertisement-row">
                            <div class="ad-preview">
                                <video v-if="advertisement.media_type === 'video' && advertisement.media_url" :src="advertisement.media_url" muted playsinline></video>
                                <img v-else-if="advertisement.media_url" :src="advertisement.media_url" :alt="advertisement.title" />
                                <span v-else class="ad-placeholder">Ad</span>
                            </div>
                            <div>
                                <strong>{{ advertisement.title }}</strong>
                                <p>{{ advertisement.body || 'No message provided.' }}</p>
                                <small>{{ advertisement.is_forever ? 'Forever' : `${advertisement.starts_at ?? 'No start'} to ${advertisement.ends_at ?? 'No end'}` }}</small>
                            </div>
                            <span :class="['pill', advertisement.status]">{{ advertisement.status }}</span>
                            <button type="button" @click="editAdvertisement(advertisement)">Update</button>
                        </article>
                        <p v-if="!filteredAdvertisements.length" class="empty-state">No advertisements have been created yet.</p>
                    </div>
                </section>

                <section v-else-if="activePage === 'News Feed'" class="page-panel">
                    <div class="panel-header"><h2>News Feed</h2><span>{{ isSuperAdmin ? 'Organization and department posts' : store.departmentScope }}</span></div>
                    <form v-if="canPublishNews" class="news-composer" @submit.prevent="publishNews">
                        <input v-model="newsForm.title" placeholder="News title" required />
                        <textarea v-model="newsForm.body" placeholder="Write an update" required></textarea>
                        <select v-if="isSuperAdmin" v-model="newsForm.scope"><option value="organization">Organization-wide</option><option value="department">Department-scoped</option></select>
                        <div v-if="canPublishNews && newsForm.scope === 'department'" class="checkbox-grid news-department-picker" aria-label="Share with departments">
                            <label v-for="department in publishableDepartments" :key="department.id">
                                <input v-model="newsForm.department_ids" type="checkbox" :value="department.id" />
                                {{ department.name }}
                            </label>
                        </div>
                        <button type="submit">Publish News</button>
                    </form>
                    <p v-if="adminMessage.text" class="form-success">{{ adminMessage.text }}</p>
                    <p v-if="adminMessage.error" class="form-error">{{ adminMessage.error }}</p>
                    <div class="news-list full-list">
                        <article v-for="post in filteredNews" :key="post.id" class="news-item"><span class="news-icon"></span><div><strong>{{ post.title }}</strong><p>{{ post.body }}</p></div></article>
                        <p v-if="!filteredNews.length" class="empty-state">No news matches your search.</p>
                    </div>
                </section>

                <section v-else-if="activePage === 'Logs'" class="page-panel">
                    <div class="panel-header"><h2>Logs</h2><span>Audit trail</span></div>
                    <div class="data-table logs-table">
                        <p class="table-head"><span>Action</span><span>Target</span><span>Department</span><span>Time</span></p>
                        <p v-for="log in filteredLogs" :key="log.id">
                            <span>{{ log.action }}</span><span>{{ log.target_type }}</span><span>{{ log.department_id ?? 'System' }}</span><span>{{ log.created_at }}</span>
                        </p>
                    </div>
                </section>

                <section v-else-if="activePage === 'My Applications'" class="page-panel">
                    <div class="panel-header"><h2>My Applications</h2><span>One-click portal launcher</span></div>
                    <div class="portal-grid expanded">
                        <a v-for="portal in filteredPortals" :key="portal.id" class="portal-tile" :href="portal.launch_url">
                            <span class="portal-icon">{{ portal.name.slice(0, 1) }}</span>
                            <span><strong>{{ portal.name }}</strong><small>{{ portal.status }} · Open portal</small></span>
                        </a>
                    </div>
                </section>

                <section v-else-if="activePage === 'Recent Activity' || activePage === 'Notifications' || activePage === 'Dashboard Management'" class="page-panel">
                    <div class="panel-header"><h2>{{ activePage }}</h2><span>{{ activePage === 'Notifications' ? 'Realtime and database notifications' : 'Current MVP status' }}</span></div>
                    <div v-if="activePage === 'Notifications'" class="notification-list">
                        <article v-for="notification in visibleNotifications" :key="notification.id" :class="['notification-row', { unread: !notification.read_at }]">
                            <span class="notification-dot"></span>
                            <div>
                                <strong>{{ notification.title }}</strong>
                                <p>{{ notification.message }}</p>
                                <small>{{ notification.created_at }}</small>
                            </div>
                            <button v-if="!notification.read_at" type="button" @click="store.markNotificationRead(notification.id)">Mark read</button>
                        </article>
                        <p v-if="!visibleNotifications.length" class="empty-state">No notifications yet.</p>
                    </div>
                    <div v-else class="compact-table">
                        <p><strong>Authentication</strong><span>Session secured and audited</span><em>Active</em></p>
                        <p><strong>Portal Access</strong><span>Department and user scoped</span><em>Active</em></p>
                        <p><strong>Integration Health</strong><span>Basic status tracking</span><em>Active</em></p>
                    </div>
                </section>
            </template>
        </section>
    </main>
</template>
