<?php

declare(strict_types=1);

namespace App\Poczta;

use App\Models\MailFailure;
use App\Models\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Ostatnia próba wysłania listu się nie udała — zostaw ŚLAD, którego nie
 * trzeba szukać (issue #234, D-062).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TO NAPRAWIA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Do 10 września 2026: odmowa dostawcy → trzy próby workera → `failed_jobs`
 * → cisza. Sześć minut i list przepadał. O potwierdzeniu rejestracji, które
 * nie doszło, nie dowiadywał się ani adresat, ani właściciel — dopóki ten
 * drugi sam z siebie nie uruchomił `php artisan queue:failed`. Kolejka pusta,
 * `/health` zielony: awaria wyglądała jak sukces.
 *
 * Teraz każda taka porażka zostawia wiersz w `mail_failures`, a `/health`
 * mówi `degraded`, dopóki właściciel go nie odhaczy.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO `JobFailed`, A NIE ZDARZENIA POCZTY
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo `MessageSending` i `MessageSent` nie mówią o porażce nic: pierwsze
 * leci przed wysyłką, drugie tylko po udanej. A wyjątek transportu leci
 * przy KAŻDEJ z trzech prób — zapisywanie go stamtąd dałoby trzy wiersze
 * o jednym liście i wpis nawet wtedy, gdy druga próba się udała.
 *
 * `JobFailed` leci DOKŁADNIE RAZ: w chwili, w której worker uznał zadanie za
 * przegrane i odkłada je do `failed_jobs`. To jest ta sama chwila, w której
 * list naprawdę przepada — ani sekundy wcześniej.
 *
 * Drugą zaletą jest niezależność od dostawcy: warunkiem zapisu jest
 * `TransportExceptionInterface` gdziekolwiek w łańcuchu przyczyn, więc ślad
 * powstaje tak samo przy naszym `emaillabs`, jak przy `smtp` czy `ses`,
 * gdyby właściciel kiedyś je włączył (D-047 zostawia SMTP uśpiony).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TA KLASA NIE MA PRAWA RZUCIĆ — I DLATEGO WSZYSTKO JEST W `try`
 * ────────────────────────────────────────────────────────────────────────
 *
 * To jest najważniejsza własność tego kodu i jedyna, której złamanie
 * pogorszyłoby sytuację względem stanu przed poprawką. Wiersz w `failed_jobs`
 * zapisuje INNY słuchacz tego samego zdarzenia — ten, który rejestruje
 * `queue:work` (`WorkCommand::logFailedJob`). Nasz jest rejestrowany
 * wcześniej (w dostawcy usług, przy starcie aplikacji), więc leci PIERWSZY.
 * Gdyby rzucił — bo padła baza, bo tabeli jeszcze nie ma po wdrożeniu kodu
 * przed migracją — zabrałby `failed_jobs` ten jeden zapis, na którym dziś
 * stoi cała diagnostyka. Zamiana „nikt się nie dowie" na „nikt się nie dowie
 * i nie ma nawet payloadu do ponowienia" byłaby poprawką w złą stronę.
 *
 * Dlatego: jeden `try` na wszystko, a porażka zapisu idzie do dziennika
 * i kończy sprawę.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TU NIE MA
 * ────────────────────────────────────────────────────────────────────────
 *
 *  - WYSYŁANIA ALARMU POCZTĄ. Alarm pocztą o awarii poczty jest podejrzany
 *    z definicji: przy wyczerpanym limicie dobowym — czyli w najczęstszym
 *    przypadku — list o awarii odbije się tak samo jak ten, o którym miał
 *    donieść, i wygeneruje własną porażkę, o której trzeba by donieść.
 *    Ślad jest więc TAM, GDZIE NIE ZALEŻY OD POCZTY: wiersz w bazie,
 *    `degraded` w `/health` i wpis `Log::error` w dzienniku serwera.
 *    UWAGA, TO BYŁO TU NAPISANE NIEPRAWDZIWIE: pierwsza wersja tego akapitu
 *    dodawała „a stąd webhook błędów z D-041 i Sentry". Sprawdzone w kodzie
 *    — kanał `blad_webhook` nie jest częścią stosu domyślnego
 *    (`LOG_STACK=single`) i woła się do niego jawnie, a Sentry'ego nie ma
 *    w `composer.json` wcale. Automatem, który zamienia `checks.listy`
 *    w dzwonek, jest więc `/health` (od PR #255 dzwoni na ten kanał sam)
 *    albo zewnętrzny monitoring czytający TREŚĆ odpowiedzi — nie ten wpis.
 *    Pełne uzasadnienie i sprostowanie: D-062 §3.
 *  - PONAWIANIA. Nie budujemy drugiego systemu kolejek. Ponowienie to
 *    `php artisan queue:retry`, a komenda `kuking:nieudane-listy` mówi,
 *    czy w tym przypadku ma ono sens.
 */
final class ZapiszNieudanyList
{
    /** Ile ogniw łańcucha przyczyn przeglądamy, szukając awarii transportu. */
    private const GLEBOKOSC_LANCUCHA = 10;

    public function __invoke(JobFailed $zdarzenie): void
    {
        try {
            $this->zapisz($zdarzenie);
        } catch (Throwable $e) {
            // Cel: NIE przerwać zapisu do `failed_jobs` (patrz komentarz
            // klasy). Nazwa klasy i komunikat — bez payloadu zadania, bo ten
            // niesie adres odbiorcy i token.
            Log::error('Nie udało się zapisać śladu nieudanego listu.', [
                'wyjatek' => $e::class,
                'komunikat' => BezpiecznyKomunikat::z($e->getMessage()),
            ]);
        }
    }

    private function zapisz(JobFailed $zdarzenie): void
    {
        $odmowa = $this->awariaTransportu($zdarzenie->exception);

        if ($odmowa === null) {
            // Nieudane zadanie, ale nie o poczcie (przetwarzanie zdjęcia,
            // eksport danych). Nie nasza sprawa — `failed_jobs` zostaje
            // miejscem, w którym takie rzeczy widać.
            return;
        }

        $powod = $odmowa instanceof OdmowaEmailLabs ? $odmowa->powod() : PowodOdmowy::NIEZNANA;
        $uuid = $zdarzenie->job->uuid();

        // WYSZUKANIE PO `failed_job_uuid` ZAMIAST `create()`: to samo zdarzenie
        // potrafi dojść dwa razy (worker przerwany w trakcie sprzątania),
        // a dwa wiersze o jednym liście kazałyby właścicielowi zgadywać, czy
        // przepadł jeden list, czy dwa.
        //
        // `firstOrNew([...])` byłoby krótsze, ale przypisuje pola MASOWO —
        // a model nie ma `$fillable` (jak `LoginLinkToken`), więc Eloquent
        // rzuciłby `MassAssignmentException`. Tu jest to szczególnie zdradliwe:
        // wyjątek zostałby złapany przez `__invoke()` i ślad NIE POWSTAŁBY,
        // po cichu. Pola przypisujemy więc jawnie, jedno po drugim.
        $slad = $uuid === null
            ? new MailFailure
            : MailFailure::query()->where('failed_job_uuid', $uuid)->first() ?? new MailFailure;

        $slad->failed_job_uuid = $uuid;
        $slad->powod = $powod;
        $slad->status_http = $odmowa instanceof OdmowaEmailLabs ? $odmowa->statusHttp() : null;
        $slad->rodzaj = $this->rodzaj($zdarzenie->job);
        $slad->kolejka = mb_substr($zdarzenie->job->getQueue() ?? '', 0, 100) ?: null;
        $slad->prob = max(1, $zdarzenie->job->attempts());
        $slad->user_id = $this->ktoCzekal($zdarzenie->job);
        $slad->komunikat = BezpiecznyKomunikat::z($odmowa->getMessage());
        $slad->failed_at = now();
        $slad->save();

        // `error`, nie `warning`: to jest utracona wiadomość do człowieka,
        // a nie niedogodność. Poziom nie jest jednak drogą na webhook —
        // ten wpis idzie do dziennika serwera i tam zostaje (sprostowanie
        // w D-062 §3). O awarii mówi na zewnątrz `/health`, przez wiersz,
        // który powstaje linijkę wyżej.
        //
        // W kontekście są wyłącznie rzeczy bezpieczne: kategoria, kod HTTP,
        // nazwa klasy powiadomienia, uuid zadania. Żadnego adresu, tematu ani
        // treści (AGENTS.md §7).
        Log::error('Poczta: list przepadł i nikt go już nie wyśle.', [
            'powod' => $powod->value,
            'opis' => $powod->opis(),
            'co_zrobic' => $powod->coZrobic(),
            'status_http' => $slad->status_http,
            'rodzaj' => $slad->rodzaj,
            'zadanie' => $uuid,
            'prob' => $slad->prob,
        ]);

        $this->posprzataj();
    }

    /**
     * Sprzątanie ODHACZONYCH wierszy starszych niż `poczta.retencja_dni`.
     *
     * DLACZEGO PRZY ZAPISIE, A NIE W HARMONOGRAMIE
     * Bo to jest jedno `DELETE` na zdarzenie, które w zdrowym tygodniu nie
     * zachodzi ani razu. Osobne zadanie w `routes/console.php` znaczyłoby
     * kolejną pozycję w harmonogramie, kolejną komendę i kolejną rzecz do
     * pamiętania — a sprzątanie miałoby co robić raz na kilka miesięcy.
     * `docs/decyzje/ADR_RETENCJE.md` pilnuje tabel z danymi osobowymi;
     * tu nie ma ani adresu, ani treści, więc chodzi wyłącznie o to, żeby
     * tabela nie rosła bez końca.
     *
     * NIEODHACZONYCH NIE KASUJEMY NIGDY, choćby były sprzed roku: to jedyna
     * wiedza o tym, że list przepadł, a wiek jej nie unieważnia.
     */
    private function posprzataj(): void
    {
        $dni = (int) config('kuking.poczta.retencja_dni', 90);

        if ($dni < 1) {
            return;
        }

        MailFailure::query()
            ->whereNotNull('zauwazony_at')
            ->where('failed_at', '<', now()->subDays($dni))
            ->delete();
    }

    /**
     * Awaria transportu poczty gdziekolwiek w łańcuchu przyczyn.
     *
     * Warunkiem jest TYP, nie treść komunikatu. Zgadywanie z tekstu („czy
     * w komunikacie jest słowo mail") byłoby dokładnie tak kruche, jak
     * zgadywanie powodu awarii `/health` z `$e->getMessage()` (audyt W7-07):
     * wystarczyłaby zmiana wersji biblioteki, żeby ślad przestał powstawać
     * — po cichu, bo nic by o tym nie krzyknęło.
     *
     * Łańcuch przeglądamy, bo Laravel owija wyjątki: przy powiadomieniu
     * kolejkowanym `TransportException` bywa przyczyną, nie wyjątkiem
     * najwyższym.
     */
    private function awariaTransportu(Throwable $wyjatek): ?Throwable
    {
        $ogniwo = $wyjatek;

        for ($krok = 0; $krok < self::GLEBOKOSC_LANCUCHA; $krok++) {
            if ($ogniwo instanceof TransportExceptionInterface) {
                return $ogniwo;
            }

            $poprzednie = $ogniwo->getPrevious();

            if ($poprzednie === null) {
                return null;
            }

            $ogniwo = $poprzednie;
        }

        return null;
    }

    /**
     * CO przepadło — nazwa klasy powiadomienia albo wiadomości.
     *
     * `displayName` z payloadu jest tu właściwym źródłem: dla powiadomień
     * Laravel wkłada tam klasę POWIADOMIENIA (`App\Notifications\
     * PotwierdzenieAdresu`), a nie opakowującego zadania — czyli dokładnie
     * to, co właściciel chce przeczytać. `resolveName()` jest zapasem.
     */
    private function rodzaj(Job $zadanie): string
    {
        $payload = $zadanie->payload();
        $nazwa = $payload['displayName'] ?? null;

        if (! is_string($nazwa) || trim($nazwa) === '') {
            $nazwa = $zadanie->resolveName();
        }

        return mb_substr(trim($nazwa), 0, 255);
    }

    /**
     * KTO CZEKAŁ NA TEN LIST — jeśli da się to ustalić bez zgadywania.
     *
     * To jest najcenniejsza informacja w całym wierszu: w grupie 50+ osoba,
     * która nie dostała potwierdzenia rejestracji, nie napisze reklamacji.
     * Po prostu odejdzie i uzna, że serwis nie działa. Właściciel ma więc
     * wiedzieć, do kogo napisać innym kanałem.
     *
     * DLACZEGO „BEST EFFORT" I DLACZEGO NULL JEST DOBRYM WYNIKIEM
     * Adresat siedzi w zserializowanym poleceniu w payloadzie. Odczytanie go
     * wymaga `unserialize`, a przy `SerializesModels` to odtwarza model
     * z bazy — czyli może się nie udać (konto skasowane, baza w kiepskim
     * stanie akurat teraz). Cały ten kod jest więc dodatkiem: gdy się nie
     * udaje, wiersz powstaje bez `user_id`, a adres i tak zostaje
     * w `failed_jobs`, które `php artisan queue:failed` pokazuje.
     *
     * ŚWIADOMIE OBSŁUGUJEMY TYLKO POWIADOMIENIA (`SendQueuedNotifications`),
     * a nie `SendQueuedMailable`. Powód: `Mailable` niesie ADRES, nie konto,
     * więc ustalenie „kto to" wymagałoby zapytania po adresie e-mail —
     * czyli wyciągnięcia adresu z payloadu i przepuszczenia go przez kod,
     * przez który dziś nie przechodzi. Cała poczta transakcyjna Kuking
     * (potwierdzenie adresu, nowe hasło, link do logowania) idzie
     * powiadomieniami, więc obsłużony jest przypadek, o który chodzi.
     */
    private function ktoCzekal(Job $zadanie): ?string
    {
        try {
            $polecenie = $zadanie->payload()['data']['command'] ?? null;

            if (! is_string($polecenie) || $polecenie === '') {
                return null;
            }

            $obiekt = unserialize($polecenie);

            if (! $obiekt instanceof SendQueuedNotifications) {
                return null;
            }

            $adresaci = $obiekt->notifiables;

            if (! is_iterable($adresaci)) {
                return null;
            }

            foreach ($adresaci as $adresat) {
                if ($adresat instanceof User) {
                    return $adresat->getKey();
                }

                // Inny model niż konto (dziś nie ma takiego przypadku) nie
                // odpowiada na pytanie „do kogo napisać" — pomijamy zamiast
                // wkładać do kolumny `user_id` obcy identyfikator.
                if ($adresat instanceof Model) {
                    return null;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
