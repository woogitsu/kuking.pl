<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Digest\OdbiorcyDigestu;
use App\Domain\Digest\TrescDigestu;
use App\Domain\Digest\ZbierzTresciDigestu;
use App\Domain\Security\DziennyBudzetListow;
use App\Mail\PodsumowanieTygodnia;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Wysyłka tygodniowych podsumowań (issue #11, `docs/DECISIONS.md` D-057).
 *
 * TA KOMENDA ISTNIEJE PO TO, ŻEBY NIE PRZEKROCZYĆ 300 LISTÓW NA DOBĘ.
 * Cała reszta — wybór odbiorców, zbudowanie treści, złożenie listu — mieszka
 * gdzie indziej (`App\Domain\Digest\*`, `App\Mail\PodsumowanieTygodnia`).
 * Tutaj jest wyłącznie arytmetyka limitu i jedna decyzja: komu dziś, a komu
 * jutro.
 *
 * DLACZEGO WŁASNY LICZNIK, A NIE „WYŚLIJ I ZOBACZ, CO ODBIJE"
 * Sprawdzone w kodzie transportu: gdy EmailLabs odrzuci wiadomość — a limit
 * dobowy odrzuca ją tak samo jak każdy inny błąd — `App\Poczta\
 * TransportEmailLabs::rozstrzygnij()` rzuca `OdmowaEmailLabs`. To wywraca
 * zadanie w kolejce, a `docker/entrypoint.sh` uruchamia workera
 * z `--tries=3 --backoff=10,60,300`. Trzy próby mieszczą się więc
 * w **sześciu minutach od pierwszej** — czyli wszystkie tego samego dnia,
 * wszystkie ponad limitem, wszystkie odrzucone. Czwartej nie ma: list ląduje
 * w `failed_jobs` i tam zostaje.
 *
 * „Wróci do kolejki i spróbuje jutro" jest więc NIEPRAWDĄ i nie wolno na tym
 * budować. Nie dowie się o tym też nikt sam z siebie: nie ma Sentry (D-041
 * — błędy 500 idą webhookiem, ale zadania kolejki nie), a jedynym miejscem,
 * które w ogóle liczy `failed_jobs`, jest `kuking:sprawdz-poczte`
 * uruchamiane ręcznie. Pół wysyłki mogłoby przepaść po cichu.
 *
 * Dlatego limit pilnujemy PRZED wysłaniem, po własnej stronie. Odrzucenie
 * przez dostawcę jest wtedy awarią, a nie normalnym trybem pracy.
 *
 * SUFIT JEST WSPÓLNĄ KLASĄ, NIE WŁASNYM LICZNIKIEM
 * `App\Domain\Security\DziennyBudzetListow` powstał przy logowaniu linkiem
 * (issue #25), które zderzyło się z tym samym limitem tego samego wiadra.
 * Podsumowanie używa TEJ SAMEJ klasy, z własnym kluczem licznika i własnym
 * sufitem z konfiguracji. Dwa niezależne liczniki tego samego wiadra
 * rozjechałyby się przy pierwszej zmianie którejkolwiek liczby — a w tym
 * repozytorium rozjazd dwóch kopii jednej reguły jest usterką, nie
 * niedogodnością.
 *
 * SUMA SUFITÓW MUSI ZMIEŚCIĆ SIĘ POD 300 i żadna z tych klas tego nie widzi:
 * każda pilnuje własnej funkcji. Podział całego wiadra stoi w
 * `config/kuking.php` (sekcja `poczta`), a pilnuje go
 * `PodzialLimituPocztyTest`.
 */
class WyslijPodsumowaniaTygodnia extends Command
{
    protected $signature = 'kuking:wyslij-podsumowania
                            {--limit= : Ile listów najwyżej wypuścić w tym przebiegu (domyślnie z konfiguracji)}
                            {--tylko= : Nazwa użytkownika albo adres e-mail JEDNEJ osoby — list próbny, z pominięciem odstępu tygodniowego}
                            {--na-sucho : Policz i pokaż, ale nie wysyłaj i nie zapisuj niczego}';

    protected $description = 'Wysyła tygodniowe podsumowanie do osób, które wyraziły zgodę (issue #11).';

    public function handle(
        OdbiorcyDigestu $odbiorcy,
        ZbierzTresciDigestu $zbierz,
        ZapiszSygnal $sygnal,
    ): int {
        $budzetDnia = DziennyBudzetListow::dlaPodsumowania();
        $naSucho = (bool) $this->option('na-sucho');

        // WYŁĄCZNIK CAŁOŚCI — jedna zmienna środowiskowa (D-057). Kod wyjścia
        // 0, nie 1: to nie jest awaria, tylko stan wyłączony, a harmonogram
        // ma nie hałasować w dzienniku codziennie o poranku.
        if (! config('kuking.digest.wlaczony') && ! $naSucho) {
            $this->info('Tygodniowe podsumowanie jest wyłączone (KUKING_DIGEST_WLACZONY). Nic nie wysyłam.');

            return self::SUCCESS;
        }

        $jedna = $this->jednaOsoba();

        if ($this->option('tylko') !== null && $jedna === null) {
            $this->error(
                'Nie ma takiej osoby wśród kwalifikujących się do wysyłki. '
                .'Sprawdź nazwę albo adres, a potem to, czy konto jest czynne, ma potwierdzony adres '
                .'i zaznaczoną zgodę na `/ustawienia/prywatnosc`.',
            );

            return self::FAILURE;
        }

        $budzet = $this->budzet($budzetDnia);

        if ($budzet < 1 && $jedna === null) {
            $this->warn('Dzienny limit listów jest już wyczerpany. Reszta pójdzie jutro.');

            return self::SUCCESS;
        }

        $kandydaci = $jedna !== null
            ? collect([$jedna])
            // TRZYKROTNY ZAPAS, ŚWIADOMIE. Część kandydatów odpadnie, bo nie
            // ma dla nich o czym pisać — a pusty list nie wychodzi. Gdyby
            // pobierać dokładnie tylu, ilu wynosi budżet, tydzień z małym
            // ruchem kończyłby się wysyłką do garstki osób przy niewykorzystanym
            // limicie, mimo że dalej w kolejce stali ludzie, dla których treść
            // była. Zapas kosztuje jedno większe zapytanie, nie więcej listów.
            : $odbiorcy->naDzis($budzet * 3);

        if ($kandydaci->isEmpty()) {
            $this->info('Nikt dziś nie czeka na podsumowanie.');

            return self::SUCCESS;
        }

        $tresci = $zbierz->dla($kandydaci);

        $wyslane = [];
        $puste = 0;
        $numer = 0;
        $odstep = max(0, (int) config('kuking.digest.odstep_sekund'));

        foreach ($kandydaci as $osoba) {
            if (count($wyslane) >= $budzet && $jedna === null) {
                break;
            }

            $tresc = $tresci[(string) $osoba->getKey()] ?? TrescDigestu::pusta($osoba);

            // NAJWAŻNIEJSZA LINIJKA W TYM PLIKU (issue #11 pkt 7).
            // „Lepiej nic niż e-mail o niczym" — pusty list jest jedyną
            // rzeczą, która potrafi zamienić digest z powodu powrotu
            // w powód do wypisania się.
            if ($tresc->jestPusty()) {
                $puste++;

                continue;
            }

            if (! $naSucho) {
                // ROZSUNIĘCIE W CZASIE, NIE STO DWADZIEŚCIA WYWOŁAŃ API
                // W JEDNEJ MINUCIE — `docs/decyzje/POCZTA.md` §5 pkt 5 mówi
                // wprost, że taki szczyt sam w sobie jest sygnałem spamowym.
                // Przy domyślnych 20 sekundach cała paczka schodzi w jakieś
                // czterdzieści minut.
                //
                // `->delay()` NA LIŚCIE, a nie `Mail::later()`, i to nie jest
                // kwestia gustu: `Mailable::queue()` sam sięga po
                // `$this->delay` i woła `laterOn()`, więc skutek w kolejce
                // jest identyczny — ale opóźnienie zostaje ZAPISANE
                // W OBIEKCIE. Dzięki temu widać je w `queue:work`, widać
                // w `failed_jobs` i widać w teście. `Mail::later()` przekazuje
                // je bokiem, do samej kolejki, i po drodze nie zostaje po nim
                // ślad, którym dałoby się to sprawdzić.
                $list = (new PodsumowanieTygodnia($tresc))->delay(now()->addSeconds($numer * $odstep));

                Mail::to($osoba->email)->queue($list);

                // Miejsce w dobowym suficie zajmujemy PRZY WSTAWIENIU DO
                // KOLEJKI, nie po doręczeniu — uzasadnienie przy
                // `DziennyBudzetListow::zajmij()`. W skrócie: po wstawieniu
                // jest już za późno, bo list odrzucony limitem dostawcy
                // przepada w `failed_jobs` i nikt się o tym nie dowie.
                $budzetDnia->zajmij();

                $sygnal->handle($osoba, ZapiszSygnal::WEEKLY_DIGEST_SENT, $tresc->miary());
            }

            $wyslane[] = $osoba;
            $numer++;
        }

        if (! $naSucho) {
            // Znacznik stawiamy JEDNYM zapytaniem, po pętli — patrz
            // `OdbiorcyDigestu::oznaczWyslane()`, dlaczego przy wstawieniu
            // do kolejki, a nie po doręczeniu.
            $odbiorcy->oznaczWyslane($wyslane);
        }

        $this->podsumuj(count($wyslane), $puste, $budzet, $naSucho, $jedna !== null);

        return self::SUCCESS;
    }

    /**
     * Ile listów wolno jeszcze dziś wypuścić.
     *
     * Licznik jest DOBOWY, nie „na przebieg": drugie uruchomienie komendy
     * tego samego dnia (ręczne po awarii, po restarcie kontenera) nie podwaja
     * wysyłki. Odstęp tygodniowy pilnuje tego samego po stronie POJEDYNCZEJ
     * osoby; ten licznik pilnuje po stronie SKRZYNKI NADAWCZEJ, czyli tego,
     * czego odstęp nie widzi.
     *
     * `--limit` może budżet tylko ZAWĘZIĆ, nigdy poszerzyć. Flaga jest do
     * ostrożnego pierwszego uruchomienia („wyślij pięć i zobacz"), a nie do
     * obchodzenia sufitu — ten wynika z limitu dostawcy, którego żadna flaga
     * nie podniesie.
     */
    private function budzet(DziennyBudzetListow $budzetDnia): int
    {
        $zostalo = $budzetDnia->zostalo();

        if ($this->option('limit') === null) {
            return $zostalo;
        }

        return min($zostalo, max(0, (int) $this->option('limit')));
    }

    /**
     * Konto wskazane flagą `--tylko` — droga listu próbnego dla właściciela.
     *
     * Pomija odstęp tygodniowy (o to w niej chodzi: chcemy zobaczyć list
     * TERAZ), ale NIE pomija zgody, statusu konta ani potwierdzenia adresu.
     * Te trzy warunki nie są niedogodnością do obejścia — są powodem, dla
     * którego wolno nam do kogoś napisać.
     */
    private function jednaOsoba(): ?User
    {
        $szukane = trim((string) $this->option('tylko'));

        if ($szukane === '') {
            return null;
        }

        $osoba = User::findByLogin($szukane);

        if ($osoba === null) {
            return null;
        }

        return $this->laravel->make(OdbiorcyDigestu::class)
            ->kwalifikujacySie()
            ->whereKey($osoba->getKey())
            ->with('profile')
            ->first();
    }

    private function podsumuj(int $ile, int $puste, int $budzet, bool $naSucho, bool $proba): void
    {
        $czasownik = $naSucho ? 'Do wysłania' : 'Wysłano';

        $this->info("{$czasownik}: {$ile}. Pominięto bez treści: {$puste}.");

        if ($proba || $naSucho) {
            return;
        }

        $czekajacy = app(OdbiorcyDigestu::class)->ileCzeka();

        if ($czekajacy > 0) {
            // NIE `error()`: to jest normalny tryb pracy przy większej
            // społeczności, a nie awaria. Ale MUSI zostawić ślad w dzienniku,
            // bo to jedyny moment, w którym widać, że plan darmowy przestaje
            // wystarczać — przy 840 osobach tygodniowo (120 × 7) ta liczba
            // przestaje spadać do zera i wtedy trzeba przejść na plan płatny
            // (`docs/DECISIONS.md` D-057).
            $this->warn("W kolejce czeka jeszcze {$czekajacy} — kolejni dostaną list w następnych dniach.");

            Log::info('Tygodniowe podsumowanie: część odbiorców czeka na kolejny dzień.', [
                'wyslano' => $ile,
                'budzet_dnia' => $budzet,
                'czeka' => $czekajacy,
            ]);
        }
    }
}
