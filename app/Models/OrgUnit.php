<?php

namespace App\Models;

use App\Models\Concerns\HasMaterializedPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Organizational unit. Ports wpi-hub models/org-unit.js.
 *
 * Hierarchy (parents/getParents/getChildren/getChain/isChild/hasChild/
 * insertWithPath) comes from the HasMaterializedPath trait.
 *
 * @property int $id
 * @property string $name
 * @property ?string $code
 * @property ?string $venueType
 * @property ?string $location
 * @property ?string $website
 * @property ?string $type
 * @property ?string $defDoc
 * @property string $parentPath
 */
class OrgUnit extends Model
{
    use HasMaterializedPath;

    protected $table = 'org_units';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * Ordered org-unit types, highest to lowest. The index doubles as "depth"
     * (getDepth in the Node model) for chain trimming.
     *
     * @var string[]
     */
    public const TYPES = ['Nation', 'Region', 'Chapter', 'Venue'];

    /**
     * @var string[]
     */
    public array $publicAttrs = ['id', 'name', 'code', 'type', 'venueType', 'location'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'orgUnit');
    }

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class, 'parentOrgID');
    }

    /**
     * @return string[]
     */
    public static function getTypes(): array
    {
        return self::TYPES;
    }

    /**
     * Venue catalogue (config/venues.json -> config/wpi.php in Phase 6).
     */
    public static function getVenues(): array
    {
        return config('wpi.venues', []);
    }
}
