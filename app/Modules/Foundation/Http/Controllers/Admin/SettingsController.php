<?php

namespace App\Modules\Foundation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => [
                'company_name' => Setting::get('company_name', 'Fabric PPC'),
                'idle_lot_alert_days' => Setting::get('idle_lot_alert_days', 3),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'idle_lot_alert_days' => ['required', 'integer', 'between:1,60'],
        ]);
        foreach ($data as $key => $value) {
            Setting::put($key, $key === 'idle_lot_alert_days' ? (int) $value : $value);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }
}
