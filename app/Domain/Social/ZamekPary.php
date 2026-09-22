<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Users\ZamekKonta;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * JEDNA KOLEJNOŚĆ BLOKAD DLA OPERACJI NA PARZE OSÓB
 * (ustalenie SOCIAL-01 z audytu trzeciej warstwy, 10.09.2026; decyzja D-080).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO BYŁO ZŁAMANE
 * ══════════════════════════════════════════════════════════════════════
 *
 * Serwis obiecuje jedną własność: **blokada wygrywa z obserwowaniem**.
 * Migracja `create_follows_and_blocks_tables` mówi to wprost w komentarzu,
 * `BlockUser` realizuje to `detach`-em w obie strony, a filtry widoczności
 * (`visibleTo`, wyszukiwarka ludzi, listy obserwujących) stoją na założeniu,
 * że relacja blokady jest OSTATECZNA.
 *
 * Ta własność nie była liniaryzowalna. `FollowUser` pytał `exists()` o tabelę
 * `blocks`, a potem dopisywał wiersz `follows` — bez transakcji i bez żadnej
 * blokady wiersza. `BlockUser` robił swoje dwie rzeczy w transakcji, ale
 * transakcja nie pomaga, gdy druga strona nic nie blokuje. Przeplot:
 *
 *   1. żądanie A pyta „czy jest blokada między nami" → nie ma;
 *   2. żądanie B zakłada blokadę i zdejmuje obserwowanie w obie strony;
 *   3. żądanie A dopina obserwowanie, bo działa na odpowiedzi z punktu 1.
 *
 * DLACZEGO TO NIE JEST TEORETYCZNY WYŚCIG O MILISEKUNDY. Człowiek blokuje
 * kogoś zwykle w momencie konfliktu, czyli dokładnie wtedy, gdy druga strona
 * jest aktywna i klika. To dwie osoby robiące coś naraz w tej samej sprawie,
 * a nie zbieg okoliczności. Skutkiem jest zablokowana osoba, która dalej ma
 * moje wpisy w swoim feedzie obserwowanych — czyli blokada nie zrobiła tej
 * jednej rzeczy, po którą człowiek po nią sięgnął.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO ROBI TA KLASA
 * ══════════════════════════════════════════════════════════════════════
 *
 * Ustala jedno gardło dla operacji dotyczących PARY osób i pilnuje, żeby
 * wszystkie wchodziły przez nie w tej samej kolejności. Dwie równoległe
 * operacje na tej samej parze ustawiają się wtedy w kolejce, zamiast
 * wyprzedzać się nawzajem.
 *
 * ── KOLEJNOŚĆ DWÓCH WIERSZY JEST NAJWAŻNIEJSZĄ RZECZĄ W TEJ KLASIE ──
 *
 * `App\Domain\Users\ZamekKonta` (D-079) rozstrzyga kolejność blokad dla
 * operacji na JEDNYM koncie i tam pytanie o kolejność w ogóle nie powstaje:
 * jest jeden wiersz, więc nie ma czego szeregować. Tutaj wierszy są DWA
 * i to zmienia wszystko.
 *
 * Gdyby każda operacja blokowała wiersze w kolejności swoich argumentów,
 * dwie równoległe operacje na tej samej parze W PRZECIWNYCH KIERUNKACH
 * zakleszczyłyby się nawzajem:
 *
 *   żądanie A (Basia → Marek):  blokuje wiersz Basi,  czeka na Marka
 *   żądanie B (Marek → Basia):  blokuje wiersz Marka, czeka na Basię
 *
 * Każde trzyma to, na co czeka drugie. PostgreSQL wykryje to po
 * `deadlock_timeout` i ZABIJE jedną z transakcji — czyli człowiek zobaczy
 * błąd serwera zamiast założonej blokady. A to jest scenariusz zupełnie
 * zwyczajny: „Basia blokuje Marka" i „Marek obserwuje Basię" w tej samej
 * sekundzie to dokładnie ta sytuacja, o którą w tym całym zadaniu chodzi.
 *
 * Dlatego wiersze są blokowane w kolejności USTALONEJ PRZEZ DANE, nie przez
 * wywołanie: rosnąco po identyfikatorze. Obie strony konfliktu wyliczają tę
 * samą kolejność niezależnie od siebie, więc jedna czeka na drugą i nikt
 * nikogo nie zakleszcza. Kolejność stoi TUTAJ, w jednym miejscu — dwie
 * kopie tej samej reguły w dwóch akcjach rozjadą się przy pierwszej zmianie.
 *
 * ── DLACZEGO DWA OSOBNE ZAPYTANIA, A NIE JEDNO Z `ORDER BY` ──
 *
 * `SELECT ... WHERE id IN (a, b) ORDER BY id FOR UPDATE` blokuje wiersze
 * w kolejności, w jakiej wypuszcza je plan zapytania. W praktyce węzeł
 * `LockRows` stoi nad `Sort`, więc wyszłoby dobrze — ale to zależy od planu,
 * a plan zależy od statystyk i wersji bazy. Gwarancja, która trzyma się na
 * kształcie planu, nie jest gwarancją. Dwa jawne zapytania w jawnej
 * kolejności są nudne, o jeden round-trip droższe i nie da się ich zepsuć
 * cudzą decyzją o planowaniu.
 *
 * ── SAMA BLOKADA NIE WYSTARCZY ──
 *
 * Blokada serializuje, ale NIE MÓWI żądaniu A, że świat zmienił się, gdy ono
 * czekało. Żądanie A wchodzi pod blokadę z odpowiedzią sprzed sekundy i musi
 * zapytać jeszcze raz. Rewalidacja należy do akcji, nie do tej klasy — tylko
 * akcja wie, co dla niej znaczy „nadal aktualne", i tylko ona wie, co
 * powiedzieć wtedy człowiekowi (patrz `FollowUser::handle()`).
 *
 * ── DLACZEGO BLOKUJEMY WIERSZE KONT, A NIE WIERSZ RELACJI ──
 *
 * Bo wiersza relacji może NIE BYĆ, a `SELECT ... FOR UPDATE` na
 * nieistniejącym wierszu nie blokuje niczego i nie powstrzyma cudzego
 * `INSERT`-a. Oba konta istnieją zawsze i są wspólne dla obu operacji, więc
 * są jedyną rzeczą, na której da się je ustawić w kolejce. Ten sam powód co
 * w `ZamekKonta`.
 */
