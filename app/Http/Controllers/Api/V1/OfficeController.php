<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UserException;
use App\Http\Controllers\Concerns\ValidatesLegacy;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfficeResource;
use App\Http\Resources\OrgUnitResource;
use App\Http\Resources\UserResource;
use App\Models\Office;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PermissionService;
use Illuminate\Http\Request;

/**
 * Office routes. Ports wpi-hub controllers/office.js.
 */
class OfficeController extends Controller
{
    use ValidatesLegacy;

    public function __construct(
        private readonly PermissionService $perm,
        private readonly AuditService $audit,
    ) {}

    /**
     * Office with its parent office, org unit, holder, and office chain.
     */
    public function show(Request $request, int $id)
    {
        $office = Office::with(['parentOffice', 'orgUnit', 'user'])->find($id);
        if ($office === null) {
            throw new UserException('Office not found', 404);
        }

        $parents = $office->getParents()->reject(fn ($o) => $o->id === $office->id)->values();
        $children = $office->getChildren()->reject(fn ($o) => $o->id === $office->id)->values();

        return response()->json([
            'id' => $office->id,
            'name' => $office->name,
            'email' => $office->email,
            'type' => $office->type,
            'roles' => $office->roles,
            'parentOffice' => $office->parentOffice
                ? (new OfficeResource($office->parentOffice))->toArray($request) : null,
            'orgUnit' => $office->orgUnit
                ? (new OrgUnitResource($office->orgUnit))->toArray($request) : null,
            'user' => $office->user
                ? (new UserResource($office->user))->toArray($request) : null,
            'parents' => OfficeResource::collection($parents)->toArray($request),
            'children' => OfficeResource::collection($children)->toArray($request),
        ]);
    }

    /**
     * Creates a Primary national office (requires admin over org 1).
     */
    public function store(Request $request)
    {
        $this->perm->hasOverUnit(1, 'admin', $request->user());

        $attrs = $this->validateLegacy($request->all(), [
            'name' => 'required|string|min:1',
            'email' => 'sometimes|email',
            'roles' => 'required|array',
            'userID' => 'sometimes|integer',
        ]);

        $office = new Office($attrs);
        $office->type = 'Primary';
        $office->parentOrgID = 1;
        $office->parentPath = '';
        $office->save();
        $office->parentPath = (string) $office->id;
        $office->save();

        return (new OfficeResource($office->fresh()))->full();
    }

    /**
     * Current user's offices.
     */
    public function me(Request $request)
    {
        $offices = Office::where('userID', $request->user()->id)->get();

        return OfficeResource::collection($offices);
    }

    /**
     * Allowed roles (role key => description).
     */
    public function roles()
    {
        return response()->json(config('wpi.roles'));
    }

    /**
     * Assigns a user to an office, or vacates it (user id 0).
     */
    public function assign(Request $request, int $id, int $user)
    {
        $office = Office::find($id);
        if ($office === null) {
            throw new UserException('Office not found', 404);
        }
        if ($user !== 0 && User::find($user) === null) {
            throw new UserException('User not found', 404);
        }

        if ($office->userID === $user) {
            throw new UserException($user ? 'User already officer' : 'Office already vacant', 500);
        }

        // Node intended a "vacating your own office" shortcut here; its literal
        // check compared the User model to an int id (dead code). Implemented as
        // intended: the holder may vacate their own office without office_assign.
        $curUser = $request->user();
        $selfVacate = $user === 0 && $curUser->id === $office->userID;

        if (! $selfVacate) {
            $offices = $this->perm->prefetch($curUser);
            $own = $offices->filter(fn ($o) => $o->id === $office->parentOfficeID);
            if ($own->isNotEmpty()) {
                $this->perm->has('office_create_own_assistants', $own);
            } else {
                $this->perm->hasOverOffice($office, 'office_assign', $offices);
            }
        }

        $this->audit->log('Updated user in office', $office, ['curUser' => $curUser->id]);

        $office->userID = $user ?: null;
        $office->save();

        return response()->json(['success' => true]);
    }

