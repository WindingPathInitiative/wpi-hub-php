<?php

use App\Http\Controllers\Api\V1\OfficeController;
use App\Http\Controllers\Api\V1\OrgUnitController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (mounted under /v1 via bootstrap/app.php apiPrefix)
|--------------------------------------------------------------------------
|
| Ports controllers/index.js. The Node app grouped routes by resource:
|   /v1/user       -> controllers/user.js   (Phase 5)
|   /v1/org-unit   -> controllers/org-unit.js (Phase 4)
|   /v1/office     -> controllers/office.js  (Phase 4)
|
| Internal-only routes (the Node "(I)" endpoints) are guarded by a
| shared-secret middleware instead of the legacy dual-port trust (Phase 2).
| Routes are added per-phase as controllers land.
|
*/

Route::get('/', fn () => response()->json([
    'message' => 'Welcome to the user hub',
    'version' => config('wpi.version'),
]));

/*
| Org units (controllers/org-unit.js). Static reference routes need no token;
| list/show require a token; mutations also require an active member.
*/
Route::prefix('org-unit')->group(function () {
    Route::get('/types', [OrgUnitController::class, 'types']);
    Route::get('/venues', [OrgUnitController::class, 'venues']);
    Route::get('/organizations', [OrgUnitController::class, 'organizations']);

    Route::get('/', [OrgUnitController::class, 'index'])->middleware('cognito');
    Route::get('/{id}', [OrgUnitController::class, 'show'])->middleware('cognito');

    Route::post('/', [OrgUnitController::class, 'store'])->middleware(['cognito', 'member']);
    Route::put('/{id}', [OrgUnitController::class, 'update'])->middleware(['cognito', 'member']);
    Route::delete('/{id}', [OrgUnitController::class, 'destroy'])->middleware(['cognito', 'member']);
});

/*
| Offices (controllers/office.js). verify/* mirror the Node routes, which were
| NOT port-restricted in code despite Migrate.md marking them internal.
*/
Route::prefix('office')->group(function () {
    Route::get('/roles', [OfficeController::class, 'roles']);
    Route::get('/me', [OfficeController::class, 'me'])->middleware('cognito');

    Route::get('/verify/orgunit/{unit}', [OfficeController::class, 'verifyOrgUnit'])
        ->whereNumber('unit')->middleware(['cognito', 'member']);
    Route::get('/verify/user/{user}', [OfficeController::class, 'verifyUser'])
        ->whereNumber('user')->middleware(['cognito', 'member']);
    Route::get('/verify/office/{office}', [OfficeController::class, 'verifyOffice'])
        ->whereNumber('office')->middleware(['cognito', 'member']);

    Route::post('/', [OfficeController::class, 'store'])->middleware('cognito');

    Route::put('/{id}/assign/{user}', [OfficeController::class, 'assign'])
        ->whereNumber('id')->whereNumber('user')->middleware(['cognito', 'member']);
    Route::post('/{id}/assistant', [OfficeController::class, 'createAssistant'])
        ->whereNumber('id')->middleware(['cognito', 'member']);
    Route::delete('/{id}/assistant', [OfficeController::class, 'deleteAssistant'])
        ->whereNumber('id')->middleware(['cognito', 'member']);

    Route::get('/{id}', [OfficeController::class, 'show'])->whereNumber('id')->middleware('cognito');
    Route::put('/{id}', [OfficeController::class, 'update'])->whereNumber('id')->middleware(['cognito', 'member']);
});

/*
| Users (controllers/user.js). portal + {id}/internal are internal-only
| (shared-secret guard).
*/
Route::prefix('user')->group(function () {
    Route::get('/', [UserController::class, 'index'])->middleware('cognito');
    Route::post('/portal', [UserController::class, 'portal'])->middleware('internal');
    Route::post('/', [UserController::class, 'store'])->middleware(['cognito', 'member']);

    Route::get('/{id}/internal', [UserController::class, 'internal'])->middleware('internal');
    Route::put('/{id}/approve', [UserController::class, 'approve'])->middleware(['cognito', 'member']);
    Route::put('/{id}/assign/{chapter}', [UserController::class, 'assign'])
        ->whereNumber('chapter')->middleware(['cognito', 'member']);
    Route::put('/{id}/suspend', [UserController::class, 'suspend'])->middleware(['cognito', 'member']);

    Route::get('/{id}', [UserController::class, 'show'])->middleware('cognito');
    Route::put('/{id}', [UserController::class, 'update'])->middleware(['cognito', 'member']);
});
