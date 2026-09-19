<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(array_keys(config('app.available_locales')))]]);
        $request->user()->update(['locale' => $data['locale']]);
        $request->session()->put('locale', $data['locale']);

        return back();
    }
}
