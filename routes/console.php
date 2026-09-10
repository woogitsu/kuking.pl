<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
// `proc_open` nie jest potrzebny. `Artisan::call()` uruchamia tę samą komendę
// co wcześniej i zwraca jej kod wyjścia.
//
// Kosztem jest utrata `runInBackground()`: sprzątanie eksportów blokuje pętlę
// harmonogramu na czas swojego działania. Przy jednym uruchomieniu na dobę
// i `withoutOverlapping()` to jest do przyjęcia — a alternatywą byłoby
// osłabienie zabezpieczenia, którego nie wolno ruszać.
// Zdjęcia wgrane, ale do niczego nieprzypięte (audyt C1). Powstają, gdy ktoś
// wybierze zdjęcia, dostanie błąd walidacji i zamknie kartę zamiast poprawić.
// `Schedule::call()`, nie `command()` — uzasadnienie przy zadaniu niżej.
Schedule::call(fn () => Artisan::call('kuking:sprzataj-osierocone-zdjecia'))
    ->dailyAt('03:40')
    ->name('sprzataj-osierocone-zdjecia')
    ->withoutOverlapping();

Schedule::call(fn () => Artisan::call('kuking:sprzataj-eksporty'))
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
Schedule::call(fn () => Artisan::call('kuking:zdejmij-wygasle-kary'))
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
Schedule::call(fn () => Artisan::call('kuking:usun-wygasle-konta'))
    ->name('kuking:usun-wygasle-konta')
    ->dailyAt('03:50')
    ->withoutOverlapping();

// Retencja sygnałów produktowych (issue #115): `product_signals` starsze niż
// `config('kuking.analytics.signal_retention_days')` (domyślnie 90 dni) nie
// mają już żadnej wartości analitycznej, a minimalizacja danych (AGENTS.md
// §7) jest zasadą domyślną. Codziennie w nocy, nie co godzinę — to nie jest
// termin z konkretną godziną jak zawieszenie, dobowa dokładność wystarcza.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
Schedule::call(fn () => Artisan::call('kuking:sprzataj-sygnaly'))
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
Schedule::call(fn () => Artisan::call('kuking:sprzataj-audyt'))
    ->name('kuking:sprzataj-audyt')
    ->dailyAt('04:10')
    ->withoutOverlapping();

// Retencja `notifications` (issue #19, ADR_RETENCJE.md §5.2):
// `config('kuking.notifications.retention_months')` miesięcy od
// `created_at`, niezależnie od `read_at`.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
Schedule::call(fn () => Artisan::call('kuking:sprzataj-powiadomienia'))
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
Schedule::call(fn () => Artisan::call('kuking:sprzataj-sprawy-moderacyjne'))
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
Schedule::call(fn () => Artisan::call('kuking:sprzataj-wiadomosci'))
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
Schedule::call(fn () => Artisan::call('kuking:sprzataj-zmiany-adresu'))
    ->name('kuking:sprzataj-zmiany-adresu')
    ->dailyAt('04:50')
    ->withoutOverlapping();

// Licznik społeczności w stopce (issue #38): „{n} kuKINGów". Co godzinę,
// nie na żądanie — stopka jest na KAŻDEJ stronie serwisu, a COUNT(*) na
// każdą odsłonę jest dokładnie tym, czego ta komenda ma nie dopuścić.
// Uzasadnienie pełne w `App\Domain\Analytics\LiczbaKukingow`.
// `Schedule::call()`, nie `command()` — uzasadnienie przy pierwszym zadaniu.
Schedule::call(fn () => Artisan::call('kuking:policz-kukingow'))
    ->name('kuking:policz-kukingow')
    ->hourly()
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
Schedule::call(fn () => Artisan::call('kuking:podsumowanie-automatu'))
    ->name('kuking:podsumowanie-automatu')
    ->dailyAt('07:00')
    ->withoutOverlapping();
