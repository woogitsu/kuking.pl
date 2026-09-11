<?php

declare(strict_types=1);

namespace Tests\Feature\Wyscigi;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „OZNACZ JAKO PRZECZYTANE" JEST ZAPISEM, WIĘC MUSI BYĆ ATOMOWY (issue #276,
 * `docs/DECISIONS.md` D-079).
 *
 * CO BYŁO ZŁAMANE
 * `NotificationController::open()` obiecuje w komentarzu jedną własność:
 * **powtórne kliknięcie nie przesuwa `read_at` w przód**, bo od tego
 * znacznika liczy się retencja powiadomień (`PrzedawnionePowiadomienia`,
 * trzy miesiące — polityka prywatności obiecuje ludziom konkretny okres).
 * Pilnował tego `if ($powiadomienie->isUnread())` — czyli SPRAWDZENIE
 * W PHP na wierszu odczytanym poprzednim zapytaniem, a potem OSOBNY zapis
 * `update … where id = ?`. Między jednym a drugim jest okno.
 *
 * DLACZEGO TO NIE JEST WYŚCIG TEORETYCZNY
 * To jest przycisk, w który człowiek klika DWA RAZY, kiedy strona myśli —
 * zgłoszenie właściciela z 8 września brzmi dosłownie „Znowu wchodzę,
 * patrzę i nic". Drugim uczestnikiem bywa też „oznacz wszystkie jako
 * przeczytane" (`markAllRead()`) z drugiej karty, a od issue #276 ten
 * przycisk stoi na stronie DWA RAZY — nad listą i pod nią. Dwa równoległe
 * żądania na ten sam wiersz to tu zachowanie typowe, nie skrajne.
 *
 * CZEGO TEN TEST NIE DOWODZI (`docs/PULAPKI_TESTOW.md` #6)
 * Nie dowodzi niczego o DWÓCH POŁĄCZENIACH. `RefreshDatabase` trzyma dane
 * w niezatwierdzonej transakcji, więc drugie połączenie ich nie zobaczy,
 * a prawdziwego przeplotu dwóch procesów w PHPUnicie nie odtworzymy. Test
 * wymusza deterministycznie DOKŁADNIE ten przeplot, który był usterką:
 * konkurencyjny zapis wchodzi między `SELECT`-em a `UPDATE`-em, przez
 * `DB::listen()` — tą samą metodą co `EksportDanychRaceTest`,
 * `IdempotencjaZgloszeniaWyscigTest` i `IngredientRaceTest`.
 *
 * Dowód „baza sama pilnuje warunku" jest po stronie kształtu zapisu:
 * `read_at IS NULL` stoi w `WHERE` tego samego `UPDATE`-a, więc spóźnione
 * żądanie zmienia zero wierszy. Nie ma tu osobnego kroku rewalidacji, który
 * ktoś mógłby kiedyś skasować jako „zbędny" — patrz D-079 punkt 2
 * („blokada bez rewalidacji pod nią nie pilnuje niczego").
 */
class OznaczeniePowiadomieniaRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_spozniony_zapis_nie_przesuwa_znacznika_przeczytania(): void
    {
        $odbiorca = $this->user('basiawyscigpowiadomien');
        $powiadomienie = $this->powiadomienieBezCelu($odbiorca);

        // Konkurencyjne żądanie oznacza to powiadomienie DAWNO — 40 dni temu.
        // Data z przeszłości nie jest ozdobą: przy retencji trzymiesięcznej
        // różnica między „przeczytane 40 dni temu" a „przeczytane teraz" to
        // 40 dni dłuższego życia wiersza w bazie. Gdyby konkurent pisał
        // `now()`, obie gałęzie wyglądałyby na zegarku niemal identycznie
        // i test nie odróżniłby naprawy od usterki.
        $dawno = now()->subDays(40)->startOfSecond();

        $wyprzedzony = false;

        DB::listen(function ($query) use (&$wyprzedzony, $powiadomienie, $dawno): void {
            if ($wyprzedzony) {
                return;
            }

            $sql = mb_strtolower($query->sql);

            // Czekamy dokładnie na pytanie „czy to powiadomienie należy do tej
            // osoby" z początku `open()`. Nie na dowolne zapytanie: stronę
            // poprzedzają zapytania o sesję i konto, a po nich przeplot byłby
            // gdzie indziej, niż była usterka.
            if (! str_starts_with(trim($sql), 'select')
                || ! str_contains($sql, '"notifications"')) {
                return;
            }

            $wyprzedzony = true;

            // „Drugie kliknięcie" (albo „oznacz wszystkie" z innej karty)
            // wygrywa wyścig: zapisuje `read_at` w chwili, gdy pierwsze
            // żądanie już odczytało wiersz jako nieprzeczytany. Surowy
            // `UPDATE` omija model, więc nie odpala zdarzeń i nie zapętla
            // nasłuchu.
            DB::table('notifications')
                ->where('id', $powiadomienie->getKey())
                ->update(['read_at' => $dawno]);
        });

        $this->actingAs($odbiorca)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('notifications.index'));

        $this->assertTrue(
            $wyprzedzony,
            'Konkurencyjny zapis nie wszedł między odczyt a zapis — test nie zmierzył wyścigu.',
        );

        $this->assertSame(
            $dawno->format('Y-m-d H:i:s'),
            $powiadomienie->refresh()->read_at?->format('Y-m-d H:i:s'),
            'Spóźnione żądanie przesunęło znacznik przeczytania w przód, mimo że wiersz '
            .'był już oznaczony. Warunek „tylko gdy nieprzeczytane" sprawdzony w PHP nie '
            .'jest gwarancją (D-079) — a od `read_at` zależy, kiedy powiadomienie zniknie z bazy.',
        );
    }

    /**
     * UUID W ADRESIE TO NIE AUTORYZACJA (AGENTS.md §7) — SPRAWDZONE NA NOWYM
     * ZAPISIE, nie na dawnym.
     *
     * `PowiadomienieZobaczOznaczaPrzeczytaneTest` i
     * `PowiadomienieBezCeluDaSieOznaczycTest` mają po jednym takim teście, ale
     * oba sprawdzają wyłącznie to, że żądanie kończy się na 404. Ten sprawdza
     * dodatkowo drugą, nową warstwę: sam `UPDATE` idzie przez relację
     * `notifications()`, czyli ma `user_id` w `WHERE`. Gdyby ktoś kiedyś
     * rozluźnił wyszukanie wiersza na początku metody (np. na `Notification::
     * findOrFail()` — „przecież i tak sprawdzamy niżej"), cudzy wiersz i tak
     * nie wejdzie do zapisu.
     *
     * KONTROLA DODATNIA W TYM SAMYM TEŚCIE (`docs/PULAPKI_TESTOW.md` #4):
     * napastnik oznacza NAJPIERW własne powiadomienie i to się udaje. Bez tego
     * test byłby zielony także wtedy, gdyby trasa była zepsuta dla wszystkich
     * i 404 nie miało nic wspólnego z właścicielstwem.
     */
    public function test_cudzego_powiadomienia_nie_da_sie_oznaczyc_choc_wlasne_sie_da(): void
    {
        $odbiorca = $this->user('basiacudzepowiadomienie');
        $obcy = $this->user('marekcudzepowiadomienie');

        $cudze = $this->powiadomienieBezCelu($odbiorca);
        $wlasne = $this->powiadomienieBezCelu($obcy);

        // KONTROLA DODATNIA: ta sama trasa, ten sam napastnik, własny wiersz.
        $this->actingAs($obcy)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $wlasne))
            ->assertRedirect(route('notifications.index'));

        $this->assertNotNull(
            $wlasne->refresh()->read_at,
            'Kontrola dodatnia: trasa nie oznacza nawet własnego powiadomienia, więc 404 '
            .'niżej nie dowodziłby niczego o właścicielstwie.',
        );

        $this->actingAs($obcy)
            ->post(route('notifications.open', $cudze))
            ->assertNotFound();

        $this->assertNull(
            $cudze->refresh()->read_at,
            'Cudzy identyfikator oznaczył powiadomienie jako przeczytane. UUID w adresie '
            .'to nie autoryzacja (AGENTS.md §7).',
        );
    }

    /**
     * Powiadomienie moderacyjne BEZ adresu docelowego — dokładnie przykład
     * z issue #276 („Sprawdziliśmy Twoje odwołanie. Cofamy decyzję."). Jest
     * tu z rozmysłem: przy braku celu `open()` kończy się `back()`, więc
     * cały test mierzy sam zapis, bez przekierowania na inną stronę.
     */
    private function powiadomienieBezCelu(User $odbiorca): Notification
    {
        return Notification::create([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => null,
            'type' => Notification::TYPE_MODERATION,
            'data' => [
                'title' => 'Sprawdziliśmy Twoje odwołanie. Cofamy decyzję.',
                'message' => 'Uznaliśmy Twoje odwołanie za zasadne i cofamy poprzednią decyzję.',
            ],
        ]);
    }
}
