<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UserException;
use App\Http\Controllers\Concerns\ValidatesLegacy;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfficeResource;
use App\Http\Resources\OrgUnitResource;
use App\Http\Resources\UserResource;
use App\Models\Office;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\AuditService;
use App\Services\OfficeFactoryService;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Org unit routes. Ports wpi-hub controllers/org-unit.js.
 */
class OrgUnitController extends Controller
{
    use ValidatesLegacy;

    public function __construct(
        private readonly PermissionService $perm,
        private readonly OfficeFactoryService $officeFactory,
        private readonly AuditService $audit,
    ) {}

    public function types()
    {
        return response()->json(OrgUnit::getTypes());
    }

    public function venues()
    {
        return response()->json(OrgUnit::getVenues());
    }

    public function organizations()
    {
        return response()->json(config('wpi.organizations'));
    }

    /**
     * Lists units with optional filtering.
     */
    public function index(Request $request)
    {
        $p = collect($request->except('token'))
            ->map(fn ($v) => is_string($v) ? strtolower($v) : $v)
            ->all();

        $types = array_map('strtolower', OrgUnit::getTypes());

        if (filled($p['type'] ?? null) && ! in_array($p['type'], $types, true)) {
            throw new UserException('Invalid type specified', 400);
        }

        $searchTypes = [];
        if (filled($p['types'] ?? null)) {
            $searchTypes = explode(',', $p['types']);
            foreach ($searchTypes as $t) {
                if (! in_array($t, $types, true)) {
                    throw new UserException('Invalid type specified', 400);
                }
            }
        }

        if (filled($p['venue'] ?? null) && ! filled($p['type'] ?? null)) {
            $p['type'] = 'venue';
        } elseif (filled($p['venue'] ?? null) && ($p['type'] ?? null) !== 'venue') {
            throw new UserException('Invalid type with "venue" option', 400);
        }

        if (filled($p['code'] ?? null) && (filled($p['venue'] ?? null) || ($p['type'] ?? null) === 'venue')) {
            throw new UserException('Venue type does not have codes', 400);
        }

        $query = OrgUnit::query();

        if (filled($p['name'] ?? null)) {
            $query->where('name', 'LIKE', '%'.$p['name'].'%');
        }
        if (filled($p['code'] ?? null)) {
            $query->where('code', 'LIKE', '%'.$p['code'].'%');
        }
        if (filled($p['type'] ?? null)) {
            $query->where('type', '=', $p['type']);
        }
        if ($searchTypes) {
            $query->whereIn('type', $searchTypes);
        }
        if (filled($p['parent'] ?? null) && is_numeric($p['parent'])) {
            $parent = $p['parent'];
            $query->where(function ($q) use ($parent) {
                $q->where('parentPath', '=', $parent)
                    ->orWhere('parentPath', 'LIKE', $parent.'.%')
                    ->orWhere('parentPath', 'LIKE', '%.'.$parent)
                    ->orWhere('parentPath', 'LIKE', '%.'.$parent.'.%');
            });
        }
        if (filled($p['venue'] ?? null)) {
            $query->where('venueType', '=', $p['venue']);
        }

        $query->limit(is_numeric($p['limit'] ?? null) ? (int) $p['limit'] : 100);
        if (is_numeric($p['offset'] ?? null)) {
            $query->offset((int) $p['offset']);
        }

        try {
            $units = $query->get();
        } catch (\Throwable $e) {
            throw new UserException('Search failed', 500, $e);
        }

        return OrgUnitResource::collection($units);
    }

    /**
     * Returns a unit with its users, offices, and parent/child chain.
     */
    public function show(Request $request, string $id)
    {
        $unit = $this->resolveUnit($request, $id);

        $includeUsers = $request->query('users') !== '0';
        $includeOffices = $request->query('offices') !== '0';

        if ($includeUsers) {
            $unit->load('users');
        }
        if ($includeOffices) {
            $unit->load(['offices.user']);
        }

        $data = (new OrgUnitResource($unit))->full()->toArray($request);

        if ($includeUsers) {
            $data['users'] = $unit->users
                ->map(fn (User $u) => (new UserResource($u))->toArray($request))
                ->all();
        }
        if ($includeOffices) {
            $data['offices'] = $unit->offices->map(fn ($o) => $this->embeddedOffice($o))->all();
        }

        $parentsDepth = $request->query('parents');
        $childrenDepth = $request->query('children');

        if ($parentsDepth !== '0' || $childrenDepth !== '0') {
            [$parents, $children] = $this->splitChain($unit, $request);
            $data['parents'] = $this->trimParents($parents, $parentsDepth);
            $data['children'] = $this->trimChildren($this->sortChain($children), $childrenDepth);
        }

        // setDepth(0) removes the key entirely.
        if ($parentsDepth === '0') {
            unset($data['parents']);
        }
        if ($childrenDepth === '0') {
            unset($data['children']);
        }

        return response()->json($data);
    }

