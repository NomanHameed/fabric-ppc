<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Activity\Models\UserDevice;
use App\Modules\Activity\Services\ActionLogger;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Access\Approval;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Services\UserApprovalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Signups in every approval state, the terminals they came from, and the action log entries that go with them.
 *
 * Deliberately on its own email domain (`@signup.test`), so the moderation demo never mixes with the seeded
 * role logins on `@fabric.local`. Every value is fixed — device ids included — so two runs produce the same
 * database.
 */
class DemoAccessSeeder extends Seeder
{
    public const DOMAIN = 'signup.test';

    public const PASSWORD = 'password';

    /** Fixed ULIDs: a demo device keeps its identity across `migrate:fresh --seed`. */
    public const DEVICES = [
        '01JB0000000000000000000001' => 'PPC-OFFICE-01',
        '01JB0000000000000000000002' => 'PPC-OFFICE-02',
        '01JB0000000000000000000003' => 'STENTER-FLOOR',
        '01JB0000000000000000000004' => 'JIGGER-FLOOR',
        '01JB0000000000000000000005' => 'DISPATCH-DESK',
        '01JB0000000000000000000006' => 'LAB-BENCH',
        '01JB0000000000000000000007' => null,   // a terminal nobody has named yet
    ];

    /** email local part => [name, status, days ago they signed up, verified?, rejection reason] */
    public const SIGNUPS = [
        'asif.mehmood' => ['Asif Mehmood', Approval::PENDING, 1, true, null],
        'rabia.noor' => ['Rabia Noor', Approval::PENDING, 2, true, null],
        'kamran.ali' => ['Kamran Ali', Approval::PENDING, 4, true, null],
        'unverified.saad' => ['Saad Iqbal', Approval::PENDING, 6, false, null],
        'ex.contractor' => ['Imran Shah', Approval::REJECTED, 20, true, 'Contract ended; no longer with the mill'],
        'wrong.person' => ['Nadia Bashir', Approval::REJECTED, 25, true, 'Not a mill employee'],
        'approved.zaid' => ['Zaid Hussain', Approval::APPROVED, 12, true, null],
        'approved.hina' => ['Hina Farooq', Approval::APPROVED, 15, true, null],
    ];

    /**
     * The same table as the constant, typed. Read through this rather than the constant directly: the literal
     * values in a class constant are narrowed to their exact strings, which makes every status comparison in
     * the loop below look like dead code to static analysis.
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: bool, 4: string|null}>
     */
    public static function signups(): array
    {
        return self::SIGNUPS;
    }

    public function run(): void
    {
        // Idempotent: DatabaseSeeder may be run repeatedly, and the action log is insert-only.
        if (User::query()->where('email', 'like', '%@'.self::DOMAIN)->exists()) {
            return;
        }

        $devices = $this->devices();
        $moderator = User::query()->where('email', DemoUsersSeeder::emailFor(Permissions::ROLE_MANAGER))->first()
            ?? User::query()->orderBy('id')->firstOrFail();

        $approvals = app(UserApprovalService::class);
        $logger = app(ActionLogger::class);
        $i = 0;

        foreach (self::signups() as $local => [$name, $status, $daysAgo, $verified, $reason]) {
            $signedUpAt = CarbonImmutable::now()->subDays($daysAgo)->setTime(9, 15);
            $device = $devices[$i % count($devices)];
            $i++;

            $user = new User([
                'name' => $name,
                'email' => $local.'@'.self::DOMAIN,
                'password' => self::PASSWORD,
                'email_verified_at' => $verified ? $signedUpAt->addMinutes(4) : null,
            ]);
            $user->approval_status = Approval::PENDING;
            $user->forceFill(['created_at' => $signedUpAt, 'updated_at' => $signedUpAt])->save();

            $logger->record(
                ActionKeys::USER_REGISTERED, $user, ['email' => $user->email], $user,
                $device, '10.20.30.'.(40 + $i), 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', $signedUpAt,
            );

            if ($status === Approval::APPROVED) {
                $approvals->approve($user, $moderator);
                $user->forceFill(['approved_at' => $signedUpAt->addDay()])->save();
                $user->assignRole(Permissions::ROLE_VIEWER);
            } elseif ($status === Approval::REJECTED) {
                $approvals->reject($user, $moderator, (string) $reason);
                $user->forceFill(['approved_at' => $signedUpAt->addDay()])->save();
            }
        }
    }

    /**
     * The terminals, each tied to the demo login that uses it.
     *
     * @return list<UserDevice>
     */
    private function devices(): array
    {
        $roles = array_keys(DemoUsersSeeder::USERS);
        $devices = [];
        $i = 0;

        foreach (self::DEVICES as $deviceId => $computerName) {
            $owner = User::query()->where('email', DemoUsersSeeder::emailFor($roles[$i % count($roles)]))->first();
            $i++;

            $devices[] = UserDevice::query()->firstOrCreate(['device_id' => (string) $deviceId], [
                'computer_name' => $computerName,
                'user_id' => $owner?->getKey(),
                'last_ip_address' => '10.20.30.'.(10 + $i),
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'first_seen_at' => CarbonImmutable::now()->subDays(60),
                'last_seen_at' => CarbonImmutable::now()->subHours($i),
            ]);
        }

        return $devices;
    }
}
