<?php

declare(strict_types=1);

use App\Support\ScheduledArtisanCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Harmonogram
|--------------------------------------------------------------------------
*/

// Wygasłe paczki z danymi (RODO — minimalizacja: nie trzymamy kopii całych
// kont dłużej, niż potrzeba na ich pobranie). Codziennie w nocy, bo sprzątanie
// dotyka storage, a w nocy nikt nie czeka na odpowiedź serwisu.
// `withoutOverlapping` — przy dużej liczbie plików jedno uruchomienie może
// trwać dłużej niż dobę i nie chcemy dwóch naraz.
// UWAGA NA `Schedule::command()` — NIE UŻYWAMY GO TUTAJ.
//
// `Schedule::command()` uruchamia zadanie przez Symfony Process, a ten wymaga
// `proc_open`, wyłączonego w `docker/php.ini` (hardening, AGENTS.md zabrania
// go osłabiać). Na produkcji kończyło się to natychmiastowym:
//
//     The Process class relies on proc_open, which is not available
//     on your PHP installation.
//
// a w roli `all` śmierć harmonogramu kładła CAŁY kontener — objawiało się to
// losowymi 502 w trakcie zwykłej pracy.
//
// `Schedule::call()` wykonuje domknięcie w TYM SAMYM procesie PHP, więc
// `proc_open` nie jest potrzebny. ScheduledArtisanCommand::artisan() tłumaczy kod 0 na true,
// a każdy niezerowy na false: CallbackEvent nie rozpoznaje liczby 1 jako błędu (#835).
//
// Kosztem jest utrata `runInBackground()`: sprzątanie eksportów blokuje pętlę
// harmonogramu na czas swojego działania. Przy jednym uruchomieniu na dobę
// i `withoutOverlapping()` to jest do przyjęcia — a alternatywą byłoby
// osłabienie zabezpieczenia, którego nie wolno ruszać.
// Zdjęcia wgrane, ale do niczego nieprzypięte (audyt C1). Powstają, gdy ktoś
// wybierze zdjęcia, dostanie błąd walidacji i zamknie kartę zamiast poprawić.
// `Schedule::call()`, nie `command()` — uzasadnienie przy zadaniu niżej.
ScheduledArtisanCommand::artisan('kuking:sprzataj-osierocone-zdjecia')
    ->dailyAt('03:40')
    ->name('sprzataj-osierocone-zdjecia')
    ->withoutOverlapping();

ScheduledArtisanCommand::artisan('kuking:sprzataj-eksporty')
    ->name('kuking:sprzataj-eksporty')
    ->dailyAt('03:20')
    ->withoutOverlapping();

// Zdejmowanie kar, którym minął termin (issue #40).
//
// Co godzinę, nie raz na dobę: kara „do 12 września” ma się skończyć 12
// września, a nie następnej nocy. Przy dobowym harmonogramie ktoś ukarany na
// 7 dni siedziałby realnie do ośmiu — i to bez żadnej decyzji człowieka.
//
// Middleware EnsureAccountIsActive i tak przywraca konto natychmiast, gdy
// karany wejdzie na stronę po terminie. Ta komenda pilnuje kont, które po
// prostu nie wracają, żeby stan w bazie zgadzał się z rzeczywistością także
// dla moderacji i statystyk.
// `Schedule::call()`, nie `command()` — uzasadnienie przy zadaniu wyżej.
//
// `hourly()` to minuta 00 KAŻDEJ godziny, więc raz na dobę zadanie ląduje
// w tej samej minucie co `kuking:sprzataj-sygnaly` (04:00). Zostawiamy tak
// świadomie i nazywamy to tutaj, żeby kolejny audyt nie zgłaszał tego jako
// usterki: harmonogram wykonuje zadania z jednej minuty SEKWENCYJNIE, w tym
// samym procesie PHP, więc to nie jest wyścig. Oba zadania tykają w różnych
// tabelach i oba mają `withoutOverlapping()`.
//
// Gdyby liczba replik serwisu kiedykolwiek przekroczyła 1 (dziś jest jedna),
// KAŻDE zadanie w tym pliku musiałoby dostać `->onOneServer()` — nie z powodu
// tej pary, tylko dlatego, że każda replika ma własny harmonogram.
ScheduledArtisanCommand::artisan('kuking:zdejmij-wygasle-kary')
    ->name('kuking:zdejmij-wygasle-kary')
    ->hourly()
    ->withoutOverlapping();

