<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\WejscieLinkiemWycofane;
use App\Domain\Users\ZamekKonta;
use App\Models\AuditLogEntry;
use App\Models\LoginLinkToken;
use App\Models\User;
use Throwable;

/**
 * Zużycie tokenu logowania linkiem — blokady, rewalidacja i dziennik audytu
 * w jednej transakcji jako nazwany przypadek użycia.
 *
 * Wyjęte z `Auth\LoginLinkController::store()` bez zmiany zachowania (issue
 * #970): ta sama kolejność blokad (konto, potem token — D-075, D-079), te same
 * rozstrzygnięcia i ten sam wpis w transakcji. Kontroler zostaje przy HTTP:
 * włączenie funkcji, sesja, 2FA i odpowiedź.
 *
 * Zwraca konto, które wolno wpuścić (token skasowany), albo `null`, gdy link
 * jest nieaktualny z któregokolwiek powodu — kontroler pokazuje wtedy JEDEN
 * komunikat, żeby różnica w treści nie była wyrocznią „ten token istniał".
 * Awaria dziennika audytu daje `WejscieLinkiemWycofane` po cofnięciu
 * transakcji (link nadal ważny); każdy inny wyjątek leci bez zmian.
 */
final class ZuzyjLinkDoLogowania
{
    /**
     * @throws WejscieLinkiemWycofane
     */
    public function handle(string $token, ?string $ip): ?User
    {
        /*
         * ZUŻYCIE TOKENU IDZIE POD DWIEMA BLOKADAMI: NAJPIERW WIERSZ KONTA,
         * POTEM WIERSZ TOKENU. TA KOLEJNOŚĆ JEST OBOWIĄZKOWA (D-075, D-079).
         *
         * `lockForUpdate()` na wierszu tokenu nie jest ostrożnością na zapas.
         * Bez niego dwa równoległe żądania z tym samym tokenem (dwa
         * kliknięcia, podwójne wysłanie formularza, skaner i człowiek naraz)
         * mogłyby OBA odczytać wiersz, OBA uznać go za ważny i OBA zalogować
         * — czyli token „jednorazowy" wpuściłby dwa razy. Kasujemy w tej
         * samej transakcji, więc drugie żądanie zastaje albo blokadę, albo
         * pustkę.
         *
         * DLACZEGO JESZCZE BLOKADA KONTA, SKORO SAMO ZUŻYCIE JEJ NIE
         * POTRZEBUJE. Bo `WyslijLinkDoLogowania::wymienToken()` bierze te
         * same dwie tabele w kolejności `users` → `login_link_tokens`
         * (D-075: „KOLEJNOŚĆ BLOKAD ZOSTAJE JEDNA W CAŁYM REPOZYTORIUM:
         * KONTO NAJPIERW"). Ta metoda była jedynym miejscem w repozytorium,
         * które tę kolejność odwracało — i nie bolało to wyłącznie dlatego,
         * że w tej transakcji nie było ANI JEDNEJ linijki dotykającej
         * `users`. Bezpieczeństwo stało więc na NIEOBECNOŚCI jednej linijki:
         * pierwsze dopisane tu `$user->update(['last_seen_at' => now()])` —
         * rzecz naturalna i niewinnie wyglądająca — domyka cykl z tamtą
         * metodą i daje zakleszczenie na drodze, która dla osób 60+ jest
         * PODSTAWOWĄ drogą logowania, nie awaryjną (D-056). Czyli: 500 przy
         * kliknięciu w link z listu, u ludzi, którzy hasła nie użyją. Jedna
         * dodatkowa blokada na kliknięcie jest tańsza niż pilnowanie przez
         * lata, żeby nikt nigdy nie dopisał tu tej linijki.
         *
         * DLACZEGO TRZY ODCZYTY, A NIE JEDEN. Konta nie znamy, dopóki nie
         * przeczytamy tokenu — a tokenu nie wolno zablokować pierwszego.
         * Dlatego: (1) odczyt tokenu BEZ blokady, wyłącznie po to, żeby
         * wiedzieć, o czyje konto chodzi; (2) konto pod blokadą; (3) token
         * PONOWNIE, już pod blokadą. Krok (3) nie jest powtórzeniem kroku
         * (1): blokada serializuje, ale nie mówi żądaniu, że świat zmienił
         * się, kiedy ono czekało w kolejce (D-079 §3). Odczyt (1) nie
         * rozstrzyga więc NICZEGO poza tym, czyje konto zablokować —
         * wszystkie decyzje zapadają na wierszu z kroku (3).
         */
        $wstepny = LoginLinkToken::znajdzPoTokenie($token);
        $konto = $wstepny?->user;

        if ($konto === null) {
            return null;
        }

        $audytZawiodl = false;

        try {
            return ZamekKonta::zablokuj($konto, function (?User $swiezy) use ($token, $ip, &$audytZawiodl): ?User {
                // KONTA JUŻ NIE MA. Kaskada z `login_link_tokens.user_id`
                // zabrała razem z nim wiersz tokenu, więc nie ma tu czego
                // sprzątać ani kogo wpuszczać.
                if ($swiezy === null) {
                    return null;
                }

                $wiersz = LoginLinkToken::query()
                    ->where('token_hash', LoginLinkToken::skrot($token))
                    ->lockForUpdate()
                    ->first();

                // TOKEN ZNIKNĄŁ, KIEDY CZEKALIŚMY NA BLOKADĘ KONTA. Zniknąć
                // mógł na każdy z pięciu sposobów wypisanych w `LoginLinkToken`
                // — najczęściej przez drugie kliknięcie tego samego linku albo
                // przez nową prośbę o link z drugiego urządzenia. Człowiek
                // dostaje wtedy dokładnie ten sam komunikat co przy tokenie
                // zużytym i wygasłym: tamten ekran łączy te trzy przypadki
                // świadomie (patrz `ekranNieaktualnegoLinku()`) i nie ma
                // powodu, żeby rozjeżdżać się z nim tutaj. Przypadek wygaśnięcia
                // stoi niżej, za pytaniem o właściciela — powód tam.
                if ($wiersz === null) {
                    return null;
                }

                // CZY TEN WIERSZ JEST NADAL NASZ — trzecie pytanie rewalidacji
                // z D-079 §3. Blokadę trzymamy na koncie wybranym w kroku (1);
                // gdyby wiersz tokenu należał w tej chwili do kogoś innego,
                // zużylibyśmy cudzy token BEZ blokady jego konta i wpuścili
                // konto, do którego ten token nie należy. Wtedy nie robimy nic
                // — także nie kasujemy, bo to nie nasz wiersz i nie nasza
                // blokada.
                //
                // DLACZEGO TO PYTANIE STOI PRZED PYTANIEM O WAŻNOŚĆ, a nie po
                // nim. Kasowanie wygasłego wiersza jest zapisem, więc podlega
                // tej samej regule co zapis niżej: wolno nam pisać tylko do
                // wiersza, którego konto trzymamy pod blokadą. Odwrotna
                // kolejność kasowałaby cudzy wygasły wiersz bez blokady jego
                // konta — czyli łamałaby regułę, którą ta metoda w ogóle
                // wprowadza, i to w komentarzu tuż obok. Żadne dziś osiągalne
                // żądanie tu nie trafia (wiersz odnajdujemy po skrócie tokenu,
                // a drugie konto musiałoby mieć ten sam token), więc zamiana
                // kolejności nie zmienia niczego, co widać z zewnątrz. Stoi tak
                // dlatego, że reguła bez wyjątku jest sprawdzalna, a reguła
                // z jednym nieosiągalnym wyjątkiem — już nie.
                if ((string) $wiersz->user_id !== (string) $swiezy->getKey()) {
                    return null;
                }

                // TOKEN PRZEDAWNIŁ SIĘ, KIEDY CZEKALIŚMY NA BLOKADĘ KONTA.
                // Wygasły wiersz kasujemy przy okazji — nie jest już do
                // niczego, a zostawianie go zależnym od nocnego sprzątania
                // byłoby trzymaniem martwego klucza dłużej, niż trzeba. Tu
                // wolno: konto tego wiersza jest już zablokowane.
                if (! $wiersz->jestWazny()) {
                    $wiersz->delete();

                    return null;
                }

                // JEDNORAZOWOŚĆ. Kasujemy ZANIM cokolwiek zalogujemy i niezależnie
                // od tego, czy konto okaże się dalej wpuszczalne — link zużyty to
                // link zużyty, także wtedy, gdy trafił na konto zablokowane
                // w międzyczasie.
                $wiersz->delete();

                if (in_array($swiezy->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)) {
                    return null;
                }

                // Stan konta mógł się zmienić między prośbą a kliknięciem —
                // rola też. Konta obsługi serwisu tą drogą nie wchodzą (issue #25).
                if ($swiezy->hasStaffRole()) {
                    return null;
                }

                // WPIS W TEJ SAMEJ TRANSAKCJI CO ZUŻYCIE TOKENU (D-249, klasa 1;
                // #1530). Przedtem stał za nią: token był już skasowany i
                // zatwierdzony, a awaria dziennika dawała 500, człowieka bez
                // sesji i link, który przy ponowieniu mówił „już nie działa".
                // Jednorazowe poświadczenie przepadało, zanim ktokolwiek wszedł.
                // Teraz albo token znika RAZEM z wpisem, albo nie znika nic
                // i ten sam przycisk z listu działa dalej. Sesja HTTP (niżej)
                // nie jest zapisem do wycofania — powstaje dopiero po `COMMIT`.
                try {
                    AuditLogEntry::record('account.login_link_used', $swiezy, $swiezy, ip: $ip);
                } catch (Throwable $awaria) {
                    $audytZawiodl = true;

                    throw $awaria;
                }

                return $swiezy;
            });

        } catch (Throwable $awaria) {
            // Flaga jest ustawiana przez referencję w domknięciu wyżej — PHPStan
            // tego nie śledzi i uznaje ją za wiecznie `false`.
            /** @var bool $audytZawiodl */
            if (! $audytZawiodl) {
                throw $awaria;
            }

            // Transakcja wycofana: token jest z powrotem, konto nietknięte.
            // Operator dostaje nazwę brakującego wpisu (bez tokenu i adresu),
            // kontroler — sygnał, że człowiekowi wolno kliknąć jeszcze raz.
            throw new WejscieLinkiemWycofane((string) $konto->getKey(), $awaria);
        }
    }
}