    /**
     * Creates a new org unit (and its manager office) under a parent.
     */
    public function store(Request $request)
    {
        $data = $request->all();

        if (empty($data)) {
            throw new UserException('No data provided', 400);
        }
        if (empty($data['parentID'])) {
            throw new UserException('No parent provided', 400);
        }

        $types = OrgUnit::getTypes();
        if (! in_array($data['type'] ?? null, $types, true) || ($data['type'] ?? null) === 'Nation') {
            throw new UserException('Invalid org unit type', 400);
        }

        $parent = OrgUnit::find($data['parentID']);
        if ($parent === null) {
            throw new UserException('Parent not found', 400);
        }

        $this->perm->hasOverUnit($parent, 'org_create_'.strtolower($data['type']), $request->user());

        if (array_search($data['type'], $types, true) <= array_search($parent->type, $types, true)) {
            throw new UserException("Org type doesn't match expected type", 400);
        }

        $data['parentPath'] = $parent->parentPath;

        $rules = [
            'name' => 'required|string|min:1',
            'code' => 'required|string|min:1',
            'venueType' => [$data['type'] === 'Venue' ? 'required' : 'nullable', 'string', 'min:1'],
            'location' => 'sometimes|string',
            'defDoc' => 'sometimes|string',
            'website' => 'sometimes|nullable|url',
            'type' => 'required|in:Venue,Chapter,Region',
            'parentPath' => 'required|string|min:1',
        ];
        $attributes = $this->validateLegacy($data, $rules);

        try {
            $unit = DB::transaction(function () use ($attributes) {
                $unit = new OrgUnit($attributes);
                $unit->insertWithPath();
                $this->officeFactory->makeOfficeForUnit($unit, 'manager');
                $unit->load('offices');

                return $unit;
            });
        } catch (UserException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UserException('There was an error creating the org unit', 500, $e);
        }

        $this->audit->log('Creating new org unit', $unit);

        // Node returned the shown unit with its (just-created) offices relation.
        $data = (new OrgUnitResource($unit))->full()->toArray(request());
        $data['offices'] = OfficeResource::collection($unit->offices)->toArray(request());

        return response()->json($data);
    }

    /**
     * Updates an org unit.
     */
    public function update(Request $request, string $id)
    {
        if (empty($request->all())) {
            throw new UserException('No data provided', 400);
        }

        $unit = $this->resolveUnit($request, $id);

        $this->perm->hasOverUnit($unit, 'org_update', $request->user());

        $body = $request->all();
        if (! empty($body['code']) && $body['code'] !== $unit->code) {
            $body['code'] = strtoupper($body['code']);
            $request->merge(['code' => $body['code']]);
            $this->perm->hasOverUnit($unit, 'org_create_'.strtolower($unit->type), $request->user());
        }

        $rules = [
            'name' => 'sometimes|string|min:1',
            'code' => 'sometimes|string|min:1',
            'location' => 'sometimes|string',
            'defDoc' => 'sometimes|string',
            'website' => 'sometimes|nullable|url',
        ];
        if ($unit->type === 'Venue') {
            unset($rules['code']);
        }

        $attributes = $this->validateLegacy($body, $rules);

        $this->audit->log('Updating org unit', $unit, [], $unit->toJson());

        $unit->fill($attributes)->save();

        return (new OrgUnitResource($unit))->full();
    }

