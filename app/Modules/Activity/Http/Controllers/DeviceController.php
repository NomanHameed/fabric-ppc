<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Concerns\RecordsUserActions;
use App\Modules\Activity\Services\DeviceRegistrar;
use App\Modules\Activity\Support\ActionKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registering the computer name for the machine the request came from — Oracle's `COMPUTER`, the part a human
 * recognises. The device itself is never chosen by the client: it is whatever TrackDevice resolved from the
 * signed cookie, so a user can only name their own terminal.
 */
class DeviceController extends Controller
{
    use RecordsUserActions;

    public function __construct(private readonly DeviceRegistrar $devices) {}

    /** The "this computer" screen: one field, reachable from the nav and from the unnamed-device prompt. */
    public function edit(Request $request): Response
    {
        $device = $this->devices->resolve($request);

        return Inertia::render('activity/Device', [
            'device' => [
                'device_id' => $device->device_id,
                'computer_name' => $device->computer_name,
                'last_seen_at' => $device->last_seen_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Whether this terminal has been named yet, for the dismissible prompt. Deliberately says nothing about
     * any other device: a caller only ever learns about the machine it is sitting at.
     */
    public function current(Request $request): JsonResponse
    {
        $device = $this->devices->resolve($request);

        return response()->json([
            'device_id' => $device->device_id,
            'computer_name' => $device->computer_name,
            'named' => $device->computer_name !== null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // 60, not Oracle's 15: hostnames here are longer than Oracle's column. Anything exported to Oracle
            // has to be truncated there, not shortened for everyone here.
            'computer_name' => ['required', 'string', 'max:60'],
        ]);

        $device = $this->devices->resolve($request);
        $this->devices->name($device, $data['computer_name'], $request->user());

        $this->recordAction(ActionKeys::DEVICE_NAMED, $device, ['computer' => $data['computer_name']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('activity.device.saved')]);

        return back();
    }
}