// Egzekucja 30-dniowej karencji po zgłoszeniu usunięcia konta (audyt A8).
//
// Bez tego zadania obietnica z `docs/legal/COMPLIANCE.md` i z ekranu
// „Twoje dane" ("po 30 dniach dane zostaną usunięte na stałe") jest fikcją —
// nic wcześniej nie egzekwowało karencji, konto zostawało `pending_delete`
// bez końca. Codziennie w nocy, nie co godzinę: to nie kara z konkretną
// godziną wygaśnięcia jak zawieszenie, tylko okno na zmianę zdania liczone
// w dniach — dobowa dokładność wystarcza.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:usun-wygasle-konta')
    ->name('kuking:usun-wygasle-konta')
    ->dailyAt('03:50')
    ->withoutOverlapping();

// Retencja sygnałów produktowych (issue #115): `product_signals` starsze niż
// `config('kuking.analytics.signal_retention_days')` (domyślnie 90 dni) nie
// mają już żadnej wartości analitycznej, a minimalizacja danych (AGENTS.md
// §7) jest zasadą domyślną. Codziennie w nocy, nie co godzinę — to nie jest
// termin z konkretną godziną jak zawieszenie, dobowa dokładność wystarcza.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:sprzataj-sygnaly')
    ->name('kuking:sprzataj-sygnaly')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Retencja `audit_log` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.1):
// `config('kuking.audit_log.retention_months')` miesięcy od `created_at`,
// z wyjątkiem kategorii dowodowych RODO/DSA
// (`App\Models\AuditLogEntry::NIGDY_NIE_KASUJ`, nigdy nie kasowane).
// Codziennie w nocy, po sygnałach produktowych — dobowa dokładność
// wystarcza, liczymy w miesiącach, nie w konkretnej godzinie wygaśnięcia.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:sprzataj-audyt')
    ->name('kuking:sprzataj-audyt')
    ->dailyAt('04:10')
    ->withoutOverlapping();

// Retencja `notifications` (issue #19, ADR_RETENCJE.md §5.2):
// `config('kuking.notifications.retention_months')` miesięcy od
// `created_at`, niezależnie od `read_at`.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:sprzataj-powiadomienia')
    ->name('kuking:sprzataj-powiadomienia')
    ->dailyAt('04:20')
    ->withoutOverlapping();

// Retencja sprawy moderacyjnej — `appeals` + `moderation_actions` + `reports`
// razem, w tej kolejności (issue #19, ADR_RETENCJE.md §4, §5.3-5.5):
// `config('kuking.moderation.case_retention_months')` miesięcy (decyzja
// właściciela, art. 442¹ k.c.) od zamknięcia każdej sprawy. Jedna komenda dla
// trzech tabel, bo kasowanie `moderation_actions` przed jego `appeals`
// zabrałoby odwołanie kaskadą, zanim minął jego własny czas — patrz
// `App\Domain\Compliance\PrzedawnioneSprawyModeracyjne`.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:sprzataj-sprawy-moderacyjne')
    ->name('kuking:sprzataj-sprawy-moderacyjne')
    ->dailyAt('04:30')
    ->withoutOverlapping();

// Retencja `contact_messages` — wiadomości z „Napisz do nas".
// `config('kuking.kontakt.retention_months')` miesięcy od ZAŁATWIENIA
// (`handled_at`); wiadomości otwarte nie są kasowane nigdy, niezależnie od
// wieku. Osobna komenda i osobna liczba niż sprawy moderacyjne, bo to nie
// jest sprawa: nie ma tu decyzji, od której da się odwołać, ani sporu, do
// którego można by wrócić — uzasadnienie przy kluczu w `config/kuking.php`.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:sprzataj-wiadomosci')
    ->name('kuking:sprzataj-wiadomosci')
    ->dailyAt('04:40')
    ->withoutOverlapping();

