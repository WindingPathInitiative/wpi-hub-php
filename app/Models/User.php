<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Member record. Ports wpi-hub models/user.js.
 *
 * Extends Authenticatable so the custom Cognito guard can resolve it via
 * Auth::user() (where the Node controllers read req.token.get('user')).
 * Cognito/portal field normalization and membership-number generation live in
 * dedicated services (Phase 2 / Phase 5), not on the model.
 *
 * @property int $id
 * @property ?string $portalID
 * @property string $firstName
 * @property string $lastName
 * @property ?string $nickname
 * @property ?string $address
 * @property string $email
 * @property string $membershipType
 * @property string $membershipNumber
 * @property Carbon $membershipExpiration
 * @property ?int $orgUnit
 * @property-read string $fullName
 */
class User extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * Public (non-PII) attributes — the Bookshelf publicAttrs. Serialization
     * gating lives in UserResource; kept here for reference/parity.
     *
     * @var string[]
     */
    public array $publicAttrs = [
        'id',
        'membershipNumber',
        'nickname',
        'membershipType',
        'membershipExpiration',
    ];

    protected function casts(): array
    {
        return [
            'membershipExpiration' => 'date',
        ];
    }

    /**
     * fullName accessor (Node added this in User.parse()).
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => $this->firstName.' '.$this->lastName);
    }

    /**
     * The user's org unit. Named unit() rather than orgUnit() because the FK
     * column is itself called `orgUnit` — an identically named relation method
     * would be shadowed by the attribute on property access. The serialized
     * output still uses the `orgUnit` key (see UserResource).
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'orgUnit');
    }

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class, 'userID');
    }
}
