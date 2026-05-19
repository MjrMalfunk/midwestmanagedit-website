<?php
return [
    'provider' => 'm365',
    'calendar_user' => 'scheduling@midwestmanagedit.com',
    'tenant_id' => '6b2aa02f-6b5b-4fec-aa27-7878f3ff3fc7',
    'client_id' => 'eb977399-9ada-48bc-872d-091d8c3bc644',
    'client_secret' => '~3e8Q~Yd-rTF-cSvbi.7w_wzUS1CNd8i04T1vdAc',
    'timezone' => 'America/Indiana/Indianapolis',
    'timezone_label' => 'Eastern Time',
    'graph_timezone' => 'Eastern Standard Time',
    'meeting' => [
        'title_prefix' => 'Schedule a Chat - ',
        'duration_minutes' => 30,
        'buffer_minutes' => 15,
        'min_notice_minutes' => 120,
        'max_days_ahead' => 21,
        'max_visible_days' => 10,
        'location' => 'Online / Phone',
        'create_online_meeting' => true,
        'online_meeting_provider' => 'teamsForBusiness',
    ],
    'working_hours' => [
        1 => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:30']],
        2 => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:30']],
        3 => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:30']],
        4 => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:30']],
        5 => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:30']],
    ],
    'blackout_dates' => [
        // '2026-05-25',
    ],
    'blackout_ranges' => [
        // ['start' => '2026-05-20T10:00:00-04:00', 'end' => '2026-05-20T12:00:00-04:00'],
    ],
];
