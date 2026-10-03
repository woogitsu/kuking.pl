<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Media\ZdjeciaDoPrzypiecia;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\User;
use App\Policies\CookedEventPolicy;
use App\Support\LimityZdjec;
use Illuminate\Support\Facades\DB;

/**
 * Dołączenie zdjęcia do ISTNIEJĄCEGO wykonania „Ugotowałem” (#2500, V2, D-333 — paczka E).
 *
 * To NIE jest drugie gotowanie. Wykonanie zostaje to samo: id, kucharz,
 * przepis, przypięta wersja, `cooked_at`, notatka, komentarze. Akcja:
 *  - NIE woła `RecordCookedEvent`, więc nie ma drugiego `TYPE_COOKED`,
 *    celebracji ani analityki publikacji gotowania;
 *  - dokłada wyłącznie wiersze `cooked_event_media` (kolejne pozycje) i
 *    ustawia `photos_added_at`, który publiczna karta pokazuje jako
 *    „Zdjęcie uzupełnione …”;
 *  - nie zmienia, nie usuwa i nie przestawia dotychczasowych zdjęć.
 *
 * WSZYSTKO POD BLOKADĄ, NA ŚWIEŻYM STANIE (kolejność `media` → `users` →
 * `cooked_events`, jak przy zapisie wykonania): zdjęcia (własność, bez
 * `deleted`/`secured` — `ZdjeciaDoPrzypiecia`), konto kucharza, wiersz
 * wykonania. Prawo sprawdza `CookedEventPolicy::addPhotos` jeszcze raz na tym
 * świeżym stanie (sankcja konta, okno czasowe, dostępność przepisu), więc
 * formularz otwarty godzinę temu nie obejdzie zmiany, która zaszła w
 * międzyczasie. Limit zdjęć liczy zdjęcia JUŻ przypięte i nowe razem; dwie
 * równoległe wysyłki ustawiają się w kolejkę na wierszu wykonania.
 *
 * Ponowienie tej samej wysyłki jest bezpieczne: klucz udanego multipart
 * pozostaje przy wykonaniu także po usunięciu zdjęcia; już przypięte Media
 * również jest pomijane (wynik 0). Zdjęcie przypięte do INNEGO wykonania
 * nie zostaje przejęte. Brak nowego zdjęcia jest ponowieniem tylko wtedy,
 * gdy cały wybór już należy do tego wykonania albo rozpoznano udany klucz.
 * Niedostępny wybór bez takiego dowodu kończy się odmową (#2883).
 */
final class DolaczZdjeciaDoWykonania
{
    /**
     * @param  list<string>  $mediaIds
     * @return int ile zdjęć faktycznie dołączono (0 = rzeczywiste ponowienie)
     *
     * @throws BladDlaCzlowieka
     */
    public function handle(User $kucharz, CookedEvent $wykonanie, array $mediaIds, ?string $kluczWyslania = null): int
    {
        return DB::transaction(function () use ($kucharz, $wykonanie, $mediaIds, $kluczWyslania): int {
            $wlasne = ZdjeciaDoPrzypiecia::zablokuj((string) $kucharz->getKey(), $mediaIds);

            $swiezyKucharz = User::query()->whereKey($kucharz->getKey())->lockForUpdate()->first();
            $swiezeWykonanie = CookedEvent::query()->whereKey($wykonanie->getKey())->lockForUpdate()->first();

            if ($swiezyKucharz === null || $swiezeWykonanie === null) {
                throw new BladDlaCzlowieka('Tego wykonania już nie ma, więc nie można dołączyć do niego zdjęcia.');
            }

            if (! (new CookedEventPolicy)->addPhotos($swiezyKucharz, $swiezeWykonanie)) {
                throw new BladDlaCzlowieka(
                    'Do tego wykonania nie można już dołączyć zdjęcia. Zdjęcie można dołożyć przez '
                    .(int) config('kuking.wykonania.dolaczenie_zdjec_dni').' dni od zapisania wykonania, '
                    .'gdy konto jest w pełni aktywne, a przepis wciąż dostępny. Nic nie zostało zapisane.',
                );
            }

            if ($kluczWyslania !== null && in_array($kluczWyslania, $swiezeWykonanie->photo_submission_keys ?? [], true)) {
                return 0;
            }

            /** @var list<string> $przypiete */
            $przypiete = DB::table('cooked_event_media')
                ->where('cooked_event_id', $swiezeWykonanie->getKey())
                ->orderBy('position')
                ->pluck('media_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();

            $gdzieIndziej = $wlasne === []
                ? []
                : DB::table('cooked_event_media')
                    ->whereIn('media_id', $wlasne)
                    ->where('cooked_event_id', '!=', $swiezeWykonanie->getKey())
                    ->pluck('media_id')
                    ->map(fn (mixed $id): string => (string) $id)
                    ->all();

            // Kolejność wybrana przez człowieka, bez duplikatów, bez tego, co już jest.
            $nowe = array_values(array_filter(
                array_unique($mediaIds),
                fn (string $id): bool => in_array($id, $wlasne, true)
                    && ! in_array($id, $przypiete, true)
                    && ! in_array($id, $gdzieIndziej, true),
            ));

            if ($nowe === []) {
                if ($mediaIds !== [] && array_diff($mediaIds, $przypiete) === []) {
                    return 0;
                }

                throw new BladDlaCzlowieka('Nie udało się dołączyć wybranego zdjęcia. Wybierz zdjęcie ponownie.');
            }

            if (count($przypiete) + count($nowe) > LimityZdjec::maksZdjecNaWysylke()) {
                throw new BladDlaCzlowieka(
                    LimityZdjec::komunikatZaDuzoZdjec().' To wykonanie ma już '.count($przypiete).' zdjęć. Nowych zdjęć nie dołączono.',
                );
            }

            $pozycja = count($przypiete) === 0
                ? 0
                : ((int) DB::table('cooked_event_media')->where('cooked_event_id', $swiezeWykonanie->getKey())->max('position')) + 1;

            foreach ($nowe as $id) {
                $swiezeWykonanie->media()->attach($id, ['position' => $pozycja++]);
            }

            // Zapytaniem po kluczu: bez zdarzeń modelu i bez dotykania innych kolumn.
            $zmiany = ['photos_added_at' => now()];
            if ($kluczWyslania !== null) {
                $zmiany['photo_submission_keys'] = array_merge($swiezeWykonanie->photo_submission_keys ?? [], [$kluczWyslania]);
            }
            CookedEvent::query()->whereKey($swiezeWykonanie->getKey())->update($zmiany);

            return count($nowe);
        });
    }
}
