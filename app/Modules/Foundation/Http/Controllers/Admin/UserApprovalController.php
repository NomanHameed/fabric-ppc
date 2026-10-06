<?php

namespace App\Modules\Foundation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Access\Approval;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Services\UserApprovalService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signup moderation. Guarded by `admin.users.approve` on the route; the service is the only writer of the
 * approval columns, and it refuses self-moderation.
 */
class UserApprovalController extends Controller
{
    public function __construct(private readonly UserApprovalService $approvals) {}

    /** The queue of accounts waiting on a decision, oldest first — the signup that has waited longest is on top. */
    public function index(): Response
    {
        $pending = User::query()->where('approval_status', Approval::PENDING)
            ->orderBy('created_at')->get();

        // Where each signup came from: the `user.registered` entry the action log wrote when they submitted
        // the form. It is the only record of the terminal and address behind an account nobody has met yet.
        $signups = UserAction::query()
            ->where('action', ActionKeys::USER_REGISTERED)
            ->where('subject_type', (new User)->getMorphClass())
            ->whereIn('subject_id', $pending->modelKeys())
            ->orderBy('id')
            ->get()
            ->keyBy('subject_id');

        return Inertia::render('admin/Approvals', [
            'pending' => $pending->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'email_verified' => $u->email_verified_at !== null,
                'registered_at' => $u->created_at?->toIso8601String(),
                'ip_address' => $signups->get($u->id)?->ip_address,
                'computer_name' => $signups->get($u->id)?->computer_name,
            ])->values(),
        ]);
    }

    public function approve(Request $request, User $user): RedirectResponse
    {
        $this->assertMayModerate($request, $user);

        $this->run(fn () => $this->approvals->approve($user, $request->user()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('approval.approved')]);

        return back();
    }

    public function reject(Request $request, User $user): RedirectResponse
    {
        $this->assertMayModerate($request, $user);

        $data = $request->validate(['rejected_reason' => ['required', 'string', 'max:200']]);

        $this->run(fn () => $this->approvals->reject($user, $request->user(), $data['rejected_reason']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('approval.rejected_done')]);

        return back();
    }

    /**
     * The Task-14 privilege boundary (HANDOFF §7), mirrored from UserController: only an admin may act on
     * another admin's account. `admin.users.approve` is held by the Manager role and is deliberately weaker
     * than `admin.users.manage`, so without this a manager could decide an administrator's account.
     */
    private function assertMayModerate(Request $request, User $user): void
    {
        abort_if(
            ! $request->user()->hasRole(Permissions::ROLE_ADMIN) && $user->hasRole(Permissions::ROLE_ADMIN),
            403,
        );
    }

    /** Domain refusals reach the form as a field error, not only as a toast. */
    private function run(callable $callback): void
    {
        try {
            $callback();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['approval_status' => $e->getMessage()]);
        }
    }
}