final class ZamekPary
{
    /**
     * Wykonuje `$co` w transakcji, pod blokadą wierszy OBU kont.
     *
     * Do wywołania zwrotnego trafiają ŚWIEŻE modele, odczytane już pod
     * blokadą, i to w tej samej kolejności, w jakiej podano argumenty —
     * kolejność blokowania jest sprawą wewnętrzną tej klasy i nie ma prawa
     * przeciekać do wywołującego. Modele podane na wejściu mogły zostać
     * odczytane przed sekundą albo przed godziną; operacja ma działać na
     * stanie z chwili, w której naprawdę trzyma blokadę.
     *
     * `null` znaczy „tego konta już nie ma" — akcja decyduje, co wtedy
     * powiedzieć człowiekowi.
     *
     * @template T
     *
     * @param  Closure(?User, ?User): T  $co
     * @return T
     */
    public static function zablokuj(User $pierwsza, User $druga, Closure $co): mixed
    {
        // ZAMEK PARY WEWNĄTRZ ZAMKA KONTA — ODMOWA, GŁOŚNO I OD RAZU.
        //
        // `ZamekKonta::zablokuj($a, …)` trzyma `users[$a] FOR UPDATE` przez
        // całe wywołanie zwrotne. Gdyby z jego wnętrza zawołać ten zamek dla
        // pary ($a, $b), proces miałby już JEDEN wiersz i dobierałby drugi
        // w kolejności ustalonej przez dane — a to daje cykl dokładnie
        // wtedy, gdy `$a` jest WIĘKSZYM identyfikatorem pary:
        //
        //   my:  users[a] (z ZamekKonta) → chcemy users[b] (mniejszy)
        //   oni: users[b] (mniejszy, jako pierwszy) → chcą users[a]
        //                            → CYKL → 40P01
        //
        // I to jest powód, dla którego odmawiamy ZAWSZE, a nie tylko przy
        // `$a > $b`: bezpieczeństwo zależałoby wtedy od tego, które konto ma
        // większy UUID, czyli od rzutu monetą przy każdej parze. Zakleszczenie
        // sypałoby się raz na dwa przypadki, w produkcji, i nie dałoby się go
        // powtórzyć na życzenie — najgorszy możliwy kształt usterki.
        //
        // Dziś ŻADNA droga w `app/` tego nie robi (audyt kolejności blokad
        // z 11.09.2026, znalezisko Z-7: „hipoteza przyszłego regresu, nie
        // aktualne znalezisko"). Ten strażnik istnieje, żeby ta hipoteza nie
        // mogła się spełnić po cichu — bo interfejs obu klas tego nie
        // zabraniał, a nic tego nie pilnowało.
        //
        // Kierunek odwrotny — `ZamekKonta` wewnątrz `ZamekPary` — jest
        // bezpieczny i NIE jest zabroniony: bierze blokadę na wiersz, który
        // ta transakcja już trzyma, więc nie dokłada ani jednej krawędzi
        // do grafu oczekiwania.
        if (ZamekKonta::trzymanyWTymProcesie()) {
            throw new RuntimeException(
                'ZamekPary::zablokuj() zawołany wewnątrz ZamekKonta::zablokuj(). '
                .'To jest droga do zakleszczenia: zamek konta trzyma już jeden wiersz '
                .'`users`, a zamek pary dobiera drugi w kolejności ustalonej przez dane — '
                .'przy koncie o WIĘKSZYM identyfikatorze pary powstaje cykl z równoległym '
                .'zwykłym ZamekPary (D-075, D-080). Odmowa jest bezwarunkowa, bo inaczej '
                .'poprawność zależałaby od tego, które konto ma większy UUID. '
                .'CO ZROBIĆ: wyjdź z zamka konta i zawołaj ZamekPary na zewnątrz, albo '
                .'przenieś całą operację do jednego ZamekPary — on bierze OBA wiersze '
                .'we właściwej kolejności i zamek konta staje się zbędny.',
            );
        }

        $klucz = [
            'pierwsza' => (string) $pierwsza->getKey(),
            'druga' => (string) $druga->getKey(),
        ];

        // KOLEJNOŚĆ USTALONA PRZEZ DANE, NIE PRZEZ WYWOŁANIE. `array_unique`
        // nie jest ozdobą: gdyby kiedyś ktoś zawołał tę metodę dla tej samej
        // osoby dwa razy, bez tego wzięlibyśmy blokadę na ten sam wiersz
        // dwukrotnie i tylko zamieszali w logu zapytań.
        $doZablokowania = array_unique(array_values($klucz));
        sort($doZablokowania, SORT_STRING);

        return DB::transaction(static function () use ($klucz, $doZablokowania, $co) {
            $swieze = [];

            foreach ($doZablokowania as $id) {
                $swieze[$id] = User::query()->whereKey($id)->lockForUpdate()->first();
            }

            return $co($swieze[$klucz['pierwsza']], $swieze[$klucz['druga']]);
        });
    }
}
