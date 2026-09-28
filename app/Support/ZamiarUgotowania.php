<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Recipe;
use App\Models\User;
use App\Policies\RecipePolicy;
use Illuminate\Http\Request;

/** Krótko żyjący powrót z rejestracji do formularza wykonania przepisu. */
final class ZamiarUgotowania
{
    private const KLUCZ = 'cook_intent';

    public function zapamietaj(Request $request): void
    {
        if (! $request->query->has('cook_recipe')) {
            return;
        }

        $request->session()->forget(self::KLUCZ);
        $slug = $request->query('cook_recipe');
        if (! is_string($slug) || preg_match('/\A[a-z0-9-]{1,200}\z/D', $slug) !== 1) {
            return;
        }

        $zamiar = ['recipe' => $slug, 'expires' => now()->addHours(2)->timestamp];
        if ($this->cel($zamiar) !== null) {
            $request->session()->put(self::KLUCZ, $zamiar);
        }
    }

    public function przypiszKonto(Request $request): void
    {
        $zamiar = $request->session()->get(self::KLUCZ);
        if (is_array($zamiar) && $this->cel($zamiar, $request->user()) !== null) {
            $zamiar['owner'] = $request->user()->getKey();
            $request->session()->put(self::KLUCZ, $zamiar);
        }
    }

    public function celPoOnboardingu(Request $request): ?string
    {
        $zamiar = $request->session()->get(self::KLUCZ);

        return is_array($zamiar) && ($zamiar['owner'] ?? null) === $request->user()->getKey()
            ? $this->cel($zamiar, $request->user()) : null;
    }

    /** @param array<string, mixed> $zamiar */
    private function cel(array $zamiar, ?User $widz = null): ?string
    {
        $slug = $zamiar['recipe'] ?? null;
        if (! is_string($slug) || preg_match('/\A[a-z0-9-]{1,200}\z/D', $slug) !== 1
            || ! is_int($zamiar['expires'] ?? null) || $zamiar['expires'] <= now()->timestamp) {
            return null;
        }

        $przepis = Recipe::query()->publiclyVisible()->where('slug', $slug)->first();
        if ($przepis === null) {
            return null;
        }

        $polityka = app(RecipePolicy::class);
        if ($widz === null ? ! $polityka->view(null, $przepis) : ! $polityka->cook($widz, $przepis)) {
            return null;
        }

        return route('cooked.create', $przepis->slug);
    }
}
