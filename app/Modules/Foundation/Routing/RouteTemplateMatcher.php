<?php

namespace App\Modules\Foundation\Routing;

use App\Modules\Foundation\Models\RouteTemplate;

final class RouteTemplateMatcher
{
    public function match(?string $poType, ?string $qualityDescription): ?RouteTemplate
    {
        return RouteTemplate::query()->where('is_active', true)->get()
            ->filter(fn (RouteTemplate $t) => $this->eligible($t, $poType, $qualityDescription))
            ->sortBy([
                fn ($a, $b) => $b->priority <=> $a->priority,
                fn ($a, $b) => $this->specificity($b) <=> $this->specificity($a),
                fn ($a, $b) => $a->id <=> $b->id,
            ])
            ->first();
    }

    private function eligible(RouteTemplate $t, ?string $poType, ?string $quality): bool
    {
        if ($t->po_type !== null && strcasecmp($t->po_type, (string) $poType) !== 0) {
            return false;
        }

        return $t->quality_pattern === null || $this->like((string) $quality, $t->quality_pattern);
    }

    private function like(string $value, string $pattern): bool
    {
        $regex = '/^'.str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')).'$/isu';

        return (bool) preg_match($regex, $value);
    }

    private function specificity(RouteTemplate $t): int
    {
        return (int) ($t->po_type !== null) + (int) ($t->quality_pattern !== null);
    }
}
