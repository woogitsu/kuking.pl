<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Media\KasujZdjecie;
use App\Models\DataExport;
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
 *  - PRZY ZAKRESIE `minimum` (domyślnym, D-022) NIE kasuje `posts`,
 *    `recipes`, `comments`, `cooked_events` — TEKST zostaje, przypisany do
 *    już zanonimizowanego konta i — od D-022 — NADAL WIDOCZNY. Usuwanie go byłoby
 *    kasowaniem cudzej historii gotowania: komuś ktoś kiedyś odpowiedział
 *    w komentarzu, ktoś ugotował z tego przepisu i ma go w zeszycie.
 *    RODO chroni DANE OSOBOWE, nie fakt istnienia wpisu, a zanonimizowany
 *    tekst przepisu danymi osobowymi nie jest.
 *
 *    ZAKRES WYBIERA JEDNAK CZŁOWIEK, NIE MY (D-022). Powyższe jest naszą
 *    oceną, że tak jest lepiej dla społeczności — a tej oceny nie wolno
 *    robić za kogoś przy jego własnych danych. Kto zaznaczy haczyk na
 *    ekranie usuwania konta, dostaje `delete_scope = everything`
 *    i `usunTresci()` niżej kasuje wszystko na stałe.
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
        $fresh = User::query()->whereKey($user->getKey())->first();

        // PONOWIENIE (audyt/issue #17): konto jest JUŻ zanonimizowane
        // (`data_erased_at` ustawione w poprzednim, udanym uruchomieniu), ale
        // zostały nieskasowane zdjęcia — bo poprzednia próba kasowania plików,
        // niżej w tej metodzie, PADŁA W POŁOWIE (na przykład: R2 rzuciło na
        // drugim z trzech wariantów). Anonimizacja to osobna, już zatwierdzona
        // transakcja i nie ma czego w niej powtarzać — tu kończymy WYŁĄCZNIE
        // kasowanie plików, tych samych zdjęć, tą samą metodą.
        //
        // Bez tej gałęzi takie zdjęcie nie miało ŻADNEJ drogi powrotnej:
        // `PurgeExpiredAccountDeletions` wybiera konta do PEŁNEJ egzekucji po
        // `whereNull('data_erased_at')`, a to konto już go nie ma. Wiersz
        // `media` — i plik z pełnym, nietkniętym EXIF-em — zostawałby więc
        // bezterminowo, mimo że człowiek dostał potwierdzenie usunięcia
        // danych.
        if ($fresh !== null && $fresh->data_erased_at !== null) {
            $zostaly = $fresh->media()->get()->all();

            if ($zostaly === []) {
                return false;
            }

            return $this->dokonczKasowanieZdjec($zostaly) > 0;
        }

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

            // ZAKRES WYBRANY PRZEZ CZŁOWIEKA 30 DNI TEMU (D-022).
            //
            // Czytamy KOLUMNĘ, nie żądanie HTTP — ekran, na którym stawiano
            // haczyk, dawno się zamknął. Domyślny `minimum` (haczyk
            // nietknięty) zostawia teksty; `everything` kasuje je razem
            // z resztą.
            if ($fresh->chceUsunacTresci()) {
                $this->usunTresci($fresh);
            }

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
                // `ostatnio_widziany_at` (issue #114/#115) jest DANĄ OSOBOWĄ
                // tego samego rodzaju co reszta pól wyżej — mówi, kiedy
                // KONKRETNA osoba ostatnio korzystała z serwisu. Konto
                // anonimizowane nie ma już logowania ani sesji, więc nowej
                // wartości i tak nikt tu nie zapisze — ale STARA wartość,
                // zostawiona bez zmiany, byłaby jedynym miejscem w bazie,
                // które wciąż wiąże ten fakt z (już anonimowym) kontem.
                // `resources/legal/polityka-prywatnosci.md` obiecuje wprost,
                // że ten znacznik znika wraz z usunięciem/anonimizacją konta —
                // to zdanie musi się tu zgadzać z kodem (AGENTS.md, część 11
                // i `tests/Feature/DokumentyPrawneNieKlamiaTest.php`).
                'ostatnio_widziany_at' => null,
            ])->save();

            // STAN KOŃCOWY KONTA — I TO JEST NAPRAWA DRUGIEJ POŁOWY D-018.
            //
            // Do tej pory konto zostawało tu na `pending_delete`, a ten status
            // znaczy „karencja trwa, treści schowane". Skutek był taki, że
            // zanonimizowany tekst zostawał w bazie i znikał z serwisu na
            // zawsze: przepis 403, wpis 403, profil 403, komentarz niewidoczny
            // nawet dla autora wpisu. D-018 obiecało jedno, serwis robił
            // drugie — i nikt tego nie zauważył, bo test asertował obecność
            // wiersza w bazie, nie widoczność na ekranie.
            //
            // `erased` jest stanem KOŃCOWYM i osobnym: logowania nie ma,
            // odzyskania nie ma, wyszukiwarka i listy osób tego konta nie
            // pokazują — ale tekst, który po tej osobie został, jest widoczny
            // pod podpisem „Użytkownik usunięty".
            $fresh->markDataErased();

            // GOTOWA PACZKA DANYCH PRZESTAJE BYĆ DO POBRANIA.
            //
            // Paczka to kopia CAŁEGO konta: adres e-mail, wszystkie treści,
            // wszystkie zdjęcia — w tym oryginały. Wisiała pod podpisanym
            // adresem jeszcze do siedmiu dni PO wymazaniu konta, bo ta akcja
            // nie tykała `data_exports` wcale. Człowiek, który poprosił
            // o usunięcie konta, nie ma powodu zakładać, że najpełniejsza
            // kopia jego danych zostaje osiągalna pod adresem, który kiedyś
            // dostał mailem.
            //
            // PRZESTAWIAMY TERMIN, A NIE KASUJEMY PLIKU TUTAJ. Kasowanie
            // z weryfikacją (`exists()` po fakcie) i z ponawianiem przy
            // porażce jest już napisane i przetestowane
            // w `kuking:sprzataj-eksporty`. Duplikowanie go tu dałoby drugą
            // implementację tej samej rzeczy — i to ta gorsza wersja
            // musiałaby żyć wewnątrz transakcji, gdzie kasowanie pliku jest
            // nieodwracalne przy wycofaniu.
            //
            // Skutek jest natychmiastowy tam, gdzie ma być: `isDownloadable()`
            // patrzy na `expires_at`, więc dostęp znika w tej samej sekundzie.
            // Sam plik znika tą samą drogą co każda inna wygasła paczka.
            DataExport::query()
                ->where('user_id', $fresh->getKey())
                ->whereIn('status', [DataExport::STATUS_READY, DataExport::STATUS_QUEUED, DataExport::STATUS_PROCESSING])
                ->update(['expires_at' => now()->subSecond()]);

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
            $this->dokonczKasowanieZdjec($doSkasowania);
        }

        return $wymazano;
    }

    /**
     * Kasuje komplet plików podanych zdjęć i, TYLKO PRZY PEŁNYM SUKCESIE,
     * sam wiersz `media` — dla konta, którego anonimizacja jest już
     * zatwierdzona (audyt/issue #17).
     *
     * `skasujPliki()` + `delete()`, a NIE `jesliNieuzywane()`.
     *
     * Tamta metoda odmawia skasowania zdjęcia, do którego coś jeszcze
     * wskazuje — a tu wskazują WŁASNE wpisy i przepisy tej osoby, które
     * zostają. Przy `jesliNieuzywane()` nie skasowałoby się więc nic poza
     * awatarem, czyli dokładnie stan sprzed naprawy W4-01.
     *
     * To jest jedyne miejsce w serwisie, w którym wolno tak zrobić, i wolno
     * wyłącznie dlatego, że kasujemy KOMPLET zdjęć jednej osoby na jej
     * własne żądanie. Wpisy zostają wtedy bez zdjęcia — `x-photo` pokazuje
     * w tym stanie komunikat, a nie pustą ramkę.
     *
     * WIERSZ ZOSTAJE PRZY NIEPEŁNYM SKASOWANIU (audyt/issue #17), a nie
     * znika razem z plikiem, którego kasowanie się nie udało. Ten wiersz
     * jest jedynym śladem, po którym `handle()` — wywołane ponownie dla tego
     * samego, już zanonimizowanego konta (gałąź na górze tej klasy) — wie, co
     * jeszcze dokończyć. `skasujPliki()` sama próbuje KAŻDY plik na KAŻDYM
     * dysku niezależnie od porażki poprzedniego, więc jedna nieudana próba
     * nie blokuje reszty zdjęć w tej pętli.
     *
     * @param  list<Media>  $zdjecia
     * @return int ile zdjęć skasowano W KOMPLECIE (wiersz i wszystkie pliki)
     */
    private function dokonczKasowanieZdjec(array $zdjecia): int
    {
        $skasowane = 0;

        foreach ($zdjecia as $zdjecie) {
            if ($this->kasujZdjecie->skasujPliki($zdjecie)) {
                $zdjecie->delete();
                $skasowane++;
            }
        }

        return $skasowane;
    }

    /**
     * PEŁNE USUNIĘCIE TREŚCI — tylko przy `delete_scope = everything` (D-022).
     *
     * CO KASUJEMY I W JAKIEJ KOLEJNOŚCI
     * Komentarze i wykonania przed wpisami i przepisami — nie z powodu
     * kluczy obcych (te i tak mają `ON DELETE CASCADE`), a żeby liczba
     * skasowanych wierszy była przewidywalna, gdyby ktoś kiedyś dopisał tu
     * raportowanie.
     *
     * `withTrashed()` PRZY KAŻDYM ZAPYTANIU, I TO NIE JEST DROBIAZG.
     * Przepis, który ta osoba skasowała sama pół roku wcześniej, leży dalej
     * w tabeli z pełnym tekstem — soft delete to ukrycie, nie usunięcie.
     * Bez `withTrashed()` „usuń wszystko" pomijałoby dokładnie te wiersze,
     * o których człowiek jest najbardziej przekonany, że ich już nie ma.
     *
     * `forceDelete()`, NIE `delete()`. Soft delete zostawiłby tekst
     * w bazie i zamienił obietnicę „usuwamy wszystko" w to samo, czym była
     * przed D-022: zapis w kolumnie.
     *
     * CENA, KTÓRĄ EKRAN MUSI POWIEDZIEĆ WPROST (i mówi):
     * kaskady zabierają razem z przepisem cudze komentarze i cudze
     * wykonania pod nim, a razem ze wpisem — cudze komentarze. To jest
     * dokładnie ten skutek, przez który D-018 odrzuciło kasowanie treści
     * jako zachowanie DOMYŚLNE. Tutaj dzieje się wyłącznie na wyraźne
     * życzenie, po zaznaczeniu odhaczonego haczyka.
     */
    private function usunTresci(User $user): void
    {
        $user->comments()->withTrashed()->forceDelete();
        $user->cookedEvents()->delete();
        $user->posts()->withTrashed()->forceDelete();
        $user->recipes()->withTrashed()->forceDelete();

        // Zeszyty (`collections`) razem z zawartością — `collection_items`
        // mają kaskadę. To jest własna półka tej osoby, nie cudza historia:
        // nikt inny nie traci tu niczego poza tym, że przestaje istnieć
        // publiczny zeszyt konta, którego już nie ma.
        $user->collections()->delete();
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
