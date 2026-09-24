<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * The whole demo dataset: signups in every approval state, devices and action log, contracts and lots with
 * real ledger history and timelines, machine capacity and a month of allocations, and the mocked Oracle rows.
 *
 * Every child seeder is idempotent — it checks for its own marker and returns — so `db:seed` can be re-run.
 *
 * Model events are switched back on for the duration. DatabaseSeeder runs its children inside
 * `Model::withoutEvents()`, which is right for reference data, but demo data has to behave like real data:
 * Party::booted() computes name_normalized, the Auditable models write the `audits` rows the lot timeline
 * reads, and the insert-only guards stay armed. `audit.console` is raised for the same reason — a seeder runs
 * on the console, where auditing is off by default.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $dispatcher = Model::getEventDispatcher();
        $console = config('audit.console');

        Model::setEventDispatcher($this->container->make('events'));
        config(['audit.console' => true]);

        try {
            $this->call([
                DemoAccessSeeder::class,
                DemoProductionSeeder::class,
                DemoCapacitySeeder::class,
                OracleMockSeeder::class,
            ]);
        } finally {
            config(['audit.console' => $console]);
            if ($dispatcher === null) {
                Model::unsetEventDispatcher();
            } else {
                Model::setEventDispatcher($dispatcher);
            }
        }
    }
}
