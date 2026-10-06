<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Modules\Activity\Concerns\RecordsUserActions;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Access\Approval;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Self-registration. The account is created `pending` explicitly (the column defaults to it too) and is
 * refused everything by EnsureUserIsApproved until a moderator decides. Fortify still logs the user in and
 * sends the verification mail; both are harmless, because being logged in gets them nothing but the
 * "waiting for approval" screen.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules, RecordsUserActions;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'is_active' => true,
        ]);
        $user->approval_status = Approval::PENDING;
        $user->save();
        // Pick up the columns the database defaulted (locale), so the instance Fortify logs in is complete.
        $user->refresh();

        $this->recordAction(ActionKeys::USER_REGISTERED, $user, ['email' => $user->email], $user);

        return $user;
    }
}
