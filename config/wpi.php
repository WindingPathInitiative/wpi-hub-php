<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application version
    |--------------------------------------------------------------------------
    |
    | Node read this from package.json (1.0.5) and exposed it on the welcome
    | route, and derived the API prefix from its major component (=> v1).
    |
    */

    'version' => env('WPI_VERSION', '1.0.5'),

    /*
    |--------------------------------------------------------------------------
    | Internal-only endpoint guard
    |--------------------------------------------------------------------------
    |
    | Replaces Node's dual-port trust (middlewares/network.js) with a shared
    | secret presented in the X-Internal-Secret header. Used by the Portal and
    | audit callers on the "(I)" endpoints.
    |
    */

    'internal_secret' => env('INTERNAL_SECRET'),

    'internal_header' => 'X-Internal-Secret',

    /*
    |--------------------------------------------------------------------------
    | Static reference data (ported from wpi-hub config/*.json)
    |--------------------------------------------------------------------------
    |
    | Not secrets — bundled as data. Served by the static read-endpoints
    | (/office/roles, /org-unit/organizations, /org-unit/venues) and consumed
    | by office creation (templates) and membership-number generation
    | (organizations).
    |
    */

    // roles.json — role key => human description.
    'roles' => [
        'user_read_private' => 'Read private user data',
        'user_add' => 'Manually add a user',
        'user_update' => 'Change user data',
        'user_approve' => 'Approve user membership and issue membership number',
        'user_assign' => 'Change user location',
        'user_suspend' => 'Suspend or restore a user',
        'org_update' => 'Change unit information',
        'org_create_region' => 'Create or delete a region',
        'org_create_chapter' => 'Create or delete a chapter',
        'org_create_venue' => 'Create or delete a venue',
        'office_create' => 'Create primary office',
        'office_other_create' => 'Create other office',
        'office_create_assistants' => 'Create assistant office',
        'office_create_own_assistants' => 'Create assistants for their own office',
        'office_update' => 'Change office information',
        'office_assign' => 'Assign an officeholder',
        'vip_view' => 'View VIP for other members',
        'vip_nominate' => 'Nominate other members for VIP',
        'vip_award' => 'Award VIP',
        'checkin_event_create' => 'Create an event',
        'checkin_event_update' => 'Update an event',
        'checkin_event_checkin' => 'Check in members to an event',
        'checkin_event_attendance' => 'View full attendance information for an event',
        'checkin_event_finance' => 'View financial information for an event',
        'character_approve' => 'Run character cycles and approve advancements and downtimes',
    ],

    // templates.json — default offices created per org-unit type.
    'templates' => [
        'rootOffices' => [
            'manager' => 1,
        ],
        'Nation' => [
            'manager' => [
                'name' => 'Club Manager',
                'roles' => [
                    'user_read_private', 'user_add', 'user_update', 'user_assign', 'user_approve',
                    'user_suspend', 'org_update', 'office_update', 'office_assign',
                    'office_create_own_assistants', 'office_create_assistants', 'org_create_chapter',
                    'org_create_venue', 'view_vip', 'nominate_vip', 'award_vip',
                    'checkin_event_create', 'checkin_event_update', 'checkin_event_checkin',
                    'checkin_event_attendance', 'checkin_event_finance',
                ],
            ],
        ],
        'Chapter' => [
            'manager' => [
                'name' => 'Chapter Owner',
                'roles' => [
                    'user_read_private', 'user_update', 'user_assign', 'org_update', 'office_update',
                    'office_assign', 'office_create_own_assistants', 'office_create_assistants',
                    'org_create_venue', 'view_vip', 'nominate_vip', 'award_vip',
                    'checkin_event_create', 'checkin_event_update', 'checkin_event_checkin',
                    'checkin_event_attendance', 'checkin_event_finance',
                ],
            ],
        ],
        'Venue' => [
            'manager' => [
                'name' => 'Game Storyteller',
                'roles' => [
                    'user_read_private', 'org_update', 'office_create_own_assistants', 'office_update',
                    'view_vip', 'nominate_vip', 'checkin_event_create', 'checkin_event_update',
                    'checkin_event_checkin', 'checkin_event_attendance', 'character_approve',
                ],
            ],
        ],
    ],

    // organizations.json — membership-number formats + membership lengths.
    'organizations' => [
        [
            'id' => 'WPI',
            'name' => 'Winding Path Initiative',
            'numberFormat' => [
                ['type' => 'string', 'value' => 'WPI'],
                ['type' => 'year', 'length' => 4],
                ['type' => 'max', 'length' => 4],
            ],
            'approvalState' => 'Full',
            'initialMembershipLength' => ['years' => 1],
            'renewalMembershipLength' => ['years' => 1],
            'attendanceMembershipLength' => ['months' => 6],
        ],
    ],

    // venues.json — venue catalogue.
    'venues' => [
        ['id' => 'BnBVtM', 'name' => 'Blood & Betrayal Vampire the Masquerade', 'type' => 'Nationally Supported', 'wod' => true, 'suffix' => false],
        ['id' => 'BnBWtA', 'name' => 'Blood & Betrayal Werewolf the Apocalypse', 'type' => 'Nationally Supported', 'wod' => true, 'suffix' => false],
        ['id' => 'BnBCtD', 'name' => 'Blood & Betrayal Changeling the Dreaming', 'type' => 'Nationally Supported', 'wod' => true, 'suffix' => false],
        ['id' => 'Huldufolk', 'name' => 'Huldufólk: The Hidden People', 'type' => 'Nationally Directed', 'wod' => false, 'suffix' => false],
        ['id' => 'AbandonSky', 'name' => 'Abandon Sky', 'type' => 'Nationally Directed', 'wod' => false, 'suffix' => false],
        ['id' => 'ChapterInterest', 'name' => 'Chapter Interest', 'type' => 'Chapter Interest', 'wod' => true, 'suffix' => true],
    ],

];
