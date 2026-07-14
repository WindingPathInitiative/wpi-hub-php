<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UserException;
use App\Http\Controllers\Concerns\ValidatesLegacy;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfficeResource;
use App\Http\Resources\OrgUnitResource;
use App\Http\Resources\UserResource;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MembershipNumberService;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * User routes. Ports wpi-hub controllers/user.js.
 *
 * Self-access: the Node self-checks compared the User model to an int id
 * (always false — a bug; show() used .id correctly). Implemented as intended
 * (the acting user may read/update/move their own record). Flagged.
 */
class UserController extends Controller
{
    use ValidatesLegacy;

    public function __construct(
        private readonly PermissionService $perm,
        private readonly AuditService $audit,
        private readonly MembershipNumberService $numbers,
    ) {}

    /**
     * Lists users with optional filtering.
     */
    public function index(Request $request)
    {
        $params = $request->except('token');
        $query = User::query();

        if (filled($params['name'] ?? null)) {
            $like = '%'.$params['name'].'%';
            $query->where(function ($q) use ($like) {
                $q->where('firstName', 'LIKE', $like)
                    ->orWhere('lastName', 'LIKE', $like)
                    ->orWhere('nickname', 'LIKE', $like);
            });
        } elseif (filled($params['email'] ?? null)) {
            $query->where('email', $params['email']);
        } elseif (filled($params['membershipNumber'] ?? null)) {
            $query->where('membershipNumber', $params['membershipNumber']);
        }

        if (isset($params['type'])) {
            $query->where('membershipType', $params['type']);
        }

        if (isset($params['expired'])) {
            $op = $this->bool($params['expired']) ? '<' : '>=';
            $query->where('membershipExpiration', $op, now()->format('Y-m-d'));
        }

        if ((int) ($params['orgUnit'] ?? 0)) {
            $id = (int) $params['orgUnit'];
            $unit = OrgUnit::find($id);
            if ($unit === null) {
                throw new UserException('Org unit not found', 404);
            }
            $ids = $unit->getChildren()->pluck('id')->push($id)->all();
            $query->whereIn('orgUnit', $ids);
        }

        $query->limit(is_numeric($params['limit'] ?? null) ? (int) $params['limit'] : 100);
        if (is_numeric($params['offset'] ?? null)) {
            $query->offset((int) $params['offset']);
        }

        return UserResource::collection($query->get());
    }

    /**
     * Returns a user, optionally with private data, offices, and office chain.
     */
    public function show(Request $request, string $id)
    {
        $auth = $request->user();
        $where = $this->parseId($id, $auth);
        $requestedSelf = $id === 'me' || (is_numeric($id) && (int) $id === $auth->id);

        $showOffices = $this->bool($request->query('offices'));

        $userQuery = User::with('unit')->where($where);
        if ($showOffices) {
            $userQuery->with('offices');
        }
        $user = $userQuery->first();
        if ($user === null) {
            throw new UserException('User not found', 404);
        }

        // Viewing someone else requires an active membership (Node applied the
        // expired gate inline unless the record was your own).
        if (! $requestedSelf) {
            $this->assertActiveMember($auth);
        }

        $showPrivate = $this->bool($request->query('private'));
        if ($user->id === $auth->id) {
            $showPrivate = true;
        } elseif ($showPrivate) {
            try {
                $this->perm->hasOverUser($user, 'user_read_private', $auth);
            } catch (UserException) {
                $showPrivate = false; // just hide private data, don't error
            }
        }

        $resource = (new UserResource($user))->full();
        if ($showPrivate) {
            $resource->withPrivate();
        }
        $data = $resource->toArray($request);

        if ($showOffices) {
            $withChildren = $this->bool($request->query('children'));
            $data['offices'] = $user->offices->map(function ($office) use ($request, $withChildren) {
                $entry = (new OfficeResource($office))->full()->toArray($request);
                if ($withChildren) {
                    $unit = OrgUnit::find($office->parentOrgID);
                    if ($unit) {
                        $entry['unit'] = (new OrgUnitResource($unit))->full()->toArray($request);
                        $entry['children'] = $unit->getChildren()->pluck('id')->all();
                    }
                }

                return $entry;
            })->all();
        }

        return response()->json($data);
    }

