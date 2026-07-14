<?php

namespace Tests;

use Database\Seeders\TestFixturesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * Base for HTTP feature tests that run against the beta fixtures.
 *
 * Seeds TestFixturesSeeder and freezes the clock to a date when the beta data
 * is valid (its members all expire 2021-01-01). Authentication uses the numeric
 * Authorization dev bypass to act as a specific user id.
 */
abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed inside the per-test transaction (RefreshDatabase migrates/seeds
        // only once globally, which would skip seeding when a non-seeding test
        // class runs first).
        $this->seed(TestFixturesSeeder::class);
        $this->travelTo(TestFixturesSeeder::FROZEN_NOW);
    }

    /**
     * Act as a given user id via the numeric Authorization dev bypass.
     */
    protected function asUser(int $id): static
    {
        return $this->withHeader('Authorization', (string) $id);
    }

    /**
     * Hit an internal-only endpoint with the shared secret presented.
     */
    protected function withInternalSecret(): static
    {
        return $this->withHeader(config('wpi.internal_header'), config('wpi.internal_secret'));
    }

    protected function getJsonAs(int $id, string $uri): TestResponse
    {
        return $this->asUser($id)->getJson($uri);
    }
}
