<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Compliance\RejestrPotwierdzenRodo;
use App\Domain\Media\KasujZdjecie;
use App\Domain\Users\Exports\ExportFileNames;
use App\Domain\Zgody\PrzestawZgodeNaDigest;
use App\Models\ContactMessage;
use App\Models\DataExport;
use App\Models\MailFailure;
use App\Models\Media;
use App\Models\ProductSignal;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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
    public function __construct(
        private readonly KasujZdjecie $kasujZdjecie = new KasujZdjecie,
        private readonly PrzestawZgodeNaDigest $przestawZgode = new PrzestawZgodeNaDigest,
        private readonly RejestrPotwierdzenRodo $rejestr = new RejestrPotwierdzenRodo,
    ) {}

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
            // Konto wymazane przed poprawką #1324 nie przejdzie już przez
            // główną transakcję — ponowienie domyka i to powiązanie.
            $this->odlaczSygnalyProduktowe($fresh);

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
            // ZAPAMIĘTUJEMY ZAKRES TERAZ, ZANIM COKOLWIEK ZMIENIMY.
            // Ten sam odczyt powtórzony na końcu transakcji trafiłby już na
            // wiersz po anonimizacji, a `zakres` w potwierdzeniu ma być
            // zapisem tego, co NAPRAWDĘ zrobiliśmy, nie domysłem
            // („pewnie minimum, bo taki jest domyślny").
            $zakresWykonany = $fresh->chceUsunacTresci()
                ? User::DELETE_SCOPE_EVERYTHING
                : User::DELETE_SCOPE_MINIMUM;

            if ($fresh->chceUsunacTresci()) {
                $this->usunTresci($fresh);
            }

            /*
             * RELACJE ZNIKAJĄ RAZEM Z KONTEM (audyt zewnętrzny G05).
             *
             * `resources/legal/polityka-prywatnosci.md`, tabela w §2, wiersz
             * „Relacje w serwisie": „kogo obserwujesz, kogo zablokowałeś …
             * Do usunięcia relacji LUB KONTA". Ta akcja nie tykała tych
             * tabel wcale, więc obietnica była nieprawdziwa — a AGENTS.md
             * część 11 mówi, że zdania z polityki mają zgadzać się z kodem.
             *
             * DETACH PRZEZ RELACJE MODELU, NIE `DB::table(...)->delete()`.
             * Każdy `detach()` jest z definicji zawężony do TEGO konta, więc
             * nie da się nim przypadkiem zabrać relacji dwóch obcych osób —
             * to jest najgorsza możliwa awaria tej zmiany i pilnuje jej
             * osobny test. Idzie też za definicją relacji, więc zmiana nazwy
             * tabeli nie zostawi tu martwego zapytania.
             *
             * W OBIE STRONY, bo `follows` i `blocks` trzymają jedno i drugie
             * w tym samym wierszu, tylko z różnych stron — ale KOLEJNOŚĆ tych
             * dwóch stron ustala `usunRelacjeWKolejnosciDanych()` niżej, a nie
             * ta lista wywołań (D-093). Dwa `detach()` pod rząd, „najpierw
             * moje, potem cudze", były zakleszczeniem Z-2.
             *
             * CZEGO TU NIE MA I DLACZEGO. Zgłoszeń, odwołań i dziennika
             * audytowego nie ruszamy: mają w polityce własny, dłuższy okres
             * retencji (36 miesięcy od zamknięcia sprawy) i inną podstawę
             * prawną niż umowa z użytkownikiem. Powiadomień też nie —
             * powiadomienie o decyzji moderacyjnej ma własny termin
             * (Regulamin §8, co najmniej 6 miesięcy), więc hurtowe kasowanie
             * po `user_id` łamałoby inną obietnicę; reszta znika po
             * 3 miesiącach nocnym sprzątaniem.
             */
            $this->usunRelacjeWKolejnosciDanych($fresh->following(), $fresh->followers());
            $this->usunRelacjeWKolejnosciDanych($fresh->blocking(), $fresh->blockedBy());

            /*
             * `tag_follows` ZOSTAJE JEDNYM HURTOWYM `detach()` I TO NIE JEST
             * NIEDOKOŃCZONA ROBOTA (D-093).
             *
             * Zakleszczenie Z-2 bierze się z tego, że dwie egzekucje kasują
             * TE SAME wiersze w przeciwnych kolejnościach. W `follows`
             * i `blocks` wspólne wiersze istnieją: para wzajemna ma
             * `(X,Y)` i `(Y,X)`, a każda z dwóch egzekucji dotyczy obu.
             * W `tag_follows` kluczem jest `(user_id, tag_id)`, więc dwie
             * egzekucje różnych kont nie mają ANI JEDNEGO wspólnego wiersza —
             * nie ma czego szeregować i nie ma jak zbudować cyklu.
             *
             * Wiersz `tags` po drugiej stronie klucza obcego też nie tworzy
             * tu wspólnego punktu, i to jest ZMIERZONE, nie wydedukowane:
             * `DELETE` z tabeli odsyłającej nie bierze na wierszu rodzica
             * ŻADNEJ blokady (0 blokad krotek i 0 wpisów w `pg_locks` dla
             * relacji rodzica). Blokady kluczy obcych, które dały Z-1, bierze
             * `INSERT` — nie `DELETE`.
             */
            $fresh->followedTags()->detach();

            /*
             * DRUGI SKŁADNIK LOGOWANIA ZNIKA RAZEM Z KONTEM (G05).
             *
             * Sekret i kody zapasowe leżą pod castem `encrypted`, więc to
             * NIE jest dziura pozwalająca zalogować się usuniętym kontem —
             * hasło i tak zostaje nadpisane losowym ciągiem niżej. Chodzi
             * o coś innego: to jest materiał uwierzytelniający konta, które
             * miało zostać wymazane, i nie ma powodu, żeby dalej leżał
             * w bazie. Ewentualny incydent obejmuje wtedy mniej danych.
             *
             * Wołamy nazwaną metodę zamiast dopisywać cztery kolumny do
             * `forceFill` niżej. Gdyby 2FA dostało piąte pole, ten kod
             * pójdzie za nim sam — a rozjazd między dwiema listami tych
             * samych kolumn to dokładnie ta klasa błędu, która dała G05.
             */
            $fresh->disableTwoFactor();

            /*
             * ZAMÓWIONA ZMIANA ADRESU E-MAIL ZNIKA RAZEM Z KONTEM (#195).
             *
             * Ten sam powód co przy 2FA wyżej, plus jeden własny.
             * `pending_email_changes` trzyma adres e-mail — daną osobową
             * osoby, która właśnie poprosiła o usunięcie konta. Wiersz
             * zostawiony po anonimizacji byłby jedynym miejscem w bazie,
             * w którym ten adres nadal stoi jawnie, i przeżyłby wymazanie
             * `users.email` niżej.
             *
             * Jawnie, a nie kaskadą klucza obcego: kont z Kuking się nie
             * KASUJE, tylko anonimizuje (D-022), więc `ON DELETE CASCADE`
             * nigdy by tu nie zadziałało.
             */
            $fresh->pendingEmailChange()->delete();

            /*
             * POWIĄZANIA Z KONTAMI U DOSTAWCÓW ZEWNĘTRZNYCH ZNIKAJĄ RAZEM
             * Z HASŁEM (D-069, D-098).
             *
             * Wiersz w `tozsamosci_zewnetrzne` JEST wejściem na konto
             * dokładnie tak samo jak hasło i sesja, więc obowiązuje go ta
             * sama zasada: po wymazaniu danych konto nie ma już właściciela
             * i nikt nie ma prawa na nie wejść. Bez tej linii losowe hasło
             * niżej nie chroniłoby niczego — kto miał to konto Google,
             * wchodziłby dalej jednym kliknięciem.
             *
             * Jawnie, a nie kaskadą klucza obcego — ten sam powód co przy
             * `pending_email_changes` wyżej: kont z Kuking się NIE KASUJE,
             * tylko anonimizuje (D-022), więc `ON DELETE CASCADE` nigdy by
             * tu nie zadziałało.
             */
            $fresh->tozsamosciZewnetrzne()->delete();

            $this->odlaczWiadomosciDoOperatora($fresh);
            $this->odlaczSygnalyProduktowe($fresh);
            $this->odlaczSladyNieudanychListow($fresh);

            /*
             * ZGODA NA POCZTĘ GAŚNIE Z DOWODEM, NIE PO CICHU (D-072).
             *
             * `forceFill` niżej i tak ustawia `wants_weekly_digest = false`,
             * ale sama flaga nie mówi, DLACZEGO wysyłka ustała. Dziennik zgód
             * kończyłby się wtedy wpisem „udzielona" bez żadnego zamknięcia —
             * czyli w papierach wyglądałoby to na zgodę obowiązującą do dziś,
             * choć konto zostało wymazane. Wiersz `wycofana` ze źródłem
             * `usuniecie_konta` domyka historię i jest jedyną odpowiedzią na
             * pytanie „skąd ta osoba zniknęła z listy odbiorców".
             *
             * WOŁANE PRZED `forceFill()`, bo `PrzestawZgodeNaDigest` dopisuje
             * wiersz TYLKO przy realnej zmianie: po przestawieniu flagi nie
             * byłoby już czego wycofywać i dziennik zostałby bez zamknięcia.
             * Konto, które zgody nie miało, nie dostaje tu żadnego wiersza —
             * nie było czego wycofać.
             *
             * ANONIMIZACJA NIE KASUJE DZIENNIKA ZGÓD i to jest świadome
             * rozstrzygnięcie napięcia „dowód zgody vs prawo do usunięcia"
             * (pełne uzasadnienie w D-072). Po tej metodzie wiersz `users` nie
             * ma już adresu, hasła ani nazwy, więc `user_id` w dzienniku nie
             * wskazuje na dane osobowe — a dowód, że wysyłka miała podstawę
             * prawną, zostaje. Wycofanie zgody NIGDY nie wywraca kasowania
             * konta: nieudany zapis dowodu jest tam tylko logowany
             * (`PrzestawZgodeNaDigest`), a nie rzucany dalej.
             */
            $this->przestawZgode->handle($fresh, false, WpisZgody::ZRODLO_USUNIECIE_KONTA);

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

            // LINK DO USTAWIENIA HASŁA (audyt B5, znalezisko 6). Tabela
            // resetów jest kluczowana ADRESEM, nie kontem — po anonimizacji
            // wiersz z prawdziwym e-mailem zostawał bez terminu (Laravel
            // sprząta go dopiero `auth:clear-resets`). Kasujemy po adresie
            // sprzed anonimizacji, tak jak `ConfirmEmailChange` po starym.
            DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                ->whereRaw('lower(email) = ?', [User::normalizeEmail((string) $fresh->email)])
                ->delete();

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
                'pwa_prompt_state' => null,
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

            // DOMKNIĘCIE SPRAWY W REJESTRZE RODO — W TEJ SAMEJ TRANSAKCJI CO
            // WYMAZANIE, I TO JEST CAŁY SENS UMIESZCZENIA TEGO TUTAJ.
            //
            // Potwierdzenie zapisane w OSOBNEJ transakcji potrafi kłamać
            // w obie strony: zapisane przed wymazaniem, które padło, twierdzi
            // „wykonane" o czymś, czego nie było; zapisane po wymazaniu,
            // które się udało, a samo padło — przemilcza wykonanie prawa
            // z art. 17 i zostawia sprawę wiecznie „w toku". Jedna transakcja
            // znaczy: albo dane są wymazane I jest na to potwierdzenie, albo
            // nie ma ani jednego, ani drugiego. Dowodzi tego
            // `PotwierdzenieRodoIdzieWTejSamejTransakcjiTest`, wymuszając
            // porażkę po każdej z tych dwóch stron.
            //
            // `konto_id` w tym wierszu ZOSTAJE — decyzja właściciela
            // z 21.09.2026, razem z jej ceną, opisana w
            // `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` §3.3 punkt 7.
            $this->rejestr->domknijJakoWykonane($fresh, $zakresWykonany);

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

        if ($wymazano) {
            $this->skasujPaczkiEksportu($user);
        }

        return $wymazano;
    }

    /**
     * Kasuje WSZYSTKIE wiersze symetrycznej tabeli relacji (`follows`,
     * `blocks`) dotyczące tego konta — po jednym wierszu, w kolejności
     * wyznaczonej PRZEZ DANE, a nie przez rolę konta w wierszu (Z-2, D-093).
     *
     * ── CO BYŁO ZŁAMANE ──
     *
     * Przedtem stały tu dwa hurtowe `detach()` w stałej kolejności ról:
     * najpierw wiersze, w których to konto jest stroną „moją" (`(X, *)`),
     * potem te, w których jest stroną „cudzą" (`(*, X)`). Dla pary, która
     * obserwuje się wzajemnie, egzekucja konta X brała więc `(X,Y)` a potem
     * `(Y,X)`, a egzekucja konta Y — dokładnie odwrotnie. Każda trzymała to,
     * na co czekała druga, i PostgreSQL zabijał jedną z nich:
     *
     *   ERROR: deadlock detected … while deleting tuple (0,5) in relation "follows"
     *
     * Skutek u człowieka: nocna komenda `kuking:usun-wygasle-konta` przerywa
     * się w połowie, a konto, które PROSIŁO o usunięcie, nie zostaje tej nocy
     * wymazane. `withoutOverlapping()` w `routes/console.php` chroni tylko
     * harmonogram przed samym sobą — nie chroni go przed ręcznym przebiegiem
     * właściciela obok harmonogramu (D-077 §3).
     *
     * ── DLACZEGO WIERSZ PO WIERSZU, A NIE JEDNO ZAPYTANIE Z `ORDER BY` ──
     *
     * Ten sam powód, dla którego `ZamekPary` bierze dwa wiersze `users`
     * dwoma osobnymi zapytaniami: `SELECT … ORDER BY … FOR UPDATE` blokuje
     * wiersze w kolejności, w jakiej wypuszcza je PLAN zapytania, a plan
     * zależy od statystyk i wersji bazy. Gwarancja stojąca na kształcie planu
     * nie jest gwarancją, a druga, słabsza reguła kolejności blokad w tej
     * samej dziedzinie to dokładnie ten rozjazd, przed którym ostrzega D-079.
     * `DELETE` w PostgreSQL nie przyjmuje przy tym `ORDER BY` wcale.
     *
     * Kolejność wyliczamy więc w PHP i wykonujemy jawnie — nudno, o tyle
     * zapytań drożej, ile relacji, i nie do zepsucia cudzą decyzją
     * o planowaniu. To są zapytania w JEDNEJ transakcji, nie tyle transakcji,
     * ile relacji — i dzieje się to w nocnej komendzie, nie w żądaniu HTTP.
     *
     * ── DLACZEGO NIE `ZamekPary` ──
     *
     * Bo tej klasy NIE DA SIĘ tu użyć, i to jest zmierzone, nie przyjęte na
     * wiarę. `handle()` trzyma już wiersz `users` tego konta pod
     * `FOR UPDATE` (reguła „konto najpierw", D-075). `ZamekPary` bierze OBA
     * wiersze pary rosnąco po identyfikatorze — czyli dla pary, w której to
     * konto ma identyfikator wyższy, chciałby wziąć najpierw wiersz drugiej
     * osoby. Dwie egzekucje na parze wzajemnej robią wtedy dokładnie cykl
     * z Z-1: każda trzyma własny wiersz `users` i czeka na cudzy. Zmierzone:
     * `ERROR: deadlock detected … while locking tuple … in relation "users"`.
     * Zagnieżdżone `DB::transaction()` jest przy tym tylko punktem powrotu,
     * więc blokady i tak żyłyby do commitu transakcji zewnętrznej.
     *
     * ── DLACZEGO ODCZYT BEZ BLOKADY WYSTARCZA ──
     *
     * Między odczytem listy wierszy a ich skasowaniem nikt nie dopisze nowej
     * relacji do TEGO konta, bo `handle()` trzyma jego wiersz `users` pod
     * `FOR UPDATE`, a każdy `INSERT` do `follows`/`blocks` bierze na wierszu
     * `users` blokadę `FOR KEY SHARE` przez sprawdzenie klucza obcego — i te
     * dwie są w konflikcie (pomiar E8 z audytu, opisany w D-090). Gwarancji
     * nie daje tu więc konwencja („każdy zapis idzie przez `FollowUser`"),
     * tylko kształt klucza obcego, którego nie da się obejść drugim
     * endpointem.
     *
     * ── DETACH ZOSTAJE ──
     *
     * Kasujemy nadal przez relację, nie przez `DB::table(...)->delete()`:
     * `detach([$id])` jest z definicji zawężony do tego konta ORAZ do jednego
     * wskazanego wiersza, więc najgorsza możliwa awaria tej zmiany — zabranie
     * relacji dwóch obcych osób — pozostaje niemożliwa z konstrukcji.
     * Odczyt idzie po surowej tabeli, ale odczyt niczego nie kasuje; branie
     * go przez relację dokładałoby złączenie z `users` i naraziło listę na
     * dowolny przyszły zakres globalny na modelu konta.
     *
     * @param  BelongsToMany<User, User>  $moje  relacja od strony „to konto jest pierwszą kolumną"
     * @param  BelongsToMany<User, User>  $cudze  ta sama tabela widziana z drugiej strony
     */
    private function usunRelacjeWKolejnosciDanych(BelongsToMany $moje, BelongsToMany $cudze): void
    {
        $tabela = $moje->getTable();
        $mojaKolumna = $moje->getForeignPivotKeyName();
        $cudzaKolumna = $moje->getRelatedPivotKeyName();
        $ja = (string) $moje->getParent()->getKey();

        /** @var list<array{0: string, 1: string}> $wiersze */
        $wiersze = DB::table($tabela)
            ->where($mojaKolumna, $ja)
            ->orWhere($cudzaKolumna, $ja)
            ->get([$mojaKolumna, $cudzaKolumna])
            ->map(fn (object $wiersz): array => [
                (string) $wiersz->{$mojaKolumna},   // 0 — wartość „mojej" kolumny w tym wierszu
                (string) $wiersz->{$cudzaKolumna},  // 1 — wartość „cudzej"
            ])
            ->all();

        // KLUCZ SORTOWANIA JEST FUNKCJĄ SAMEGO WIERSZA I NICZEGO WIĘCEJ.
        //
        // Sortujemy po parze `(pierwsza kolumna, druga kolumna)`, czyli po
        // KLUCZU GŁÓWNYM wiersza. Który identyfikator siada w której pozycji,
        // wynika z DEFINICJI RELACJI — `following()` to zawsze
        // `follower_id → followed_id`, dla każdego konta jednakowo — a nie
        // z tego, KTÓRE konto jest właśnie wymazywane. Dlatego dla wiersza
        // `(X,Y)` obie egzekucje wyliczają ten sam klucz i ustawiają wiersze
        // w tej samej kolejności. To jest cała naprawa Z-2.
        //
        // Klucz nie musi nic znaczyć ani zgadzać się z porządkiem typu `uuid`
        // w PostgreSQL — musi być tylko TAKI SAM po obu stronach konfliktu.
        // Ta sama zasada i to samo porównanie łańcuchów co w `ZamekPary`.
        usort(
            $wiersze,
            static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]],
        );

        foreach ($wiersze as [$mojaWartosc, $cudzaWartosc]) {
            // Która strona relacji opisuje TEN wiersz. `CHECK`-i
            // `follows_no_self_check` / `blocks_no_self_check` gwarantują, że
            // obie kolumny nigdy nie wskazują na to samo konto, więc ta
            // gałąź jest jednoznaczna.
            if ($mojaWartosc === $ja) {
                $moje->detach([$cudzaWartosc]);
            } else {
                $cudze->detach([$mojaWartosc]);
            }
        }
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
            // Świeży, przejęty wiersz, nie model wczytany w transakcji
            // wymazania — patrz `KasujZdjecie::przejmijDoWymazania()` (#1003).
            $zdjecie = $this->kasujZdjecie->przejmijDoWymazania($zdjecie);

            if ($zdjecie === null) {
                continue;
            }

            if ($this->kasujZdjecie->skasujPliki($zdjecie)) {
                $zdjecie->delete();
                $skasowane++;
            }
        }

        return $skasowane;
    }

    /**
     * ŚLADY NIEUDANYCH LISTÓW ZOSTAJĄ, ALE BEZ KONTA (audyt B5, znalezisko 9).
     *
     * `mail_failures` to wiedza operatora, że jakiś list nie doszedł — bez
     * adresu i bez treści (`BezpiecznyKomunikat`). `user_id` mówił jednak,
     * KOMU nie doszedł, i po wymazaniu wskazywał konto bez końca: klucz ma
     * `nullOnDelete()`, a kont się nie kasuje (D-022), więc kaskada nigdy by
     * nie zadziałała. Wiersz zostaje (nieodhaczony zapala `/health`),
     * znika tylko powiązanie z osobą.
     */
    private function odlaczSladyNieudanychListow(User $user): void
    {
        MailFailure::query()
            ->where('user_id', $user->getKey())
            ->update(['user_id' => null]);
    }

    /**
     * WSZYSTKIE PACZKI EKSPORTU TEGO KONTA ZNIKAJĄ Z MAGAZYNU (audyt B5, pkt 4).
     *
     * Wygaszenie `expires_at` w transakcji wyżej zamyka pobieranie i oddaje
     * paczki `ready` nocnemu sprzątaniu — ale tylko te, których klucz jest
     * w wierszu. Paczka wgrana przez próbę, która padła przed `finalize()`
     * (`failed`, bez `object_key`), zostawała na zawsze. Cały prefiks
     * `eksporty/<user_id>/` kasujemy więc od razu, niezależnie od wierszy.
     *
     * Po commicie i bez wyjątku na zewnątrz: magazyn niedostępny w tej chwili
     * nie może cofnąć anonimizacji. Paczki `ready`/`expired` z kluczem
     * dobierze wtedy `kuking:sprzataj-eksporty`.
     */
    private function skasujPaczkiEksportu(User $user): void
    {
        try {
            Storage::disk((string) config('kuking.exports.disk'))->deleteDirectory(ExportFileNames::katalogKonta((string) $user->getKey()));
        } catch (Throwable $e) {
            Log::warning('Wymazanie konta: nie udało się skasować paczek eksportu z magazynu.', [
                'user_id' => $user->getKey(),
                'wyjatek' => $e::class,
            ]);
        }
    }

    /**
     * WIADOMOŚCI „NAPISZ DO NAS" ZOSTAJĄ, ALE TRACĄ POWIĄZANIE Z KONTEM (#995).
     *
     * `resources/legal/polityka-prywatnosci.md`, tabela w §2, wiersz
     * „Wiadomości do nas przez formularz »Napisz do nas«": „Jeśli usuniesz
     * konto, wiadomość zostaje, ale przestaje być z nim powiązana". Robimy
     * dokładnie to i nic więcej: `user_id → NULL`. Treść, odpowiedzi,
     * `status` i `handled_at` zostają, bo od `handled_at` liczy się
     * 12-miesięczna retencja, której ta akcja nie skraca.
     *
     * Jawnie, a nie kaskadą klucza obcego — ten sam powód co przy
     * `pending_email_changes`: kont z Kuking się NIE KASUJE, tylko
     * anonimizuje (D-022), więc `nullOnDelete()` nigdy by się nie uruchomił.
     *
     * `handled_by` (operator, który sprawę załatwił) celowo zostaje: obietnica
     * dotyczy nadawcy wiadomości, nie osoby obsługującej panel.
     */
    private function odlaczWiadomosciDoOperatora(User $user): void
    {
        ContactMessage::query()
            ->where('user_id', $user->getKey())
            ->update(['user_id' => null]);
    }

    /**
     * SYGNAŁY PRODUKTOWE ZOSTAJĄ, ALE BEZ KONTA (issue #1324).
     *
     * `product_signals.user_id` ma `nullOnDelete()`, a konta się nie kasuje
     * (D-022) — więc bez tej linii zdarzenia z ostatnich 90 dni dalej
     * wskazywały identyfikator wymazanego konta. Żaden raport nie potrzebuje
     * osoby po zamknięciu konta: liczy się fakt zdarzenia, więc wiersz
     * zostaje do zwykłej retencji (`PrzedawnioneSygnaly`) z `user_id = NULL`.
     *
     * Wyścig z sygnałem zapisywanym w tej samej chwili domyka
     * `ZapiszSygnal` — `FOR SHARE` na wierszu konta i sprawdzenie
     * `data_erased_at`.
     */
    private function odlaczSygnalyProduktowe(User $user): void
    {
        ProductSignal::query()
            ->where('user_id', $user->getKey())
            ->update(['user_id' => null]);
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