    /**
     * Updates a user's basic fields.
     */
    public function update(Request $request, string $id)
    {
        $auth = $request->user();
        $user = $this->fetch($id, $auth, with: ['unit']);

        $attributes = $this->validateLegacy($request->all(), [
            'firstName' => 'sometimes|string|min:1',
            'lastName' => 'sometimes|string|min:1',
            'nickname' => 'sometimes|string',
            'address' => 'sometimes|string',
            'email' => 'sometimes|email',
        ]);

        if (empty($attributes)) {
            throw new UserException('No data provided', 400);
        }

        if ($auth->id !== $user->id) {
            $this->perm->hasOverUser($user, 'user_update', $auth);
        }

        $before = $user->toJson();
        $user->fill($attributes)->save();
        $this->audit->log('Updated user data', $user, [], $before);

        return (new UserResource($user))->full()->withPrivate();
    }

    /**
     * Approves a member: issues a membership number, sets type and expiration.
     */
    public function approve(Request $request, string $id)
    {
        $auth = $request->user();
        $user = $this->fetch($id, $auth);

        if ($user->membershipNumber) {
            throw new UserException('User already a member', 500);
        }

        $this->perm->hasOverUser($user, 'user_approve', $auth);

        $before = $user->toJson();
        $user->fill($this->numbers->prepareApproveMember($request->input('organization')))->save();
        $this->audit->log('Updated user data', $user, [], $before);

        return (new UserResource($user))->full()->withPrivate();
    }

    /**
     * Moves a user to a chapter.
     */
    public function assign(Request $request, string $id, int $chapter)
    {
        $auth = $request->user();
        $user = $this->fetch($id, $auth, with: ['unit']);

        $target = OrgUnit::find($chapter);
        if ($target === null) {
            throw new UserException('Chapter not found', 404);
        }
        if ($target->type === 'Venue') {
            throw new UserException('Cannot assign user to venue', 500);
        }
        if ($user->orgUnit === $target->id) {
            throw new UserException('User already member of chapter', 500);
        }

        if ($auth->id === $user->id) {
            $this->assertSelfAssignable($user, $target);
        } else {
            $offices = $this->perm->prefetch($auth);
            // Node used Promise.any: pass if EITHER check passes.
            try {
                $this->perm->hasOverUser($user, 'user_assign', $offices);
            } catch (UserException $first) {
                $this->perm->hasOverUnit($target, 'user_assign', $offices);
            }
        }

        $this->audit->log('Updated user chapter', $user, ['curOrg' => $user->orgUnit]);
        $user->orgUnit = $target->id;
        $user->save();

        return response()->json(['success' => true]);
    }

    /**
     * Suspends or restores a user.
     */
    public function suspend(Request $request, string $id)
    {
        $auth = $request->user();
        $user = $this->fetch($id, $auth);

        $this->perm->hasOverUser($user, 'user_suspend', $auth);

        $user->membershipType = $this->bool($request->query('restore')) ? 'Full' : 'Suspended';
        $user->save();
        $this->audit->log('Suspended user', $user);

        return (new UserResource($user))->full()->withPrivate();
    }

    /**
     * Manually creates a user under an org unit.
     */
    public function store(Request $request)
    {
        $auth = $request->user();
        $data = $request->all();

        if (empty($data)) {
            throw new UserException('No data provided', 400);
        }
        if (empty($data['orgUnit'])) {
            throw new UserException('No organization unit provided', 400);
        }

        $unit = OrgUnit::find($data['orgUnit']);
        if ($unit === null) {
            throw new UserException('Chapter not found', 404);
        }
        if ($unit->type === 'Venue') {
            throw new UserException('Cannot assign user to venue', 500);
        }

        $this->perm->hasOverUnit($unit, 'user_add', $auth);

        $attributes = $this->validateLegacy($data, [
            'firstName' => 'required|string|min:1',
            'lastName' => 'required|string|min:1',
            'nickname' => 'required|string|min:1',
            'address' => 'sometimes|string|min:1',
            'email' => 'required|email',
        ]);

        if (User::where('email', $data['email'])->exists()) {
            throw new UserException('User with email already exists', 400);
        }

        $member = new User([
            'firstName' => $attributes['firstName'],
            'lastName' => $attributes['lastName'],
            'nickname' => $attributes['nickname'],
            'email' => $attributes['email'],
            'address' => $attributes['address'] ?? null,
            'orgUnit' => (int) $data['orgUnit'],
            'membershipType' => 'None',
            // Node relied on MySQL non-strict defaults here; set them explicitly.
            'membershipNumber' => '',
            'membershipExpiration' => '1000-01-01',
        ]);
        $member->save();
        $this->audit->log('Manually adding new member', $member);

        // Explicit 200 (a resource over a freshly created model yields 201).
        return response()->json((new UserResource($member))->full()->withPrivate()->toArray($request));
    }