    /**
     * Deletes an org unit, reparenting its users and removing its offices.
     */
    public function destroy(Request $request, string $id)
    {
        $unit = $this->resolveUnit($request, $id);

        if ($unit->id === 1) {
            throw new UserException('Cannot delete root org', 500);
        }

        $this->perm->hasOverUnit($unit, 'org_create_'.strtolower($unit->type), $request->user());

        if ($unit->getChildren()->isNotEmpty()) {
            throw new UserException('Cannot delete org with children', 500);
        }

        $this->audit->log('Deleted org unit', $unit, [], $unit->toJson());

        $parents = $unit->parents();
        $parent = array_pop($parents);
        if (! $parent) {
            throw new UserException('No parent found', 500);
        }

        DB::transaction(function () use ($unit, $parent) {
            Office::where('parentOrgID', $unit->id)->delete();
            User::where('orgUnit', $unit->id)->update(['orgUnit' => $parent]);
            $unit->delete();
        });

        return response()->json(['success' => true]);
    }

    /**
     * Resolves a path id (numeric / "me" / code) to a fetched OrgUnit.
     */
    private function resolveUnit(Request $request, string $id): OrgUnit
    {
        if ($id === 'me') {
            $authId = $request->user()?->id;
            $user = User::find($authId);
            if ($user === null) {
                throw new UserException('User not found', 404);
            }
            if (! $user->orgUnit) {
                throw new UserException('No org unit associated', 404);
            }
            $unit = OrgUnit::find($user->orgUnit);
        } elseif (preg_match('/^[a-z]{2}[\-\d]*$/i', $id)) {
            $unit = OrgUnit::where('code', strtoupper($id))->first();
        } elseif ((int) $id) {
            $unit = OrgUnit::find((int) $id);
        } else {
            throw new UserException('Invalid ID provided', 400);
        }

        if ($unit === null) {
            throw new UserException('Org unit not found', 404);
        }

        return $unit;
    }

    /**
     * The embedded-office shape used inside a unit (user summary nested, the
     * id/path fields dropped). Ports the office tap in org-unit show.
     */
    private function embeddedOffice($office): array
    {
        $user = null;
        if ($office->userID) {
            $user = [
                'membershipNumber' => $office->user?->membershipNumber,
                'nickname' => $office->user?->nickname,
                'userID' => $office->userID,
                'id' => $office->userID,
            ];
        }

        return [
            'id' => $office->id,
            'name' => $office->name,
            'type' => $office->type,
            'email' => $office->email,
            'roles' => $office->roles,
            'parentOrgID' => $office->parentOrgID,
            'parentOfficeID' => $office->parentOfficeID,
            'user' => $user,
        ];
    }

    /**
     * @return array{0: array<int,array>, 1: array<int,array>} [parents, children] (full JSON)
     */
    private function splitChain(OrgUnit $unit, Request $request): array
    {
        $parentIds = $unit->parents();
        $parents = [];
        $children = [];

        foreach ($unit->getChain() as $node) {
            $json = (new OrgUnitResource($node))->full()->toArray($request);
            if (in_array($node->id, $parentIds, true)) {
                $parents[] = $json;
            } else {
                $children[] = $json;
            }
        }

        return [$parents, $children];
    }

    /**
     * Builds the children list into a hierarchy by type depth. Ports sortChain.
     */
    private function sortChain(array $units): array
    {
        $types = OrgUnit::getTypes();
        $depth = fn (array $u) => array_search($u['type'], $types, true);

        foreach ($units as &$u) {
            $u['children'] = [];
        }
        unset($u);

        for ($i = count($units) - 1; $i > 0; $i--) {
            if ($units[$i] === false) {
                continue;
            }
            for ($u = $i - 1; $u >= 0; $u--) {
                if ($units[$u] !== false && $depth($units[$i]) > $depth($units[$u])) {
                    $units[$u]['children'][] = $units[$i];
                    $units[$i] = false;
                    break;
                }
            }
        }

        return array_values(array_filter($units, fn ($u) => $u !== false));
    }

    private function trimParents(array $parents, ?string $depth): array
    {
        if ($depth === null || $depth === '-1' || $parents === []) {
            return $parents;
        }
        $depth = (int) $depth;

        return array_slice($parents, count($parents) - $depth);
    }

    private function trimChildren(array $children, ?string $depth): array
    {
        if ($depth === null || $depth === '-1' || $children === []) {
            return $children;
        }
        $depth = (int) $depth;

        $iterate = function (array &$obj, int $cur = 1) use (&$iterate, $depth) {
            if ($cur < $depth) {
                foreach ($obj['children'] as &$c) {
                    $iterate($c, $cur + 1);
                }
            } else {
                unset($obj['children']);
            }
        };
        foreach ($children as &$c) {
            $iterate($c);
        }

        return $children;
    }
}
