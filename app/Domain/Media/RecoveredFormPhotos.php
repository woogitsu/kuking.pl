<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Models\Media;
use App\Models\User;
use App\Support\LimityZdjec;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class RecoveredFormPhotos
{
    /** @return Collection<int, Media> */
    public static function forUser(User $user, mixed $input): Collection
    {
        // Stare wejście pochodzi od klienta, także po odrzuceniu walidacji.
        $ids = is_array($input) ? array_slice($input, 0, LimityZdjec::maksZdjecNaWysylke()) : [];
        $ids = array_values(array_filter($ids, fn ($id): bool => is_string($id) && Str::isUuid($id)));
        $query = Media::query()->whereIn('id', $ids)
            ->where('owner_id', $user->getKey())
            ->where('status', '!=', Media::STATUS_DELETED);

        foreach (KasujZdjecie::ODWOLANIA as [$table, $column]) {
            $query->whereNotExists(function ($sub) use ($table, $column): void {
                $sub->selectRaw('1')->from($table)->whereColumn("{$table}.{$column}", 'media.id');
            });
        }

        return $query->get()->sortBy(fn (Media $media) => array_search($media->getKey(), $ids, true))->values();
    }
}
