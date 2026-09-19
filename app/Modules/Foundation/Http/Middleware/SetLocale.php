<?php

namespace App\Modules\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys(config('app.available_locales'));
        $locale = $request->user()?->locale ?? $request->session()->get('locale') ?? config('app.locale');
        App::setLocale(in_array($locale, $available, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
