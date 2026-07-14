<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit service host
    |--------------------------------------------------------------------------
    |
    | Ports wpi-hub config/audit.json. "bypass" disables audit logging entirely
    | (as does the testing environment). Otherwise this is the URL the audit
    | POSTs are sent to.
    |
    */

    'host' => env('AUDIT_HOST', 'bypass'),

];
