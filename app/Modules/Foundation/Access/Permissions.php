<?php

namespace App\Modules\Foundation\Access;

final class Permissions
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_PPC = 'ppc';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_DEPT_INCHARGE = 'dept_incharge';

    public const ROLE_COLOUR_LAB = 'colour_lab';

    public const ROLE_DISPATCH = 'dispatch';

    public const ROLE_VIEWER = 'viewer';

    /** @var list<string> */
    public const ALL = [
        'admin.users.manage', 'admin.users.approve', 'admin.roles.manage', 'admin.settings.manage',
        'masters.view', 'masters.manage',
        'oracle.import.run', 'oracle.import.view',
        'production.route.manage', 'production.issue', 'production.hold', 'production.close', 'production.override',
        'production.sheet.view', 'production.sheet.edit.all', 'production.sheet.edit.own_department',
        'colour.view', 'colour.edit', 'colour.override.approve',
        'dispatch.view', 'dispatch.edit',
        'inquiry.view',
        'reports.all', 'reports.department', 'reports.colour', 'reports.dispatch',
        'audit.view', 'activity.view',
    ];

    /** @var array<string, '*'|list<string>> */
    public const ROLES = [
        self::ROLE_ADMIN => '*',
        self::ROLE_PPC => [
            'masters.view', 'masters.manage', 'oracle.import.run', 'oracle.import.view',
            'production.route.manage', 'production.issue', 'production.hold', 'production.close', 'production.override',
            'production.sheet.view', 'production.sheet.edit.all',
            'colour.view', 'colour.edit', 'colour.override.approve',
            'dispatch.view', 'dispatch.edit', 'inquiry.view', 'reports.all', 'audit.view', 'activity.view',
        ],
        self::ROLE_MANAGER => [
            'masters.view', 'oracle.import.view',
            'production.route.manage', 'production.issue', 'production.hold', 'production.close', 'production.override',
            'production.sheet.view', 'production.sheet.edit.all',
            'colour.view', 'colour.edit', 'colour.override.approve',
            'dispatch.view', 'dispatch.edit', 'inquiry.view', 'reports.all', 'audit.view',
            'admin.users.approve', 'activity.view',
        ],
        self::ROLE_DEPT_INCHARGE => [
            'masters.view', 'production.sheet.view', 'production.sheet.edit.own_department',
            'colour.view', 'inquiry.view', 'reports.department',
        ],
        self::ROLE_COLOUR_LAB => ['masters.view', 'colour.view', 'colour.edit', 'inquiry.view', 'reports.colour'],
        self::ROLE_DISPATCH => ['masters.view', 'colour.view', 'dispatch.view', 'dispatch.edit', 'inquiry.view', 'reports.dispatch'],
        self::ROLE_VIEWER => [
            'masters.view', 'production.sheet.view', 'colour.view', 'dispatch.view', 'inquiry.view', 'reports.all',
        ],
    ];
}