// Wygasłe żądania zmiany adresu e-mail (issue #195). Wiersz
// `pending_email_changes` trzyma adres skrzynki, więc po wygaśnięciu jest już
// tylko daną osobową bez zastosowania (AGENTS.md §7 — minimalizacja).
// Termin ma każde żądanie własny, w kolumnie `expires_at`, więc komenda nie
// potrzebuje żadnego progu.
//
// TO NIE JEST BRAMKA BEZPIECZEŃSTWA — odnośnik przestaje działać co do minuty
// dzięki `PendingEmailChange::jestWazne()`, nie dzięki temu sprzątaniu.
// Dlatego dobowa częstotliwość wystarcza.
//
// 04:50, NIE 04:40 — o 04:40 startuje sprzątanie wiadomości z „Napisz do nas"
// (wyżej). W roli `all` harmonogram chodzi w JEDNYM procesie razem z serwerem
// (`Schedule::call()`, patrz uzasadnienie przy pierwszym zadaniu), więc dwa
// zadania o tej samej godzinie to dwa `DELETE` blokujące pętlę harmonogramu
// jeden po drugim, w tej samej minucie. Rozsuwamy je o dziesięć minut, tak jak
// rozsunięta jest cała reszta tej listy.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:sprzataj-zmiany-adresu')
    ->name('kuking:sprzataj-zmiany-adresu')
    ->dailyAt('04:50')
    ->withoutOverlapping();

// Retencja `potwierdzenia_zadan_rodo` (OCENA_RETENCJI_ZEWNETRZNA.md §C,
// PROJEKT_POTWIERDZENIA_RODO.md): `config('kuking.potwierdzenia_rodo.retention_months')`
// miesięcy od `zakonczono`, z pominięciem wierszy z obowiązującym
// `wstrzymanie_do`. Sprawy w toku nie są kandydatem w ogóle.
//
// 05:20, a nie 05:00 jak w pierwotnej wersji tej zmiany: o 05:00 stoi już
// sprzątanie wygasłych zaproszeń, a o 05:10 sprzątanie sesji. W roli `all`
// harmonogram chodzi w JEDNYM procesie razem z serwerem, więc dwa zadania
// o tej samej godzinie blokują pętlę jedno po drugim — cała ta lista jest
// świadomie rozsunięta co dziesięć minut i to zadanie też musi być.
//
// ScheduledArtisanCommand::artisan(), nie `Schedule::call()` — niezerowy kod
// wyjścia ma dojść do harmonogramu jako porażka (#835, uzasadnienie przy
// pierwszym zadaniu). Samo `Artisan::call()` zwraca liczbę, a CallbackEvent
// rozpoznaje jako porażkę wyłącznie `false`.
ScheduledArtisanCommand::artisan('kuking:sprzataj-potwierdzenia-rodo')
    ->name('kuking:sprzataj-potwierdzenia-rodo')
    ->dailyAt('05:20')
    ->withoutOverlapping();

// 05:00 — dziesięć minut po sprzątaniu zmian adresu, tak jak rozsunięta jest
// cała reszta tej listy (uzasadnienie odstępów wyżej).
// Wygasłe zaproszenia do założenia konta (D-085): w wierszu leży adres e-mail
// osoby, która NIE MA u nas konta. `WyslijZaproszenieDoRejestracji` sprząta przy
// okazji każdej prośby, więc to jest siatka bezpieczeństwa na dni bez ruchu —
// i to ona daje polityce prywatności prawo napisać „najwyżej dobę".
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:sprzataj-zaproszenia')
    ->name('kuking:sprzataj-zaproszenia')
    ->dailyAt('05:00')
    ->withoutOverlapping();

