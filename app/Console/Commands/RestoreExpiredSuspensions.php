<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Przywracanie kont po odsiedzeniu kary (issue #40).
 *
 * DLACZEGO TO MUSI BYĆ AUTOMAT
 * `docs/legal/MODERATION_PLAYBOOK.md` przewiduje blokady czasowe („7 dni”),
 * ale przy jednym moderatorze (D-012) nikt nie odklika ich ręcznie po
 * tygodniu. Bez tej komendy każda kara czasowa staje się w praktyce trwała —
 * czyli playbook obiecuje coś, czego system nie robi.
 *
 * Komenda jest bezpieczna do wielokrotnego uruchomienia: bierze wyłącznie
 * konta `suspended` z terminem w przeszłości. Konto zbanowane nie ma terminu
 * (CHECK w bazie tego pilnuje), więc nie da się go tędy przywrócić — nawet
 * gdyby ktoś ustawił kolumnę ręcznie.
 *
 * Nie jest to jedyna droga powrotu: middleware `EnsureAccountIsActive`
 * przywraca konto od razu, gdy karany użytkownik wejdzie na stronę po
 * upływie terminu. Ta komenda pilnuje kont, które po prostu nie wracają —
 * żeby stan w bazie zgadzał się z rzeczywistością także dla moderacji,
 * statystyk i widoku listy użytkowników.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  NA WYJŚCIU IDENTYFIKATOR KONTA, NIGDY ADRES E-MAIL (issue #1026)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Do 22 września 2026 obie linie tej pętli wypisywały `$user->email` — także
 * w trybie `--dry-run`, który z założenia miał „tylko popatrzeć". Komenda
 * tyka CO GODZINĘ (`routes/console.php`), a jej wyjście nie kończy się na
 * terminalu właściciela: to samo polecenie uruchamia się ręcznie z konsoli
 * platformy hostingowej i przy każdym takim uruchomieniu pełny adres
 * człowieka ląduje w logu usługi, nad którą serwis nie ma kontroli i której
 * retencji nie ustawia. AGENTS.md §7 zakazuje PII w logach bez wyjątku dla
 * „to tylko dry-run".
 *
 * Wypisujemy więc IDENTYFIKATOR konta — dokładnie tak, jak robi to bliźniacza
 * komenda `kuking:usun-wygasle-konta` (`PurgeExpiredAccountDeletions`),
 * która listuje konta w karencji po `getKey()` i nigdy po adresie. To nie
 * jest strata: identyfikator odpowiada na jedyne pytanie, jakie zadaje się
 * przy dry-runie („ile i które konta ruszy to uruchomienie"), a od
 * identyfikatora do konta prowadzi panel moderacji — czyli droga, która
 * zostawia wpis w `audit_log`. Adres e-mail w logu platformy nie zostawia
 * żadnego śladu i nie wygasa.
 *
 * DLACZEGO NIE MASKA `AdresEmail::maska()`
 *
 * Bo `j***@wp.pl` to nadal dana osobowa, tylko krótsza — przy wąskiej domenie
 * firmowej wskazuje osobę równie dobrze co pełny adres (ta sama pułapka, co
 * `/64` zamiast `/48` przy maskowaniu IP). Maska jest po to, żeby CZŁOWIEK
 * rozpoznał swoją skrzynkę na ekranie; log platformy nie jest ekranem
 * i nikt tam niczego nie rozpoznaje.
 */
class RestoreExpiredSuspensions extends Command
{
    protected $signature = 'kuking:zdejmij-wygasle-kary
                            {--dry-run : Pokaż, komu skończyła się kara, i nic nie zmieniaj}';

    protected $description = 'Przywraca konta, którym minął termin zawieszenia';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = User::query()
            ->where('status', User::STATUS_SUSPENDED)
            ->whereNotNull('status_expires_at')
            ->where('status_expires_at', '<=', now())
            ->orderBy('status_expires_at')
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Nie ma kar, którym minął termin.');

            return self::SUCCESS;
        }

        $nieudane = 0;

        foreach ($expired as $user) {
            if ($dryRun) {
                $this->line("[dry-run] {$user->getKey()} — kara minęła {$user->status_expires_at->format('Y-m-d H:i')}");

                continue;
            }

            // PRZYWRÓCENIE I WPIS DO AUDYTU W JEDNEJ TRANSAKCJI (D-249,
            // klasa 1; #1894). Wpis do dziennika audytu: przywrócenie
            // dostępu jest decyzją moderacyjną tak samo jak jego odebranie,
            // nawet gdy wykonuje ją zegar — bez niego w historii konta
            // zostaje samo „zawieszony” i nie widać, że kara skończyła się
            // zgodnie z terminem. To jest jedyny zapis TEGO zdarzenia:
            // `reinstate()` nie zostawia po sobie żadnego innego śladu, że
            // przywrócenie było wykonaniem WYGASŁEJ kary, a nie inną drogą
            // (np. cofnięciem odwołania).
            //
            // Dlatego `record()` — nie `recordBezWywracania()` — WEWNĄTRZ
            // `DB::transaction()`: gdy zapis audytu padnie, transakcja się
            // wycofuje razem z `reinstate()`, konto ZOSTAJE `suspended`
            // i to samo uruchomienie komendy za godzinę znajdzie je znowu
            // w zapytaniu wyżej. Bez tego jedno uruchomienie wcześniej
            // zamieniało zapis w HTTP 500 tej komendy: `record()` rzucał
            // PO wykonanym `reinstate()`, więc konto wracało do `active`
            // bez wpisu, a kolejne uruchomienie już go nie widziało
            // (`WHERE status = suspended` go nie łapie) — czyli brak
            // audytu bez drogi ponowienia.
            //
            // KAŻDE KONTO OSOBNO: wyjątek jednego konta (tu i tak głównie
            // z audytu, bo `reinstate()` sam w sobie rzadko zawodzi) nie ma
            // przerywać przywracania reszty — ta sama zasada co
            // w `kuking:usun-wygasle-konta` (#1028).
            try {
                DB::transaction(function () use ($user): void {
                    $user->reinstate();

                    AuditLogEntry::record('account.suspension_expired', null, $user);
                });
            } catch (Throwable $e) {
                $nieudane++;
                $this->error("Nie udało się przywrócić konta {$user->getKey()} — spróbuję przy następnym przebiegu.");
                Log::error('Zdejmowanie wygasłych kar: nieudana próba dla konta', [
                    'konto_id' => (string) $user->getKey(),
                    'wyjatek' => $e::class,
                ]);

                continue;
            }

            $this->line("Przywrócono: {$user->getKey()}");
        }

        $this->info($dryRun
            ? 'Kar z minionym terminem: '.$expired->count().' (nic nie zmieniono).'
            : 'Przywrócono kont: '.($expired->count() - $nieudane).'.'
                .($nieudane > 0 ? ' Nieudane: '.$nieudane.' (spróbujemy przy następnym przebiegu).' : ''));

        return self::SUCCESS;
    }
}
