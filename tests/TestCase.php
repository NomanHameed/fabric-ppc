<?php

namespace Tests;

use App\Modules\OracleSync\Source\OracleSourceConnection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * The mocked Oracle source is a second connection, so RefreshDatabase has to know about it: without this
     * its in-memory database is thrown away between tests (only transacted connections are kept alive) and
     * rows seeded into PROCESSING_LOTS et al. would survive into the next test on MySQL.
     *
     * A property, not a method: Pest applies RefreshDatabase as a trait on the generated subclass, so a trait
     * method would win over a method declared here. The trait reads this property when it exists.
     *
     * @var list<string|null>
     */
    protected $connectionsToTransact = [null, OracleSourceConnection::NAME];

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