// 05:10 — dziesięć minut po zaproszeniach, tak jak rozsunięta jest cała reszta
// tej listy (uzasadnienie odstępów wyżej).
// Retencja tabeli `sessions` (RZ-01): `config('kuking.sessions.retention_days')`
// dni od ostatniej aktywności, nigdy mniej niż `SESSION_LIFETIME`.
//
// DLACZEGO TO ZADANIE JEST POTRZEBNE, SKORO LARAVEL SPRZĄTA SESJE SAM
// Bo „sam" znaczy `config/session.php` → `'lottery' => [2, 100]`, czyli
// `gc()` przy dwóch procentach żądań. To jest sprzątanie probabilistyczne
// i zależne od ruchu — przy małym ruchu wiersz z adresem IP i pełnym
// `User-Agent` leży dłużej niż `lifetime`, bez żadnej gwarantowanej górnej
// granicy. Każda inna tabela z danymi osobowymi ma tu swoje nocne zadanie
// i twardą liczbę; `sessions` była jedyną bez.
//
// LOTERIA ZOSTAJE WŁĄCZONA — to nie jest przeoczenie. Dwa mechanizmy mają
// różne tryby awarii: loteria czyści przy ruchu nawet po śmierci
// harmonogramu, zadanie czyści co noc nawet bez ruchu.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:sprzataj-sesje')
    ->name('kuking:sprzataj-sesje')
    ->dailyAt('05:10')
    ->withoutOverlapping();

// CZUJKA KOPII BAZY (issue #193, decyzja D-043).
//
// Kopię robi OSOBNY serwis Railway w obrazie bez PHP (`docker/kopia/`) — nie
// ta aplikacja i nie ten harmonogram. `pg_dump` z PHP wymaga `proc_open`,
// wyłączonego w `docker/php.ini`, i tego nie ruszamy.
//
// To zadanie robi drugą rzecz, której tamten serwis zrobić NIE MOŻE: pilnuje,
// czy on w ogóle jeszcze chodzi. Serwis kopii alarmuje, gdy jego przebieg się
// nie udał — ale nie zaalarmuje, gdy przebiegu NIE BYŁO (skasowany serwis,
// wyłączony harmonogram, wyczerpany limit konta, wygasły token). Kod, który
// wtedy nie chodzi, nie może o sobie donieść. #193 nazywa to najgorszym
// możliwym stanem: „myślisz, że masz kopię".
//
// 06:15 UTC, czyli 07:15/08:15 w Polsce: kopia startuje 02:17 UTC
// (`.railway/railway.ts`, serwis `kopia-bazy`), więc o tej godzinie wynik
// nocy jest już znany, a właściciel widzi alarm przy pierwszej kawie,
// nie w środku nocy.
//
// Dopóki bucket R2 nie istnieje, komenda mówi „czujka wyłączona" i nie dzwoni
// nigdzie — umowa „brak zmiennej = zero efektu", ta sama co przy
// `LOG_BLAD_WEBHOOK_URL`.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:sprawdz-kopie')
    ->name('kuking:sprawdz-kopie')
    ->dailyAt('06:15')
    ->withoutOverlapping();

// Budżet połączeń PostgreSQL (issue #598). Wyczerpanie `max_connections` jest
// awarią SKOKOWĄ: dopóki zostaje jedno wolne miejsce, `/health` odpowiada
// „baza działa" — bo właśnie to miejsce zajął. Po wyczerpaniu nie łączy się
// nikt, łącznie z administratorem. Dlatego pomiar jest OSOBNY od `/health`.
//
// CO GODZINĘ, a nie raz na dobę jak czujka kopii: brak kopii to stan, który
// trwa i poczeka do rana, a wyciek połączeń narasta w ciągu godzin i o świcie
// jest już po wszystkim. Powtórzeń pilnuje `AlarmPolaczen`
// (`kuking.polaczenia.cisza_godzin`), żeby stan trwający dobę nie dał
// dwudziestu czterech identycznych wiadomości.
//
// Minuta 25, a nie 00: o pełnej godzinie tyka już licznik społeczności
// i zdejmowanie kar. Pomiar liczby połączeń wykonany dokładnie wtedy, gdy
// harmonogram sam otwiera swoje, mierzyłby po części własny hałas.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:budzet-polaczen')
    ->name('kuking:budzet-polaczen')
    ->hourlyAt(25)
    ->withoutOverlapping();