    /**
     * Updates an office's name/email/roles (with role-change validation).
     */
    public function update(Request $request, int $id)
    {
        $body = $request->all();
        if (isset($body['roles']) && is_string($body['roles'])) {
            $body['roles'] = [$body['roles']];
        }

        $attributes = $this->validateLegacy($body, [
            'name' => 'sometimes|string|min:1',
            'email' => 'sometimes|email',
            'roles' => 'sometimes|array',
        ]);

        if (empty($attributes)) {
            throw new UserException('No data provided', 400);
        }

        $office = Office::find($id);
        if ($office === null) {
            throw new UserException('Office not found', 404);
        }

        $usingOffice = $this->perm->hasOverOffice($office, 'office_update', $request->user());
        $officeRoles = $usingOffice->first()->roles ?? [];

        if (isset($body['roles'])) {
            $primaryRoles = null;
            if ($office->parentOfficeID) {
                // Node re-fetched the same office here (a bug); use the actual
                // parent office so an assistant cannot exceed its primary.
                $primaryRoles = Office::find($office->parentOfficeID)?->roles ?? [];
            }
            $this->perm->checkOfficeRoles($body['roles'], $officeRoles, $office->roles ?? [], $primaryRoles);
        }

        $this->audit->log('Updated office', $office, [], $office->toJson());

        $office->fill($attributes)->save();

        return (new OfficeResource($office))->full();
    }

    /**
     * Creates an assistant office under a parent office.
     */
    public function createAssistant(Request $request, int $id)
    {
        $attributes = $this->validateLegacy($request->all(), [
            'name' => 'required|string|min:1',
            'email' => 'sometimes|email',
            'roles' => 'sometimes|array',
        ]);

        if (empty($attributes)) {
            throw new UserException('No data provided', 400);
        }

        $office = Office::find($id);
        if ($office === null) {
            throw new UserException('Parent office not found', 404);
        }

        $offices = $this->perm->prefetch($request->user());
        $own = $offices->filter(fn ($o) => $o->id === $id);
        if ($own->isNotEmpty()) {
            $this->perm->has('office_create_own_assistants', $offices);
        } else {
            $this->perm->hasOverOffice($office, 'office_create_assistants', $offices);
        }

        if (array_diff($attributes['roles'] ?? [], $office->roles ?? []) !== []) {
            throw new UserException('Role not in parent office', 400);
        }

        $assistant = new Office($attributes);
        $assistant->type = 'Assistant';
        $assistant->parentOfficeID = $office->parentOfficeID ?: $office->id;
        $assistant->parentPath = $office->parentPath.'.';
        $assistant->parentOrgID = $office->parentOrgID;
        $assistant->insertWithPath();

        $this->audit->log('Created assistant office', $assistant, ['parent' => $id]);

        // Explicit 200 (Node used res.json); a resource over a freshly created
        // model would otherwise yield 201.
        return response()->json((new OfficeResource($assistant))->full()->toArray($request));
    }

    /**
     * Deletes an assistant office.
     */
    public function deleteAssistant(Request $request, int $id)
    {
        $office = Office::find($id);
        if ($office === null) {
            throw new UserException('Office not found', 404);
        }
        if ($office->type !== 'Assistant') {
            throw new UserException('Office is not an assistant', 400);
        }

        $this->perm->hasOverOffice(
            $office,
            ['office_create_assistants', 'office_create_own_assistants'],
            $request->user()
        );

        $this->audit->log('Deleted assistant office', $office, [], $office->toJson());

        $office->delete();

        return response()->json(['success' => true]);
    }

    public function verifyOrgUnit(Request $request, int $unit)
    {
        return $this->verify($request, fn ($roles) => $this->perm->hasOverUnit($unit, $roles, $request->user()));
    }

    public function verifyUser(Request $request, int $user)
    {
        return $this->verify($request, fn ($roles) => $this->perm->hasOverUser($user, $roles, $request->user()));
    }

    public function verifyOffice(Request $request, int $office)
    {
        return $this->verify($request, fn ($roles) => $this->perm->hasOverOffice($office, $roles, $request->user()));
    }

    /**
     * Shared body for the verify endpoints: requires a roles param, runs the
     * check, and collapses any failure to 403 (as the Node routes did).
     */
    private function verify(Request $request, \Closure $check)
    {
        if (! $request->filled('roles')) {
            throw new UserException('Missing required "roles" param', 400);
        }

        $roles = explode(',', $request->query('roles'));

        try {
            $offices = $check($roles);
        } catch (\Throwable $e) {
            throw new UserException($e->getMessage(), 403);
        }

        return response()->json([
            'success' => true,
            'offices' => OfficeResource::collection($offices)->toArray($request),
        ]);
    }
}
