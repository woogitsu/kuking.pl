<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Media\KasujZdjecie;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Egzekucja karencji: trwałe usunięcie/anonimizacja danych osobowych konta
 * po upływie 30 dni od zgłoszenia (audyt A8, RODO art. 17).
 *
 * CO KASUJEMY, A CZEGO ŚWIADOMIE NIE
 * `docs/legal/COMPLIANCE.md` §2 wprost dopuszcza (i zaleca) zachowanie treści
 * o realnej wartości społecznej — przepisów, do których inni się odwoływali,
 * komentarzy w wątkach innych osób — w formie zanonimizowanej („autor: konto
 * usunięte"), pod warunkiem że DANE OSOBOWE autora znikają. Dlatego ta klasa:
 *
 *  - anonimizuje `users` (e-mail, hasło, token) i `profiles` (nazwa, bio,
 *    avatar) — to są dane, po których da się rozpoznać konkretnego człowieka;
 *  - NIE kasuje `posts`, `recipes`, `comments`, `cooked_events` — TEKST
 *    zostaje, przypisany do już zanonimizowanego konta. Usuwanie go byłoby
 *    kasowaniem cudzej historii gotowania: komuś ktoś kiedyś odpowiedział
 *    w komentarzu, ktoś ugotował z tego przepisu i ma go w zeszycie.
 *    RODO chroni DANE OSOBOWE, nie fakt istnienia wpisu, a zanonimizowany
 *    tekst przepisu danymi osobowymi nie jest.
 *  - KASUJE WSZYSTKIE ZDJĘCIA TEJ OSOBY, nie tylko profilowe (audyt W4-01,
 *    decyzja D-018). Ze zdjęciem jest inaczej niż z tekstem: samo w sobie
 *    bywa danymi osobowymi — twarz, wnętrze mieszkania, dokument na stole,
 *    a w oryginale jeszcze EXIF ze współrzędnymi. Anonimizacja podpisu nie
 *    zmienia tam niczego, bo dane są w pikselach.
 *
 *    Do tej pory kasowane było wyłącznie zdjęcie profilowe, a ekran usuwania
 *    konta kazał potwierdzić: „Rozumiem, że po 30 dniach moje wpisy, przepisy
 *    i zdjęcia zostaną usunięte na stałe". Kod tego nie robił. To nie jest
 *    spór o interpretację przepisu — to obietnica złożona konkretnym zdaniem
 *    i niedotrzymana.
 *  - odpina zdjęcie profilowe i KASUJE PLIK razem z wariantami (issue #93),
 *    o ile nic innego na nie nie wskazuje. Wcześniej odpinana była sama
 *    referencja: zdjęcie twarzy — a często zdjęcie z nietkniętym EXIF-em,
 *    czyli modelem telefonu i współrzędnymi miejsca — leżało dalej pod
 *    adresem, który wciąż działał. Człowiek prosił o usunięcie konta,
 *    dostawał potwierdzenie, że dane zostały usunięte, i to nie była prawda.
 *
 * IDEMPOTENCJA I ODPORNOŚĆ NA PRZERWANIE
 * Cała anonimizacja jednego konta idzie w JEDNEJ transakcji z `lockForUpdate`
 * na świeżo pobranym wierszu. Dwa niezależne uruchomienia egzekutora (albo
 * jedno przerwane w połowie pętli po wielu kontach) nie psują nic: konto już
 * oznaczone `data_erased_at` jest pomijane (metoda zwraca `false` i nic nie
 * zapisuje), a przerwanie między jednym kontem a drugim zostawia już
 * przetworzone konta w pełni anonimowe, a nieprzetworzone — nietknięte
 * i do podjęcia przy następnym uruchomieniu.
 */
final class EraseAccountData
{
    public function __construct(private readonly KasujZdjecie $kasujZdjecie = new KasujZdjecie) {}

    /** @return bool Prawda, jeśli TO wywołanie faktycznie coś usunęło. */
    public function handle(User $user): bool
    {
        /** @var list<Media> $doSkasowania */
        $doSkasowania = [];

        $wymazano = DB::transaction(function () use ($user, &$doSkasowania): bool {
            // Świeży odczyt pod blokadą, nie ufamy stanowi z argumentu —
            // między zapytaniem, które wybrało konta do egzekucji, a tym
            // wywołaniem ktoś mógł cofnąć usunięcie albo inny proces mógł
            // już to konto obsłużyć.
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($fresh === null
                || $fresh->status !== User::STATUS_PENDING_DELETE
                || $fresh->data_erased_at !== null
            ) {
                return false;
            }

            $profile = $fresh->profile;

            // WSZYSTKIE zdjęcia tej osoby, nie tylko profilowe (D-018).
            // Zbieramy TERAZ, bo za chwilę odepniemy referencję z profilu
            // i awatara nie dałoby się już znaleźć tą drogą.
            $doSkasowania = $fresh->media()->get()->all();

            if ($profile !== null) {
                $profile->forceFill([
                    'username' => $this->anonimowaNazwa($fresh),
                    'display_name' => 'Użytkownik usunięty',
                    'bio' => null,
                    'avatar_media_id' => null,
                    'region' => null,
                    'speciality' => null,
                ])->save();
            }

            $fresh->forceFill([
                'email' => $this->anonimowyEmail($fresh),
                'password' => Hash::make(Str::random(40)),
                'remember_token' => null,
                'email_verified_at' => null,
                'wants_weekly_digest' => false,
                'data_erased_at' => now(),
            ])->save();

            // Defensywnie: `markForDeletion()` kasuje sesje z innych
            // przeglądarek już przy zgłoszeniu, ale między zgłoszeniem
            // a egzekucją minęło 30 dni — gdyby coś tę pierwszą operację
            // ominęło (np. status zmieniony ręcznie podczas incydentu),
            // egzekutor i tak zamyka wszystkie sesje przy wykonaniu.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $fresh->getKey())
                    ->delete();
            }

            return true;
        });

        // KASOWANIE PLIKU POZA TRANSAKCJĄ, I TO NIE JEST DROBIAZG.
        //
        // Wycofanie transakcji nie przywróci skasowanego pliku. Gdyby coś
        // padło po usunięciu zdjęcia, a przed zapisem wiersza, konto zostałoby
        // nietknięte, a zdjęcie zniknęłoby bez śladu i bez powodu. Kasujemy
        // więc dopiero wtedy, gdy anonimizacja jest już zatwierdzona.
        //
        // Kolejność ma i drugi skutek: w tym momencie referencja z profilu
        // jest już usunięta, więc sprawdzenie „czy ktoś tego jeszcze używa"
        // nie zobaczy samego siebie.
        if ($wymazano && $doSkasowania !== []) {
            foreach ($doSkasowania as $zdjecie) {
                // `skasujPliki()` + `delete()`, a NIE `jesliNieuzywane()`.
                //
                // Tamta metoda odmawia skasowania zdjęcia, do którego coś
                // jeszcze wskazuje — a tu wskazują WŁASNE wpisy i przepisy tej
                // osoby, które zostają. Przy `jesliNieuzywane()` nie
                // skasowałoby się więc nic poza awatarem, czyli dokładnie stan
                // sprzed tej naprawy.
                //
                // To jest jedyne miejsce w serwisie, w którym wolno tak zrobić,
                // i wolno wyłącznie dlatego, że kasujemy KOMPLET zdjęć jednej
                // osoby na jej własne żądanie. Wpisy zostają wtedy bez zdjęcia
                // — `x-photo` pokazuje w tym stanie komunikat, a nie pustą
                // ramkę.
                $this->kasujZdjecie->skasujPliki($zdjecie);
                $zdjecie->delete();
            }
        }

        return $wymazano;
    }

    /**
     * Deterministyczny, unikalny adres — pochodzi z ID konta (UUID, już
     * unikalne), więc nie trzeba sprawdzać kolizji ani losować niczego.
     */
    private function anonimowyEmail(User $user): string
    {
        return 'usuniete+'.$user->getKey().'@konto.kuking.pl';
    }

    /**
     * Nazwa musi przejść CHECK `^[a-zA-Z0-9_]{3,40}$` (migracja
     * `2026_09_05_000200_create_profiles_table`) i pozostać unikalna —
     * fragment ID konta załatwia obie rzeczy bez losowania.
     */
    private function anonimowaNazwa(User $user): string
    {
        return 'usuniety_'.substr(str_replace('-', '', $user->getKey()), 0, 24);
    }
}