// Czujka kolejki (issue #599). Pole `kolejka` w `/health` liczy WSZYSTKIE
// wiersze `failed_jobs`, więc od 9 września 2026 świeci nieprzerwanie przez
// cztery stare zadania — i nie odróżni piątej awarii od czwartej. Ta czujka
// pyta o ZDARZENIE (co padło w oknie kilku godzin) i mierzy zaległość
// najstarszego gotowego zadania, czyli jedyny sygnał, który zauważa MARTWEGO
// workera. Proces, który nie chodzi, nie zgłasza żadnego błędu.
//
// CO KWADRANS: martwy worker to zatrzymane potwierdzenia adresu, resety hasła
// i przetwarzanie zdjęć. Godzina ciszy przy rejestracji nowej osoby jest
// różnicą między „wolno" a „nie działa". Powtórzeń pilnuje `AlarmKolejki`.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:sprawdz-kolejke')
    ->name('kuking:sprawdz-kolejke')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Licznik społeczności w stopce (issue #38): „{n} kuKINGów". Co godzinę,
// nie na żądanie — stopka jest na KAŻDEJ stronie serwisu, a COUNT(*) na
// każdą odsłonę jest dokładnie tym, czego ta komenda ma nie dopuścić.
// Uzasadnienie pełne w `App\Domain\Analytics\LiczbaKukingow`.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:policz-kukingow')
    ->name('kuking:policz-kukingow')
    ->hourly()
    ->withoutOverlapping();

// Liczniki przy pozycjach panelu moderacji („Odwołania 2"). Ten sam powód co
// wyżej — menu boczne stoi na KAŻDEJ stronie panelu, więc pięć `COUNT(*)` na
// odsłonę jest wykluczone (`App\Domain\Moderation\KolejkiPanelu`).
//
// CO PIĘĆ MINUT, a nie co godzinę jak licznik społeczności: tamten pokazuje
// rozmiar społeczności, który zmienia się wolno, ten mówi „to czeka na
// Ciebie". Bieżącej świeżości pilnują zdarzenia modeli (`AppServiceProvider`);
// to zadanie jest siatką bezpieczeństwa na świeże wdrożenie z pustym cache
// i na kolejkę „Bez odpowiedzi", która haka przy zapisie świadomie nie ma.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:policz-kolejki')
    ->name('kuking:policz-kolejki')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Codzienne podsumowanie kolejki automatu (D-055). JEDEN list zamiast stu:
// przy setkach kont list na każde oznaczenie zamieniłby skrzynkę moderatora
// w śmietnik, a skończyłoby się tym, że przestałby je otwierać — czyli alarm
// przestałby działać dokładnie wtedy, gdy jest potrzebny. Sprawy, które nie
// mogą czekać (treści seksualne, cokolwiek dotyczącego dzieci), idą osobno
// i natychmiast, prosto z zadania analizującego.
//
// 07:00, nie w nocy: to jest list do przeczytania przy porannej kawie, a nie
// alarm. Poza tym trzyma się z dala od pasma 03:20–04:50, w którym chodzi
// całe sprzątanie — w roli `all` harmonogram jest jednym procesem.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:podsumowanie-automatu')
    ->name('kuking:podsumowanie-automatu')
    ->dailyAt('07:00')
    ->withoutOverlapping();

// Pilnowanie terminu odpowiedzi na odwołanie (DSA art. 20, D-060).
//
// 07:10, dziesięć minut po podsumowaniu kolejki automatu: te dwa listy mówią
// o dwóch różnych rzeczach i mają nie wyjść w tej samej minucie, bo
// w harmonogramie chodzącym w jednym procesie (rola `all`) blokowałyby
// pętlę jeden po drugim — tak samo rozsunięte jest całe pasmo sprzątania.
//
// Ten list wychodzi WYŁĄCZNIE wtedy, gdy termin jest blisko albo minął.
// O nowych odwołaniach mówi powiadomienie w panelu i licznik przy pozycji
// „Odwołania" — pełne uzasadnienie w `PowiadomOOdwolaniu` i D-060.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:pilnuj-terminow-odwolan')
    ->name('kuking:pilnuj-terminow-odwolan')
    ->dailyAt('07:10')
    ->withoutOverlapping();

// Tygodniowe podsumowanie od gospodarza (issue #11, docs/DECISIONS.md D-057).
//
// CODZIENNIE, CHOĆ LIST JEST TYGODNIOWY — i to nie jest sprzeczność.
// Konto pocztowe ma twardy limit 300 wiadomości na dobę (EmailLabs STARTUP,
// docs/decyzje/POCZTA.md §1), dzielony z całą pocztą transakcyjną i z
// logowaniem linkiem. Na podsumowania zostaje z tego 60 listów dziennie
// (rachunek: config/kuking.php, sekcja `poczta`), więc wysyłka „wszyscy
// naraz w piątek" kończy się przy sześćdziesięciu kontach. Zadanie
// chodzi codziennie i codziennie bierze najwyżej `dzienny_limit` osób —
// „kto czeka najdłużej, ten pierwszy" — a odstęp siedmiu dni po stronie
// KONTA pilnuje obietnicy „jeden e-mail tygodniowo, nigdy więcej"
// (App\Domain\Digest\OdbiorcyDigestu).
//
// 08:30, I TO JEST GODZINA WYBRANA POD TĘ GRUPĘ, NIE POD SERWER.
//  * Po 8:00, czyli po ciszy nocnej z docs/product/RETENTION_LOOPS.md §3.2.
//    List, który przychodzi w nocy, jest rano jednym z wielu i nikt go nie
//    otwiera — a przy telefonie leżącym na szafce nocnej bywa też budzikiem.
//  * Rano, nie o 17:00 jak proponował szkic w RETENTION_LOOPS §4. Tamta
//    godzina jest dobra dla kogoś, kto wychodzi z biura i planuje weekend.
//    Nasza grupa czyta pocztę przy porannej kawie, a o 17:00 jest w kuchni
//    — czyli robi dokładnie to, o czym ten list opowiada, i nie patrzy
//    wtedy w telefon.
//  * Nie równo o pełnej godzinie: o 08:00 tyka `kuking:zdejmij-wygasle-kary`
//    (`hourly()` = minuta 00 KAŻDEJ godziny). Cała ta lista jest świadomie
//    porozsuwana — patrz komentarz przy sprzątaniu zmian adresu.
//  * Daleko od nocnego bloku sprzątania (03:20-04:50), który potrafi trzymać
//    pętlę harmonogramu przez dłuższą chwilę.
//
// `withoutOverlapping()` zostaje, ale NIE JEST OCHRONĄ PRZED DUPLIKATEM
// i nie wolno go tak czytać (audyt QUEUE-01, D-077). Zapobiega dwóm
// przebiegom JEDNOCZEŚNIE — a wysyłkę dwa razy tego samego listu powodował
// przebieg KOLEJNY, uruchomiony po tym, jak poprzedni padł w połowie.
// Przed tym broni bariera w bazie: `UNIQUE (user_id, week_start)`
// w `weekly_digest_sends`, zajmowana PRZED każdym `Mail::queue()`
// (`App\Domain\Digest\OdbiorcyDigestu::zarezerwuj()`). Blokada
// harmonogramu oszczędza tu więc pracę i zapytania, nie listy.
//
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
ScheduledArtisanCommand::artisan('kuking:wyslij-podsumowania')
    ->name('kuking:wyslij-podsumowania')
    ->dailyAt('08:30')
    ->withoutOverlapping();
