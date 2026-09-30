<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\ZachowaneZdjecia;
use App\Exceptions\BladDlaCzlowieka;
use App\Exceptions\BladZdjecFormularza;
use App\Models\Media;
use App\Models\User;
use App\Support\LimityZdjec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Zdjęcia formularza „Ugotowałem" — wyjęte z `CookedEventController`
 * bez zmiany zachowania (issue #970).
 */
final class ZbierzZdjeciaWykonania
{
    public function __construct(private readonly StoreUploadedImage $storeImage) {}

    /**
     * Zdjęcia do tego wykonania: zachowane po wcześniejszym błędzie plus
     * nowo wgrane, w tej kolejności. Limit liczony na sumie — inaczej dałoby
     * się go obejść, dzieląc zdjęcia między pliki i ukryte pola.
     *
     * @param  mixed  $mediaIds  lista z pola `media_ids` (dane od klienta)
     * @param  array<array-key, UploadedFile>  $pliki  pliki z pola `photos`
     * @return list<string>
     *
     * @throws BladZdjecFormularza gdy suma zdjęć przekracza limit albo plik
     *                             zawiódł; niesie zdjęcia, które formularz
     *                             ma zachować
     */
    public function handle(mixed $mediaIds, array $pliki, User $user): array
    {
        $odzyskane = array_values(array_unique($this->zachowane($mediaIds, $user)
            ->map(fn (Media $media): string => (string) $media->getKey())
            ->all()));

        // LIMIT PRZED ZAPISEM, NIE PO NIM (#2241) — ta sama zasada co
        // `ZbierzZdjeciaFormularza` przy wpisie. Sprawdzenie po
        // `StoreUploadedImage::handle()` zostawiało nowe rekordy `Media`
        // i obiekty w R2, do których formularz nie miał już żadnego
        // odnośnika (`withInput()` bez `media_ids`), a zachowane zdjęcia
        // znikały z formularza razem z nimi. Teraz nic nowego nie powstaje,
        // a zachowane wracają w wyjątku.
        if (count($odzyskane) + count($pliki) > LimityZdjec::maksZdjecNaWysylke()) {
            throw new BladZdjecFormularza(
                LimityZdjec::komunikatZaDuzoZdjec().' Nowych zdjęć nie dodano. Usuń część zachowanych zdjęć albo wybierz mniej nowych.',
                $odzyskane,
            );
        }

        $wszystkie = $odzyskane;

        foreach ($pliki as $photo) {
            try {
                $wszystkie[] = (string) $this->storeImage->handle($user, $photo)->getKey();
            } catch (BladDlaCzlowieka $e) {
                // Plik, który zawiódł, trzeba wybrać ponownie — ale te, które
                // zdążyły się zapisać, wracają do formularza.
                throw new BladZdjecFormularza($e->getMessage(), $wszystkie, $e);
            }
        }

        return array_values(array_unique($wszystkie));
    }

    /**
     * Własne, nieprzypięte zdjęcia z listy od klienta (issue #871, #872).
     *
     * UUID w formularzu to nie autoryzacja (AGENTS.md §7): bramka właściciela
     * i „nieprzypięte do wpisu" stoi w `ZachowaneZdjecia`, a tu dochodzi
     * „nieprzypięte do innego wykonania" — zdjęcie z wczorajszego
     * „Ugotowałem" nie wskakuje do dzisiejszego.
     *
     * @return Collection<int, Media>
     */
    public function zachowane(mixed $mediaIds, ?User $user): Collection
    {
        $zdjecia = ZachowaneZdjecia::wKolejnosci($mediaIds, $user?->getKey());

        if ($zdjecia->isEmpty()) {
            return $zdjecia;
        }

        $przypiete = DB::table('cooked_event_media')
            ->whereIn('media_id', $zdjecia->map(fn (Media $media): string => (string) $media->getKey())->all())
            ->pluck('media_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return $zdjecia
            ->reject(fn (Media $media): bool => in_array((string) $media->getKey(), $przypiete, true))
            ->values();
    }
}
