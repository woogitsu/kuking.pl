<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Domain\Social\ZamekPary;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Block;
use App\Models\User;

/**
 * Zablokowanie kogoś.
 *
 * Blokada kasuje obserwowanie W OBIE STRONY. Gdyby zostawić follow, osoba
 * zablokowana wciąż widziałaby treści w swoim feedzie — a to znaczy, że
 * blokada nie działa. To jest reguła domenowa, nie detal implementacji.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO TA AKCJA WCHODZI PRZEZ `ZamekPary` (D-090, dokończenie D-080)
 * ══════════════════════════════════════════════════════════════════════
 *
 * D-080 postanowiło, że OBIE operacje na parze osób idą przez jedno gardło
 * `App\Domain\Social\ZamekPary`. `FollowUser` tam wszedł, ta akcja NIE —
 * została przy własnej `DB::transaction()` bez ani jednej blokady wiersza.
 * Jedna strona konfliktu brała więc wiersze `users` rosnąco po
 * identyfikatorze, a druga nie brała ich JAWNIE wcale.
 *
 * ── CO Z TEGO NAPRAWDĘ WYNIKAŁO, A CO NIE ──
 *
 * NIE wynikało z tego współistnienie blokady i obserwowania. To podejrzenie
 * zostało zmierzone na dwóch połączeniach do PostgreSQL i OBALONE:
 * `INSERT INTO blocks` i tak bierze blokady obu wierszy `users` — robią to
 * za niego sprawdzenia kluczy obcych (`SELECT 1 FROM ONLY users WHERE
 * id = $1 FOR KEY SHARE OF x`), a `FOR KEY SHARE` jest w konflikcie
 * z `FOR UPDATE`. Żądanie „Obserwuj" ustawiało się więc w kolejce mimo
 * wszystko. Ta własność trzymała się jednak na kształcie kluczy obcych,
 * czyli na czymś, czego nie widać w żadnej linijce PHP.
 *
 * WYNIKAŁO ZAKLESZCZENIE — i to zmierzone. Blokady z kluczy obcych idą
 * w kolejności RÓL (`blocker_id`, potem `blocked_id`, bo w tej kolejności
 * powstały ograniczenia), a nie w kolejności identyfikatorów. Więc:
 *
 *   „Obserwuj" (ZamekPary):  bierze wiersz NIŻSZY, czeka na WYŻSZY
 *   „Zablokuj" (ta akcja):   bierze wiersz WYŻSZY, czeka na NIŻSZY
 *
 * PostgreSQL wykrywa cykl i ZABIJA jedną z transakcji. W pomiarze ofiarą
 * padło „Zablokuj" — czyli człowiek dostawał błąd serwera zamiast założonej
 * blokady, dokładnie w sytuacji, dla której D-080 powstało („blokada musi
 * się udać zawsze").
 *
 * Przepuszczenie tej akcji przez `ZamekPary` usuwa cykl: obie strony biorą
 * te same dwa wiersze w tej samej, wyliczonej z danych kolejności, więc
 * jedna czeka na drugą, zamiast zakleszczać się z nią.
 *
 * ── SPRAWDZENIE POD BLOKADĄ, NIE PRZED ──
 *
 * Zamek podaje ŚWIEŻE modele, odczytane już pod blokadą. `null` znaczy, że
 * konta nie ma — a wtedy nie ma czego blokować i lepiej powiedzieć to
 * człowiekowi, niż pozwolić kluczowi obcemu wywalić 500.
 *
 * ── DZIENNIK AUDYTU ZOSTAJE POZA TRANSAKCJĄ ──
 *
 * Tak jak dotąd, i to jest wybór, nie przeoczenie. Wpis audytowy ma powstać
 * wtedy, gdy blokada NAPRAWDĘ się zapisała; wciągnięty pod blokadę zniknąłby
 * razem z wycofaną transakcją, a jest osobnym śladem, nie częścią relacji.
 *
 * I DLATEGO `recordBezWywracania()`, a nie `record()` (D-249, klasa 2; #1573).
 * Blokada ma się udać zawsze (D-080), a jej autorytatywny ślad to wiersz
 * `blocks` z `created_at`. Rzucające `record()` za transakcją dawało wariant
 * pośredni: blokada i oba odcięcia obserwowania trwałe, a człowiek widział
 * „nie udało się" — i po blokadzie często nie ma już drogi do ponowienia.
 * Teraz awaria dziennika idzie do `report()` z nazwą brakującego wpisu,
 * a blokada zostaje sukcesem.
 */
final class BlockUser
{
    public function handle(User $blocker, User $target, ?string $ip = null): void
    {
        if ($blocker->getKey() === $target->getKey()) {
            throw new BladDlaCzlowieka('Nie można zablokować samego siebie.');
        }

        ZamekPary::zablokuj($blocker, $target, static function (?User $blokujacy, ?User $blokowany): void {
            // Konto mogło zniknąć między odczytem a wejściem pod blokadę.
            if ($blokujacy === null || $blokowany === null) {
                throw new BladDlaCzlowieka('To konto jest niedostępne.');
            }

            Block::query()->firstOrCreate([
                'blocker_id' => $blokujacy->getKey(),
                'blocked_id' => $blokowany->getKey(),
            ], ['created_at' => now()]);

            $blokujacy->following()->detach($blokowany->getKey());
            $blokowany->following()->detach($blokujacy->getKey());
        });

        AuditLogEntry::recordBezWywracania(
            action: 'user.blocked',
            actor: $blocker,
            subject: $target,
            ip: $ip,
        );
    }
}
