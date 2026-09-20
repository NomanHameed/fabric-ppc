<?php

namespace App\Modules\Foundation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\RouteTemplate;
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
                'default_route_template_id' => Setting::get('default_route_template_id'),
            ],
            'routeTemplates' => RouteTemplate::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (RouteTemplate $t) => ['value' => $t->id, 'label' => $t->name])->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'idle_lot_alert_days' => ['required', 'integer', 'between:1,60'],
            'default_route_template_id' => ['nullable', 'integer', 'exists:route_templates,id'],
        ]);
        foreach ($data as $key => $value) {
            Setting::put($key, match ($key) {
                'idle_lot_alert_days' => (int) $value,
                'default_route_template_id' => $value === null ? null : (int) $value,
                default => $value,
            });
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }
}
