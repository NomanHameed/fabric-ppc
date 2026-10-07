import type { LucideIcon } from '@lucide/vue';
import {
    Boxes,
    Building2,
    CircleHelp,
    ClipboardList,
    Clock,
    Cog,
    DatabaseZap,
    Factory,
    FileText,
    Gauge,
    GitBranch,
    History,
    Layers,
    LayoutGrid,
    ListTree,
    Milestone,
    PackageOpen,
    Route,
    ScrollText,
    Search,
    Settings,
    Shield,
    Tags,
    UserCheck,
    Users,
    Workflow,
} from '@lucide/vue';

export type NavEntry = {
    key: string;
    titleKey: string;
    href: string;
    icon: LucideIcon;
    permission?: string;
};
export type NavGroup = { key: string; titleKey: string; items: NavEntry[] };

export const NAVIGATION: NavGroup[] = [
    {
        key: 'home',
        titleKey: 'app.nav.home',
        items: [
            {
                key: 'dashboard',
                titleKey: 'app.nav.dashboard',
                href: '/dashboard',
                icon: LayoutGrid,
            },
        ],
    },
    {
        key: 'oracle',
        titleKey: 'app.nav.oracle',
        items: [
            {
                key: 'oracle_import',
                titleKey: 'app.nav.oracle_import',
                href: '/oracle/imports',
                icon: DatabaseZap,
                permission: 'oracle.import.view',
            },
        ],
    },
    {
        key: 'production',
        titleKey: 'app.nav.production',
        items: [
            {
                key: 'issue',
                titleKey: 'app.nav.issue',
                href: '/production/issue',
                icon: PackageOpen,
                permission: 'production.issue',
            },
            {
                key: 'lot_routes',
                titleKey: 'app.nav.lot_routes',
                href: '/production/routes',
                icon: Route,
                permission: 'production.route.manage',
            },
            {
                key: 'sheets',
                titleKey: 'app.nav.sheets',
                href: '/production/sheets',
                icon: ClipboardList,
                permission: 'production.sheet.view',
            },
            {
                key: 'lots',
                titleKey: 'app.nav.lots',
                href: '/production/lots',
                icon: Layers,
                permission: 'inquiry.view',
            },
            {
                key: 'contracts',
                titleKey: 'app.nav.contracts',
                href: '/production/contracts',
                icon: FileText,
                permission: 'inquiry.view',
            },
            // Titles for the new screens come from the lang groups those screens own, not from `app.nav.*`.
            {
                key: 'lot_timeline',
                titleKey: 'timeline.title',
                href: '/production/timeline',
                icon: History,
                permission: 'inquiry.view',
            },
            {
                key: 'capacity',
                titleKey: 'production.capacity.title',
                href: '/production/capacity',
                icon: Gauge,
                permission: 'production.sheet.view',
            },
        ],
    },
    {
        key: 'inquiry',
        titleKey: 'app.nav.inquiry',
        items: [
            {
                key: 'search',
                titleKey: 'app.nav.search',
                href: '/search',
                icon: Search,
                permission: 'inquiry.view',
            },
        ],
    },
    {
        key: 'masters',
        titleKey: 'app.nav.masters',
        items: [
            {
                key: 'departments',
                titleKey: 'app.nav.departments',
                href: '/masters/departments',
                icon: Building2,
                permission: 'masters.view',
            },
            {
                key: 'machines',
                titleKey: 'app.nav.machines',
                href: '/masters/machines',
                icon: Factory,
                permission: 'masters.view',
            },
            {
                key: 'operations',
                titleKey: 'app.nav.operations',
                href: '/masters/operations',
                icon: Workflow,
                permission: 'masters.view',
            },
            {
                key: 'shifts',
                titleKey: 'app.nav.shifts',
                href: '/masters/shifts',
                icon: Clock,
                permission: 'masters.view',
            },
            {
                key: 'parties',
                titleKey: 'app.nav.parties',
                href: '/masters/parties',
                icon: Users,
                permission: 'masters.view',
            },
            {
                key: 'qualities',
                titleKey: 'app.nav.qualities',
                href: '/masters/qualities',
                icon: Boxes,
                permission: 'masters.view',
            },
            {
                key: 'route_templates',
                titleKey: 'app.nav.route_templates',
                href: '/masters/route-templates',
                icon: Route,
                permission: 'masters.view',
            },
            {
                key: 'lookups',
                titleKey: 'app.nav.lookups',
                href: '/masters/lookups',
                icon: Tags,
                permission: 'masters.view',
            },
            {
                key: 'stages',
                titleKey: 'app.nav.stages',
                href: '/masters/stages',
                icon: Milestone,
                permission: 'masters.view',
            },
            {
                key: 'stage_processes',
                titleKey: 'app.nav.stage_processes',
                href: '/masters/stage_processes',
                icon: GitBranch,
                permission: 'masters.view',
            },
            // The status -> stage gap report. It reads lots, so it is a Production route; it is listed here
            // because the only way to act on it is the Stage Processes master above, and only someone who
            // may edit that master (`masters.manage`) can do anything with it.
            {
                key: 'unmapped_statuses',
                titleKey: 'production.unmapped.title',
                href: '/production/unmapped-statuses',
                icon: CircleHelp,
                permission: 'masters.manage',
            },
        ],
    },
    {
        key: 'admin',
        titleKey: 'app.nav.admin',
        items: [
            {
                key: 'users',
                titleKey: 'app.nav.users',
                href: '/admin/users',
                icon: Shield,
                permission: 'admin.users.manage',
            },
            {
                key: 'roles',
                titleKey: 'app.nav.roles',
                href: '/admin/roles',
                icon: ListTree,
                permission: 'admin.roles.manage',
            },
            {
                key: 'settings',
                titleKey: 'app.nav.settings',
                href: '/admin/settings',
                icon: Settings,
                permission: 'admin.settings.manage',
            },
            // Signup approvals and the action log belong to the approval / activity work; the entries live here
            // because this file is the one navigation source, and their titles come from those lang groups.
            // `/devices` is deliberately absent: naming your own terminal is a personal setting, and a nav entry
            // with no permission would keep the whole Administration group visible to every role.
            {
                key: 'user_approvals',
                titleKey: 'approval.queue.link',
                href: '/admin/users/approvals',
                icon: UserCheck,
                permission: 'admin.users.approve',
            },
            {
                key: 'activity_log',
                titleKey: 'activity.title',
                href: '/activity/actions',
                icon: ScrollText,
                permission: 'activity.view',
            },
        ],
    },
];

export function visibleNavigation(
    groups: NavGroup[],
    check: (permission: string) => boolean,
): NavGroup[] {
    return groups
        .map((g) => ({
            ...g,
            items: g.items.filter((i) => !i.permission || check(i.permission)),
        }))
        .filter((g) => g.items.length > 0);
}

export const NAV_ICON_FALLBACK: LucideIcon = Cog;
