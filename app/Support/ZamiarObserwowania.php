<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Powrót do osoby po wejściu do serwisu, bez adresu podanego przez gościa. */
final class ZamiarObserwowania
{
    private const KLUCZ = 'follow_intent';

    /** @return array{person: string, recipe: string|null, expires: int}|null */
    public function zZapytania(Request $request): ?array
    {
        $person = $request->query('follow_user');
        $recipe = $request->query('follow_recipe');

        if (! is_string($person) || ! Str::isUuid($person)
            || ($recipe !== null && (! is_string($recipe) || ! preg_match('/\A[a-z0-9-]{1,200}\z/D', $recipe)))) {
            return null;
        }

        $context = ['person' => $person, 'recipe' => $recipe, 'expires' => now()->addHours(2)->timestamp];

        return $this->cel($context) !== null ? $context : null;
    }

    public function zapamietaj(Request $request): void
    {
        if ($request->query->has('follow_user')) {
            $request->session()->forget(self::KLUCZ);
            if ($context = $this->zZapytania($request)) {
                $request->session()->put(self::KLUCZ, $context);
            }
        }
    }

    public function przypiszKonto(Request $request): void
    {
        $context = $request->session()->get(self::KLUCZ);
        if (is_array($context) && $this->cel($context) !== null) {
            $context['owner'] = $request->user()->getKey();
            $request->session()->put(self::KLUCZ, $context);
        }
    }

    public function celPoOnboardingu(Request $request): ?string
    {
        $context = $request->session()->get(self::KLUCZ);

        return is_array($context) && ($context['owner'] ?? null) === $request->user()->getKey()
            ? $this->cel($context, $request->user()) : null;
    }

    public function celDoLogowania(Request $request): ?string
    {
        if ($request->query->has('follow_user')) {
            $this->zapamietaj($request);
        }

        $context = $request->session()->get(self::KLUCZ);

        return is_array($context) ? $this->cel($context) : null;
    }

    /** @param array<string, mixed> $context */
    private function cel(array $context, ?User $viewer = null): ?string
    {
        if (! is_string($context['person'] ?? null) || ! Str::isUuid($context['person'])
            || ! is_int($context['expires'] ?? null) || $context['expires'] <= now()->timestamp) {
            return null;
        }

        $person = User::query()->with('profile')->find($context['person']);
        if (! $person?->isActive() || ! $person->profile
            || ($viewer !== null && $viewer->hasBlockRelationWith($person))) {
            return null;
        }

        $slug = $context['recipe'] ?? null;
        if (is_string($slug) && preg_match('/\A[a-z0-9-]{1,200}\z/D', $slug)) {
            $recipe = Recipe::query()->publiclyVisible()
                ->where('slug', $slug)->where('author_id', $person->getKey())->first();
            if ($recipe !== null) {
                return route('recipes.show', $recipe->slug);
            }
        }

        return route('profile.show', $person->profile->username);
    }
}