    /**
     * Upserts a user from Portal changes. Internal only.
     */
    public function portal(Request $request)
    {
        if (! $request->has('changes') || ! $request->has('id')) {
            throw new UserException('Invalid POST', 400);
        }

        $changes = $request->input('changes');
        $member = User::where('portalID', $request->input('id'))->first() ?? new User;
        $member->portalID = $request->input('id');

        $member->fill([
            'membershipNumber' => $changes['membership_number'] ?? $member->membershipNumber ?? '',
            'firstName' => $changes['firstname'] ?? null,
            'lastName' => $changes['lastname'] ?? null,
            'nickname' => $changes['nickname'] ?? null,
            'email' => $changes['email'] ?? null,
            'address' => "{$changes['address']}\n{$changes['city']}, {$changes['state']} {$changes['zip']}",
            'membershipExpiration' => Carbon::parse($changes['membership_expiration']['date'])->format('Y-m-d'),
            'membershipType' => $changes['type'] ?? null,
        ]);
        $member->save();

        return response()->json((new UserResource($member))->full()->withPrivate()->toArray($request));
    }

    /**
     * Full private record by id or membership number. Internal only.
     */
    public function internal(Request $request, string $id)
    {
        if (preg_match('/^[a-z]{2}\d{10}$/i', $id)) {
            $where = ['membershipNumber' => strtoupper($id)];
        } elseif ((int) $id) {
            $where = ['id' => (int) $id];
        } else {
            throw new UserException('No ID provided', 400);
        }

        $user = User::with(['unit', 'offices'])->where($where)->first();
        if ($user === null) {
            throw new UserException('User not found', 404);
        }

        $data = (new UserResource($user))->full()->withPrivate()->toArray($request);
        $data['orgUnit'] = $user->unit ? (new OrgUnitResource($user->unit))->full()->toArray($request) : null;
        $data['offices'] = OfficeResource::collection($user->offices)->toArray($request);

        return response()->json($data);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Resolves a path id (membership number / numeric / "me") to a where array.
     *
     * @return array<string, mixed>
     */
    private function parseId(string $id, User $auth): array
    {
        if (preg_match('/^[a-z]{2}\d{10}$/i', $id)) {
            return ['membershipNumber' => strtoupper($id)];
        }
        if ((int) $id) {
            return ['id' => (int) $id];
        }
        if ($id === 'me') {
            return ['id' => $auth->id];
        }
        throw new UserException('Invalid ID provided', 400);
    }

    /**
     * @param  string[]  $with
     */
    private function fetch(string $id, User $auth, array $with = []): User
    {
        $user = User::with($with)->where($this->parseId($id, $auth))->first();
        if ($user === null) {
            throw new UserException('User not found', 404);
        }

        return $user;
    }

    /**
     * Self-move rules (ported from the assign self branch).
     */
    private function assertSelfAssignable(User $user, OrgUnit $target): void
    {
        $curOrg = $user->unit; // loaded relation (may be null)

        if (! $user->orgUnit || ($curOrg && $curOrg->id === 1)) {
            return; // unassigned or National: go ahead
        }
        if ($curOrg && $curOrg->type === 'Chapter') {
            throw new UserException('Cannot leave chapter', 403);
        }
        if ($curOrg && ! $target->isChild($curOrg)) {
            throw new UserException('Chapter not under current region', 403);
        }
    }

    /**
     * The EnsureMember gate, applied inline for non-self user reads.
     */
    private function assertActiveMember(User $user): void
    {
        match ($user->membershipType) {
            'None', 'Pending' => throw new UserException('User is not a member', 403),
            'Suspended' => throw new UserException('User is suspended', 403),
            'Expelled' => throw new UserException('User is expelled', 403),
            'Uninvited' => throw new UserException('User has been declined membership', 403),
            default => null,
        };
        if ($user->membershipExpiration->lt(now())) {
            throw new UserException('User is expired', 403);
        }
    }

    private function bool(mixed $value): bool
    {
        return $value === true || $value === '1' || $value === 'true' || $value === 1;
    }
}
