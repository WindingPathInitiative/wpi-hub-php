<?php

use App\Http\Controllers\Api\V1\CharacterController;
use App\Http\Controllers\Api\V1\CycleController;
use App\Http\Controllers\Api\V1\OfficeController;
use App\Http\Controllers\Api\V1\OrgUnitController;
use App\Http\Controllers\Api\V1\SubmissionController;
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

/*
| Characters and the advancement/downtime cycle (sporebound-character
| "advancedments-plan.md"). Player routes are scoped to the acting user; any
| authenticated user (player) qualifies — no member gate. Storyteller routes
| additionally require `character_approve` over the character's game.
*/
Route::prefix('character')->middleware('cognito')->group(function () {
    Route::get('/', [CharacterController::class, 'index']);
    Route::post('/', [CharacterController::class, 'store']);

    Route::get('/{id}', [CharacterController::class, 'show'])->whereNumber('id');
    Route::put('/{id}', [CharacterController::class, 'update'])->whereNumber('id');

    // Either the owner or a storyteller for the game (checked in-controller).
    Route::put('/{id}/retire', [CharacterController::class, 'retire'])->whereNumber('id');
    Route::put('/{id}/unretire', [CharacterController::class, 'unretire'])->whereNumber('id');

    Route::post('/{id}/submission', [SubmissionController::class, 'store'])->whereNumber('id');
    Route::put('/{id}/submission/{sid}', [SubmissionController::class, 'update'])
        ->whereNumber('id')->whereNumber('sid');
    Route::delete('/{id}/submission/{sid}', [SubmissionController::class, 'destroy'])
        ->whereNumber('id')->whereNumber('sid');
    Route::post('/{id}/submission/{sid}/submit', [SubmissionController::class, 'submit'])
        ->whereNumber('id')->whereNumber('sid');

    // Storyteller direct edits ({character} is what EnsureStoryteller scopes on).
    Route::post('/{character}/anchor', [CharacterController::class, 'addAnchor'])
        ->whereNumber('character')->middleware('storyteller');
    Route::delete('/{character}/anchor/{anchorId}', [CharacterController::class, 'removeAnchor'])
        ->whereNumber('character')->middleware('storyteller');
});

/*
| Cycles and the storyteller queue, hung off the org unit that runs the game.
| Listing cycles is open to players (they need budgets and downtime deadlines).
*/
Route::prefix('org-unit/{unit}')->whereNumber('unit')->middleware('cognito')->group(function () {
    Route::get('/cycles', [CycleController::class, 'index']);

    Route::post('/cycle', [CycleController::class, 'store'])->middleware('storyteller');
    Route::get('/submissions', [SubmissionController::class, 'queue'])->middleware('storyteller');
    Route::get('/characters', [CharacterController::class, 'roster'])->middleware('storyteller');
});

Route::middleware(['cognito', 'storyteller'])->group(function () {
    Route::put('/cycle/{cycle}', [CycleController::class, 'update'])->whereNumber('cycle');
    Route::delete('/cycle/{cycle}', [CycleController::class, 'destroy'])->whereNumber('cycle');

    Route::put('/submission/{submission}/approve', [SubmissionController::class, 'approve'])
        ->whereNumber('submission');
    Route::put('/submission/{submission}/reject', [SubmissionController::class, 'reject'])
        ->whereNumber('submission');
});
