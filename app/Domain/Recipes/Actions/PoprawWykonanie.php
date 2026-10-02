<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Recipes\Gotowanie\PolaKorekty;
use App\Domain\Recipes\KonfliktPoprawkiWykonania;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\CookedEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Korekta własnego wykonania „Ugotowałem" (#2459): wyłącznie uwaga, opis
 * zmian i rzeczywisty czas. To NIE jest drugi zapis: wykonanie zostaje tym
 * samym wierszem (id, kucharz, przepis, wersja, `cooked_at`, `created_at`,
 * klucz wysłania, zdjęcia, komentarze), więc nie ma nowego powiadomienia
 * `TYPE_COOKED`, ponownej celebracji ani zdarzenia publikacji. Nie wolno tu
 * wołać `RecordCookedEvent`.
 *
 * POD ZAMKIEM, NA ŚWIEŻYM STANIE (jak `EditComment`): konto kucharza, potem
 * wiersz wykonania, `FOR NO KEY UPDATE`. Dopiero tu pytamy ponownie
 * `CookedEventPolicy::update` (sankcja zatwierdzona po `authorize()` w
 * kontrolerze zamyka korektę) i `PolaKorekty` (wskazówka, otwarte zgłoszenie).
 *
 * KONFLIKT. Formularz niesie `CookedEvent::wersjaPolKorekty()` z chwili
 * otwarcia. Inny odcisk niż w bazie, przy innej treści niż zapisana, to
 * `KonfliktPoprawkiWykonania` i żadnego zapisu — starszy formularz nie
 * nadpisze nowszej korekty. Treść identyczna z zapisaną (ponowione żądanie,
 * podwójne kliknięcie) to sukces bez zmiany.
 *
 * Pole zablokowane (`PolaKorekty`) zostaje nietknięte; trafia do wyniku jako
 * pominięte, z powodem, żeby człowiek zobaczył, że nie zapisano go po cichu.
 * Brak klucza w `$dane` znaczy „nie ruszaj"; klucz z `null` — świadome
 * wyczyszczenie opcjonalnego pola.
 */
final class PoprawWykonanie
{
    /**
     * @param  array<string, mixed>  $dane  podzbiór `note`, `changes_note`, `actual_minutes` (po walidacji)
     *
     * @throws KonfliktPoprawkiWykonania
     * @throws BladDlaCzlowieka gdy korekta nie jest już dozwolona
     */
    public function handle(User $aktor, CookedEvent $wykonanie, array $dane, ?string $wersjaFormularza, ?string $ip = null): WynikPoprawkiWykonania
    {
        return DB::transaction(function () use ($aktor, $wykonanie, $dane, $wersjaFormularza, $ip): WynikPoprawkiWykonania {
            $swiezyAktor = User::query()->whereKey($aktor->getKey())->lock('FOR NO KEY UPDATE')->first();
            $swieze = CookedEvent::query()->whereKey($wykonanie->getKey())->lock('FOR NO KEY UPDATE')->first();

            if ($swiezyAktor === null || $swieze === null || Gate::forUser($swiezyAktor)->denies('update', $swieze)) {
                throw new BladDlaCzlowieka('Tego wykonania nie można teraz poprawić.');
            }

            $zablokowane = PolaKorekty::zablokowane($swieze);

            $nowe = [];
            $pominiete = [];

            foreach (PolaKorekty::POLA as $pole) {
                if (! array_key_exists($pole, $dane)) {
                    continue;
                }

                $wartosc = $pole === 'actual_minutes'
                    ? ($dane[$pole] === null || $dane[$pole] === '' ? null : (int) $dane[$pole])
                    : ($dane[$pole] === null || $dane[$pole] === '' ? null : (string) $dane[$pole]);

                if ($wartosc === $swieze->getAttribute($pole)) {
                    continue;
                }

                if (isset($zablokowane[$pole])) {
                    $pominiete[$pole] = $zablokowane[$pole];

                    continue;
                }

                $nowe[$pole] = $wartosc;
            }

            if ($nowe === []) {
                return new WynikPoprawkiWykonania($swieze, [], $pominiete);
            }

            if ($wersjaFormularza === null || ! hash_equals($swieze->wersjaPolKorekty(), $wersjaFormularza)) {
                throw new KonfliktPoprawkiWykonania;
            }

            $swieze->forceFill($nowe + ['poprawiono_at' => now()])->save();

            // Dziennik bez treści: tylko NAZWY poprawionych pól.
            AuditLogEntry::record(
                action: 'cooked_event.edited',
                actor: $swiezyAktor,
                subject: $swieze,
                metadata: ['pola' => array_keys($nowe)],
                ip: $ip,
            );

            return new WynikPoprawkiWykonania($swieze, array_keys($nowe), $pominiete);
        });
    }
}
