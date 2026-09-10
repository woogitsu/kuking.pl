<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Appeal;
use App\Models\Notification;
use App\Models\User;
use App\Support\Czas;

/**
 * NOWE ODWOŁANIE — ZAWIADOMIENIE DLA TEGO, KTO MOŻE JE ROZSTRZYGNĄĆ
 * (zgłoszenie właściciela z 10 września, DSA art. 20).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TO ISTNIEJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Do tej zmiany odwołanie po prostu POJAWIAŁO SIĘ na `/admin/odwolania`
 * i nic o tym nie mówiło. Właściciel przeszedł tę ścieżkę pierwszy raz na
 * produkcji i zobaczył dokładnie to: jako użytkownik dostał powiadomienie
 * o decyzji, złożył odwołanie — i jako administrator nie dowiedział się
 * o nim niczym poza wejściem na kolejkę z własnej woli.
 *
 * Odwołanie ma TERMIN (`Appeal::responseDeadline()` — siedem dni roboczych
 * z `docs/legal/MODERATION_PLAYBOOK.md` §3, obiecane człowiekowi w każdym
 * szablonie decyzji i pokazane na ekranie kolejki). Kolejka, o której nikt
 * nie wie, że coś w niej leży, to termin, który upływa po cichu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DO ADMINISTRATORÓW, NIE DO WSZYSTKICH MODERATORÓW
 * ────────────────────────────────────────────────────────────────────────
 *
 * Odwołanie ROZSTRZYGA wyłącznie administrator — `UserPolicy::resolveAppeals()`,
 * D-039 — a moderator kolejkę tylko WIDZI (`AppealController::index()`
 * pyta o `moderate`, `resolve()` o `resolveAppeals`). Powiadomienie dla
 * moderatora byłoby więc wezwaniem do czynności, której ta osoba nie może
 * wykonać: kliknąłby i przeczytał „tę sprawę zamyka administrator". To jest
 * najkrótsza droga do tego, żeby ludzie przestali czytać powiadomienia
 * z panelu w ogóle.
 *
 * Moderator nie zostaje z niczym: przy pozycji „Odwołania" w menu stoi
 * licznik tego, co czeka (`App\Domain\Moderation\KolejkiPanelu`), na każdej
 * stronie panelu. Zawiadomienie idzie do tego, kto podejmuje decyzję;
 * widok kolejki zostaje dla wszystkich, którzy ją mają.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO BEZ `actor`, CHOĆ ZNAMY OSOBĘ SKŁADAJĄCĄ ODWOŁANIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `NotifyUser` odmawia utworzenia powiadomienia, gdy między nadawcą
 * a odbiorcą istnieje blokada — i słusznie, bo tak działa cała reszta
 * serwisu. Tutaj byłoby to DZIURĄ: wystarczyłoby zablokować konto
 * administratora, żeby zawiadomienie o własnym odwołaniu nigdy nie
 * powstało, a termin z art. 20 płynął dalej. Nadawcy więc nie podajemy —
 * to nie jest powiadomienie społecznościowe „ktoś coś zrobił wobec Ciebie",
 * tylko zawiadomienie systemowe o stanie kolejki. Nazwę osoby niesie
 * `data`, tak samo jak przy pierwszym wpisie (`TYPE_FIRST_POST`).
 *
 * Drugi skutek tej samej decyzji: odwołanie ZGŁASZAJĄCEGO często nie ma
 * konta w ogóle (art. 16 ust. 2 lit. c) — nie byłoby czego podać jako
 * nadawcę, więc jedna droga obsługuje oba rodzaje odwołań bez wyjątków.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE POCZTĄ (dziś)
 * ────────────────────────────────────────────────────────────────────────
 *
 * EmailLabs daje 300 listów na dobę na CAŁY serwis (D-047), z tego samego
 * wiadra co potwierdzenia rejestracji. Wiadro jest już dzielone jawnie
 * w `config/kuking.php` (logowanie linkiem, digest, rezerwa transakcyjna),
 * a ten podział czeka w gałęziach `claude/logowanie-linkiem` (PR #233)
 * i `claude/tygodniowy-digest` (PR #236) — dokładanie tu trzeciej pozycji
 * z osobnego PR-a zrobiłoby dwa różne podziały tego samego wiadra w dwóch
 * gałęziach, czyli dokładnie to, przed czym ostrzega komentarz w tamtej
 * sekcji konfiguracji.
 *
 * Termin na odpowiedź to siedem DNI ROBOCZYCH, nie godziny — więc list
 * natychmiastowy nie kupuje tu nic, czego nie kupuje powiadomienie
 * w panelu z licznikiem widocznym na każdym ekranie. Kanał pilny
 * (`PilnyAlarmModeracyjny`) zostaje zarezerwowany dla spraw, w których
 * zwłoka jednego dnia jest realną szkodą — treści krzywdzących dzieci —
 * i ma tak zostać, bo inaczej to rozróżnienie przestaje cokolwiek znaczyć.
 *
 * Gdy podział wiadra wejdzie na `main`, właściwym miejscem na pocztę
 * o odwołaniach jest dobowe podsumowanie kolejki (jeden list, własna
 * pozycja w podziale), nie list na każde odwołanie.
 */
final class PowiadomOOdwolaniu
{
    public function __construct(private readonly NotifyUser $notify = new NotifyUser) {}

    public function handle(Appeal $odwolanie, string $nazwaSkladajacego): void
    {
        $administratorzy = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->get();

        foreach ($administratorzy as $administrator) {
            $this->notify->handle(
                recipient: $administrator,
                type: Notification::TYPE_APPEAL_FILED,
                actor: null,
                data: [
                    'appeal_id' => (string) $odwolanie->getKey(),
                    'skladajacy' => $nazwaSkladajacego,
                    // Termin zamrożony w treści powiadomienia, a nie liczony
                    // przy odczycie: człowiek ma zobaczyć tę samą datę, którą
                    // widzi na kolejce, także gdyby konfiguracja terminu
                    // kiedyś się zmieniła.
                    'termin' => Czas::data($odwolanie->responseDeadline(), 'j F Y'),
                    // „od zgłaszającego" to inna sprawa niż odwołanie autora
                    // treści i inaczej się ją czyta — patrz `Appeal::isFromReporter()`.
                    'od_zglaszajacego' => $odwolanie->isFromReporter(),
                ],
            );
        }
    }
}
