<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Foundation\Access\Approval;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            // Factory users stand in for accounts an administrator created, so they are approved; the states
            // below cover the two signup outcomes.
            'approval_status' => Approval::APPROVED,
            'approved_at' => now(),
            'locale' => 'en',
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** A self-registered account nobody has moderated yet: refused everything by EnsureUserIsApproved. */
    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_status' => Approval::PENDING,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    /** A self-registered account a moderator turned down. */
    public function rejected(string $reason = 'Not a mill employee'): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_status' => Approval::REJECTED,
            'approved_at' => now(),
            'rejected_reason' => $reason,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
