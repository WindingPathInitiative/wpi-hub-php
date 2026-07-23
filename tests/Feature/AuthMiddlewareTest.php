<?php

namespace Tests\Feature;

use App\Http\Middleware\PresetOffice;
use App\Models\User;
use App\Support\PresetOfficeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fresh coverage for the ported auth stack (Cognito guard + dev bypass +
 * EnsureMember + InternalOnly + PresetOffice). The legacy test/auth.js was an
 * obsolete OAuth flow and was dropped (§6 decision).
 */
class AuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'firstName' => 'Test',
            'lastName' => 'User',
            'email' => 'test'.uniqid().'@example.com',
            'membershipType' => 'Full',
            'membershipNumber' => 'WPI20180001',
            'membershipExpiration' => now()->addYear()->toDateString(),
            'orgUnit' => null,
        ], $attrs));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('cognito')->get('/test/me', fn (Request $r) => response()->json(['id' => $r->user()?->id]));
        Route::middleware('cognito:optional')->get('/test/optional', fn (Request $r) => response()->json(['id' => $r->user()?->id]));
        Route::middleware(['cognito', 'member'])->get('/test/member', fn () => response()->json(['ok' => true]));
        Route::middleware('internal')->get('/test/internal', fn () => response()->json(['ok' => true]));
        Route::middleware(['cognito', PresetOffice::class])->get('/test/preset', fn (PresetOfficeContext $c) => response()->json(['office' => $c->get()]));
    }

    // --- dev bypass --------------------------------------------------------

    public function test_numeric_authorization_acts_as_that_user(): void
    {
        $user = $this->makeUser();
        $this->withHeader('Authorization', (string) $user->id)
            ->getJson('/test/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_dev_token_acts_as_user_one(): void
    {
        $this->makeUser(['id' => 1]);
        $this->getJson('/test/me?token=DEV')
            ->assertOk()
            ->assertJsonPath('id', 1);
    }

    public function test_numeric_bypass_to_missing_user_is_invalid_token(): void
    {
        $this->withHeader('Authorization', '999')
            ->getJson('/test/me')
            ->assertStatus(403)
            ->assertJson(['message' => 'Invalid token', 'status' => 403]);
    }

    // --- required vs optional ---------------------------------------------

    public function test_missing_token_is_rejected_when_required(): void
    {
        $this->getJson('/test/me')
            ->assertStatus(403)
            ->assertJson(['message' => 'Authorization Token not provided', 'status' => 403]);
    }

    public function test_missing_token_passes_when_optional(): void
    {
        $this->getJson('/test/optional')
            ->assertOk()
            ->assertJsonPath('id', null);
    }

    // --- JWT path ----------------------------------------------------------

    public function test_valid_jwt_verifies_and_creates_user(): void
    {
        [$token, $iss] = $this->signToken([
            'sub' => 'cognito-sub-123',
            'email' => 'newcomer@example.com',
            'email_verified' => true,
            'name' => 'Ada Lovelace',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/test/me')
            ->assertOk();

        $user = User::where('portalID', 'cognito-sub-123')->first();
        $this->assertNotNull($user);
        $this->assertSame('newcomer@example.com', $user->email);
        $this->assertSame('Ada', $user->firstName);
        $this->assertSame('Lovelace', $user->lastName);
        $this->assertSame('None', $user->membershipType);
    }

    public function test_jwt_without_email_is_rejected(): void
    {
        [$token] = $this->signToken(['sub' => 's', 'email_verified' => true]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/test/me')
            ->assertStatus(403)
            ->assertJson(['message' => 'Token does not include email', 'status' => 403]);
    }

    public function test_jwt_with_unverified_email_is_rejected(): void
    {
        [$token] = $this->signToken(['sub' => 's', 'email' => 'x@example.com', 'email_verified' => false]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/test/me')
            ->assertStatus(403)
            ->assertJson(['message' => 'Token needs email verification', 'status' => 403]);
    }

    public function test_garbage_token_is_invalid_jwt(): void
    {
        config(['cognito.dev_bypass' => false]);
        $this->withHeader('Authorization', 'Bearer not-a-real-jwt')
            ->getJson('/test/me')
            ->assertStatus(403)
            ->assertJson(['message' => 'Invalid jwt', 'status' => 403]);
    }

    // --- EnsureMember ------------------------------------------------------

    public static function nonMemberProvider(): array
    {
        return [
            'none' => ['None', 'User is not a member'],
            'pending' => ['Pending', 'User is not a member'],
            'suspended' => ['Suspended', 'User is suspended'],
            'expelled' => ['Expelled', 'User is expelled'],
            'uninvited' => ['Uninvited', 'User has been declined membership'],
        ];
    }

    #[DataProvider('nonMemberProvider')]
    public function test_ensure_member_rejects_non_members(string $type, string $message): void
    {
        $user = $this->makeUser(['membershipType' => $type]);
        $this->withHeader('Authorization', (string) $user->id)
            ->getJson('/test/member')
            ->assertStatus(403)
            ->assertJson(['message' => $message, 'status' => 403]);
    }

    public function test_ensure_member_rejects_expired(): void
    {
        $user = $this->makeUser(['membershipType' => 'Full', 'membershipExpiration' => '2000-01-01']);
        $this->withHeader('Authorization', (string) $user->id)
            ->getJson('/test/member')
            ->assertStatus(403)
            ->assertJson(['message' => 'User is expired', 'status' => 403]);
    }

    public function test_ensure_member_allows_active_member(): void
    {
        $user = $this->makeUser(['membershipType' => 'Full', 'membershipExpiration' => now()->addYear()->toDateString()]);
        $this->withHeader('Authorization', (string) $user->id)
            ->getJson('/test/member')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    // --- InternalOnly ------------------------------------------------------

    public function test_internal_rejects_without_secret(): void
    {
        $this->getJson('/test/internal')
            ->assertStatus(403)
            ->assertJson(['message' => 'Request over insecure port', 'status' => 403]);
    }

    public function test_internal_allows_with_correct_secret(): void
    {
        $this->withHeader(config('wpi.internal_header'), config('wpi.internal_secret'))
            ->getJson('/test/internal')
            ->assertOk();
    }

    // --- PresetOffice ------------------------------------------------------

    public function test_preset_office_is_captured_from_query(): void
    {
        $user = $this->makeUser();
        $this->withHeader('Authorization', (string) $user->id)
            ->getJson('/test/preset?useOffice=7')
            ->assertOk()
            ->assertJsonPath('office', 7);
    }

    /**
     * Signs a JWS with a fresh RSA key and points the cognito config at its
     * public half, so the verifier can validate a token we control.
     *
     * @return array{0: string, 1: string}
     */
    private function signToken(array $claims): array
    {
        $jwk = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-key']);
        $iss = 'https://test-issuer.example.com';

        config([
            // No jwks_uri: verify against the bundled test key, not a live IdP.
            'cognito.jwks_uri' => null,
            'cognito.jwks' => ['keys' => [$jwk->toPublic()->all()]],
            'cognito.kid' => 'test-key',
            'cognito.iss' => $iss,
        ]);

        $claims = array_merge([
            'iss' => $iss,
            'iat' => time(),
            'exp' => time() + 3600,
        ], $claims);

        $jws = (new JWSBuilder(new AlgorithmManager([new RS256])))
            ->create()
            ->withPayload(json_encode($claims))
            ->addSignature($jwk, ['alg' => 'RS256', 'kid' => 'test-key'])
            ->build();

        return [(new CompactSerializer)->serialize($jws, 0), $iss];
    }
}
