<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Collections\ZeszytyDoWyboru;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;

/**
 * Adapter HTTP nad `ZeszytyDoWyboru` (#970): tłumaczy żądanie na osobę
 * i trzyma jedno pobranie zeszytów na żądanie w jego atrybutach, żeby karty
 * na jednej stronie nie dokładały zapytań. Domena nie zna `Request`.
 */
final class ZeszytyZZadania
{
    public function __construct(private readonly ZeszytyDoWyboru $zeszyty) {}

    /** @return EloquentCollection<int, Collection> */
    public function dla(Request $request): EloquentCollection
    {
        $user = $request->user();
        if ($user === null) {
            return new EloquentCollection;
        }

        // Karty dzielą jedno pobranie, wyłącznie w bieżącym żądaniu.
        $key = self::class.'.'.$user->getKey();
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $this->zeszyty->dla($user));
        }

        /** @var EloquentCollection<int, Collection> $zeszyty */
        $zeszyty = $request->attributes->get($key);

        return $zeszyty;
    }

    public function publicznyDomyslny(Request $request): ?Collection
    {
        return $this->zeszyty->publicznyDomyslny($this->dla($request));
    }
}
