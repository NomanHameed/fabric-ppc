<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\I18n\TranslationRepository;
use Illuminate\Http\JsonResponse;

class TranslationController extends Controller
{
    public function show(string $locale, TranslationRepository $repo): JsonResponse
    {
        abort_unless(array_key_exists($locale, config('app.available_locales')), 404);

        return response()->json($repo->all($locale))
            ->setPublic()->setMaxAge(31536000)->setEtag($repo->version($locale));
    }
}
