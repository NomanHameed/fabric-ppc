<?php

namespace Database\Factories\Modules\Activity;

use App\Modules\Activity\Models\UserDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UserDevice> */
class UserDeviceFactory extends Factory
{
    protected $model = UserDevice::class;

    public function definition(): array
    {
        return [
            'device_id' => (string) Str::ulid(),
            'computer_name' => strtoupper($this->faker->unique()->bothify('PPC-PC-##')),
            'last_ip_address' => $this->faker->localIpv4(),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'first_seen_at' => now()->subDays(30),
            'last_seen_at' => now(),
        ];
    }

    /** A terminal nobody has named yet. */
    public function unnamed(): static
    {
        return $this->state(fn () => ['computer_name' => null]);
    }
}
