<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Access\Approval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The only screen an unapproved account can see.
 *
 * It carries no business data at all — just the account's own status — and it renders under the auth layout,
 * which has no navigation and loads no module data. The approval boundary itself is EnsureUserIsApproved in
 * the `web` group; this page is one of its five allowed route names and adds nothing to that list.
 */
class ApprovalPendingController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // An approved user who lands here (a stale tab, a bookmark) belongs on the dashboard.
        if ($user->isApproved()) {
            return redirect()->intended(config('fortify.home'));
        }

        return Inertia::render('auth/PendingApproval', [
            'status' => $user->approval_status,
            'rejected' => $user->approval_status === Approval::REJECTED,
            'rejectedReason' => $user->approval_status === Approval::REJECTED ? $user->rejected_reason : null,
            'emailVerified' => $user->email_verified_at !== null,
            'email' => $user->email,
        ]);
    }
}
