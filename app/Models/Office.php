<?php

namespace App\Models;

use App\Models\Concerns\HasMaterializedPath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Office (a position governing an org unit, carrying a set of role keys).
 * Ports wpi-hub models/office.js.
 *
 * NOTE: Office's path semantics differ from OrgUnit's, so getParents() and
 * getChildren() are overridden below (the Node model did the same):
 *   - getParents() includes the office itself (no "drop self").
 *   - getChildren() matches a bare "<path>%" prefix (no "." separator).
 * insertWithPath() is shared from HasMaterializedPath.
 *
 * @property int $id
 * @property string $name
 * @property ?string $email
 * @property string $type
 * @property ?int $parentOfficeID
 * @property string $parentPath
 * @property int $parentOrgID
 * @property ?int $userID
 * @property array $roles
 */
class Office extends Model
{
    use HasMaterializedPath;

    protected $table = 'offices';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * roles is a TEXT column holding a JSON array; the cast replaces the manual
     * parse()/format() JSON handling in the Node model.
     */
    protected function casts(): array
    {
        return [
            'roles' => 'array',
        ];
    }

    /**
     * @var string[]
     */
    public array $publicAttrs = [
        'id', 'name', 'type', 'user', 'email', 'roles', 'parentOrgID', 'parentOfficeID', 'userID',
    ];

    public function parentOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'parentOfficeID');
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'parentOrgID');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userID');
    }

    /**
     * Ancestors AND self: every path segment (Office.getParents() does not drop
     * the trailing self id, unlike OrgUnit).
     */
    public function getParents(): Collection
    {
        $segments = explode('.', (string) $this->parentPath);

        return static::query()->whereIn('id', $segments)->get();
    }

    /**
     * Descendants AND self: bare prefix match "<path>%" (Office.getChildren()
     * uses no "." separator, unlike OrgUnit).
     */
    public function getChildren(): Collection
    {
        return static::query()
            ->where('parentPath', 'LIKE', $this->parentPath.'%')
            ->get();
    }
}
