<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Widocznosc\WidocznoscTresciSql;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * JEDEN KONTRAKT WIDOCZNOŚCI POWIADOMIEŃ (issue #1687, etap 1).
 *
 * Które powiadomienia ta osoba ma prawo zobaczyć. Z tego samego zawężenia
 * korzystają lista (`NotificationController::index()`), licznik
 * (`User::unreadNotificationsCount()`), „Oznacz wszystkie", pojedyncze
 * otwarcie i eksport danych (`CollectUserExportData`) — wszystkie przez
 * `Notification::scopeVisibleTo()`, który jest tylko wejściem do tej klasy.
 *
 * Do tej zmiany cała ta logika stała w modelu Eloquenta razem z ręczną kopią
 * reguł `PostPolicy`/`RecipePolicy`/`CookedEventPolicy`. Reguły TREŚCI żyją
 * teraz w `App\Domain\Widocznosc\WidocznoscTresciSql`; tutaj zostają reguły,
 * które należą do samego powiadomienia: sprawca, typ służbowy i to, czy
 * komentarz, o którym mowa, wciąż istnieje. Zgodność z Policy pilnuje test
 * kontraktowy `Tests\Feature\Visibility\PowiadomieniaZgodneZPolicyTest`.
 *
 * KOSZT: każdy warunek to `EXISTS`/`NOT EXISTS` w jednym zapytaniu — strona
 * trzydziestu powiadomień nie dokłada zapytań na wiersz (D-196, #833).
 */
final class WidocznoscPowiadomien
{
    /**
     * Powiadomienia, które ta osoba ma prawo zobaczyć — bez tych od osób,
     * z którymi łączy ją blokada.
     *
     * DLACZEGO FILTR PRZY ODCZYCIE, A NIE KASOWANIE PRZY BLOKADZIE
     *
     * `NotifyUser` od początku odmawiał tworzenia NOWYCH powiadomień, gdy
     * między osobami jest blokada. Nie robił jednak nic z tymi, które już
     * leżały na liście — a ludzie blokują właśnie PO nieprzyjemnym zdarzeniu,
     * czyli wtedy, gdy powiadomienie o nim już istnieje. Blokada zostawiała
     * więc na liście nazwisko i zdjęcie osoby, od której człowiek się odciął.
     *
     * Kasowanie wierszy przy blokadzie byłoby nieodwracalne: odblokowanie
     * kogoś ma przywrócić stan sprzed blokady, a nie zostawić dziurę
     * w historii (i w eksporcie danych — RODO art. 15). Dlatego filtrujemy
     * przy odczycie.
     *
     * Blokada liczy się W OBIE STRONY, tak samo jak w
     * `User::hasBlockRelationWith()` — inaczej byłaby ochroną połowiczną.
     *
     * Powiadomienia bez autora (`actor_id IS NULL` — powitanie, wiadomość
     * od moderacji) zostają zawsze: `NOT EXISTS` nie ma wtedy do czego
     * przyrównać `blocked_id` i nie znajduje żadnego wiersza.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function zawez(Builder $query, User $viewer): Builder
    {
        $query->whereNotExists(function (QueryBuilder $sub) use ($viewer): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where(function (QueryBuilder $warunek) use ($viewer): void {
                    $warunek
                        ->where(function (QueryBuilder $ja) use ($viewer): void {
                            $ja->where('blocks.blocker_id', $viewer->getKey())
                                ->whereColumn('blocks.blocked_id', 'notifications.actor_id');
                        })
                        ->orWhere(function (QueryBuilder $on) use ($viewer): void {
                            $on->whereColumn('blocks.blocker_id', 'notifications.actor_id')
                                ->where('blocks.blocked_id', $viewer->getKey());
                        });
                });
        });

        /*
         * ZAWIADOMIENIE SŁUŻBOWE PO ODEBRANIU UPRAWNIEŃ (issue #1351).
         *
         * `appeal.filed` niesie nazwę składającego, rodzaj sprawy i termin —
         * dane z kolejki odwołań, do której wstęp ma tylko czynny
         * administrator. Adresatów wybiera `PowiadomOOdwolaniu` w chwili
         * złożenia odwołania, więc bez tego warunku zawiadomienie zostawało
         * na liście (i w liczniku) po odebraniu roli albo przy zawieszeniu.
         * Pytamy o `isAdmin()` PRZY ODCZYCIE, nie kasujemy wierszy: ponowne
         * nadanie roli albo koniec zawieszenia pokazuje je z powrotem,
         * a retencja tego typu zostaje bez zmian. `actor_id` jest tu NULL,
         * więc filtr sprawcy niżej niczego by nie ukrył.
         */
        if (! $viewer->isAdmin()) {
            $query->where('notifications.type', '!=', Notification::TYPE_APPEAL_FILED);
        }

        /*
         * SPRAWCA ZDARZENIA ZBANOWANY ALBO OZNACZONY DO USUNIĘCIA PO FAKCIE.
         *
         * `UserPolicy::viewProfile()` daje w tym stanie 403 wszystkim poza
         * moderatorem (`User::jestDostepnyJakoAutor()`) — ta reguła w ogóle
         * nie miała odpowiednika tutaj. Nazwa i awatar osoby zbanowanej albo
         * czekającej na usunięcie konta wisiały więc na liście dalej, choć
         * kliknięcie w jej profil kończyło się ścianą. Zawieszenie
         * (`suspended`) CELOWO tu nie wchodzi — to kara za pisanie, a nie za
         * bycie widzianym, i `jestDostepnyJakoAutor()` też ją pomija.
         *
         * Od D-022 lista statusów jest JEDNĄ STAŁĄ
         * (`User::STATUSY_UKRYWAJACE_TRESC`), a nie czwartą kopią tego
         * samego `whereIn`. Powód jest zmierzony: `erased` powstał właśnie
         * dlatego, że dołożenie stanu do jednej warstwy nie dołożyło go do
         * pozostałych. `erased` w tej stałej NIE JEST — powiadomienie
         * o wykonaniu, którego autor wymazał konto, ma zostać widoczne
         * dokładnie tak samo jak samo wykonanie.
         *
         * Powiadomienia bez sprawcy (`actor_id IS NULL`) przechodzą zawsze,
         * z tego samego powodu co przy blokadzie wyżej.
         */
        $query->whereNotExists(function (QueryBuilder $sub): void {
            $sub->selectRaw('1')
                ->from('users as sprawcy')
                ->whereColumn('sprawcy.id', 'notifications.actor_id')
                ->whereIn('sprawcy.status', User::STATUSY_UKRYWAJACE_TRESC);
        });

        /*
         * POWIADOMIENIE O KOMENTARZU, KTÓREGO TREŚĆ ZNIKŁA ALBO DO KTÓREJ
         * ODBIORCA STRACIŁ DOSTĘP.
         *
         * `comment.created`/`comment.replied` istnieją jako wiersze niezależne
         * od komentarza — dlatego SAME W SOBIE nie znikają, kiedy znika
         * komentarz albo treść, pod którą stał: autor mógł go skasować,
         * moderacja mogła go ukryć, a wpis/przepis mógł w międzyczasie zmienić
         * widoczność na węższą (audyt: dokładnie ta usterka, co wpis
         * zbanowanego autora w `FollowingFeed`, tylko na powiadomieniach).
         *
         * Odbiorca takiego powiadomienia NIE musi być właścicielem treści —
         * przy odpowiedzi w cudzym wątku (`PublishComment::handle()`) idzie
         * też do autora komentarza-rodzica. Dlatego widoczność treści liczymy
         * dla KONKRETNEGO odbiorcy ($viewer) specyfikacją
         * `WidocznoscTresciSql` — tą samą, której zgodność z
         * `PostPolicy::view()` / `RecipePolicy::view()` / `CookedEventPolicy::view()`
         * sprawdza test kontraktowy.
         *
         * Inne typy powiadomień (ugotowanie, zapis do zeszytu, obserwowanie...)
         * ten warunek pomija: ich odbiorcą jest zawsze właściciel treści,
         * który widzi własne rzeczy niezależnie od stanu publikacji — dodanie
         * tu tej samej reguły nic by nie zmieniło, a tylko powielałoby kod.
         *
         * ŚWIADOME UPROSZCZENIE: pomijamy furtkę dla moderatora, którą mają
         * Policy (`isModerator()`). Odbiorcą powiadomienia prawie nigdy nie
         * jest moderator, a pominięcie furtki jest OSTRZEJSZE, nie luźniejsze
         * — najwyżej moderator nie zobaczy własnego powiadomienia o cudzym
         * komentarzu na liście (ma do tego panel moderacji), nigdy odwrotnie.
         *
         * ZNANY ROZJAZD Z POLICY (#1378): korzeń wątku nie jest tu sprawdzany,
         * więc odpowiedź pod korzeniem ukrytym przez moderację przechodzi,
         * choć `CommentPolicy::view()` jej odmawia. Test kontraktowy toleruje
         * wyłącznie ten kierunek rozjazdu.
         */
        // UWAGA NA TYP: to jedyne miejsce w tej metodzie, gdzie `where()` woła
        // się WPROST na $query (Eloquent\Builder), a nie w zagnieżdżeniu
        // `whereExists`/`whereNotExists`. `Eloquent\Builder::where(Closure)`
        // ma własne nadpisanie i przekazuje do closure NOWY `Eloquent\Builder`
        // (`$this->model->newQueryWithoutRelationships()`), nie surowy
        // `Illuminate\Database\Query\Builder` — inaczej niż każde inne miejsce
        // w tej klasie. Zły typ tutaj to `TypeError` w runtime, nie błąd SQL.
        $query->where(function (Builder $tylkoIstniejaceTresci) use ($viewer): void {
            $tylkoIstniejaceTresci
                ->whereNotIn('notifications.type', [Notification::TYPE_COMMENT, Notification::TYPE_REPLY])
                ->orWhereExists(function (QueryBuilder $sub) use ($viewer): void {
                    $sub->selectRaw('1')
                        ->from('comments as pc')
                        ->whereRaw("pc.id = (notifications.data->>'comment_id')::uuid")
                        ->where('pc.status', Comment::STATUS_PUBLISHED)
                        ->whereNull('pc.deleted_at')
                        // ISSUE #757: usunięcie komentarza Z ODPOWIEDZIAMI nie robi
                        // soft delete (zostaje `status=published`, `deleted_at=null`),
                        // żeby dzieci nie zawisły bez rodzica — `CommentController::destroy()`
                        // zostawia zamiast tego placeholder i ustawia `body_removed_at`.
                        // Bez tego warunku ta gałąź NIE łapała tej jedynej innej drogi
                        // usunięcia, więc wycinek treści (do 120 znaków) dalej wychodził
                        // w powiadomieniu i w eksporcie danych (`CollectUserExportData`
                        // używa tego samego `visibleTo()`), mimo że treść w wątku jest
                        // już zastąpiona. Od #758 wycinek jest ŻYWY, więc ten sam warunek
                        // stoi drugi raz w `WycinkiKomentarzy::zywe()` — patrz
                        // komentarz tamtej metody: to nie jest powtórka przez przeoczenie.
                        ->whereNull('pc.body_removed_at')
                        ->where(function (QueryBuilder $tresc) use ($viewer): void {
                            $tresc
                                ->where(fn (QueryBuilder $q) => $q->whereExists(
                                    fn (QueryBuilder $s) => WidocznoscTresciSql::wpisLubPrzepis($s, 'posts', 'pc.post_id', $viewer),
                                ))
                                ->orWhere(fn (QueryBuilder $q) => $q->whereExists(
                                    fn (QueryBuilder $s) => WidocznoscTresciSql::wpisLubPrzepis($s, 'recipes', 'pc.recipe_id', $viewer),
                                ))
                                ->orWhere(fn (QueryBuilder $q) => $q->whereExists(
                                    fn (QueryBuilder $s) => WidocznoscTresciSql::wykonanie($s, 'pc.cooked_event_id', $viewer),
                                ));
                        });
                });
        });

        return $query;
    }
}
