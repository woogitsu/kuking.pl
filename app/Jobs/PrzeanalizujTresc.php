<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Moderation\Actions\AlarmujModeratora;
use App\Domain\Moderation\Actions\OznaczDoPrzegladu;
use App\Domain\Moderation\Sygnaly\WykrywaczSygnalow;
use App\Models\Comment;
use App\Models\Post;
use App\Moderacja\ExceptionContext;
use App\Moderacja\GranicaWysylki;
use App\Moderacja\ModelChwilowoNiedostepny;
use App\Moderacja\OcenaModelem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ANALIZA JEDNEJ TREŚCI POD KĄTEM SYGNAŁÓW SPAMU (D-052).
 *
 * DLACZEGO W KOLEJCE, A NIE W KONTROLERZE
 * Bo publikacja wpisu jest najważniejszą operacją w tym produkcie i musi się
 * udawać w mniej niż minutę (`PublishPost`). Sygnał „powtórzona treść" robi
 * dwa zapytania i porównanie tekstów; przy fali migracyjnej — gdy jedna osoba
 * wrzuca archiwum, a kilkadziesiąt osób robi to naraz — doliczenie tego do
 * żądania HTTP znaczyłoby, że im więcej ludzi publikuje, tym wolniej im się
 * publikuje. Kolejka `low` odsuwa to za wszystko, co robi człowiek.
 *
 * ZAWODZI W DOBRĄ STRONĘ
 * Wyjątek w analizie NIE MOŻE mieć żadnego skutku dla autora — jego wpis
 * został opublikowany dawno temu, w innym żądaniu. Dlatego `handle()` łapie
 * wszystko, zapisuje w logu i kończy się powodzeniem: ponawianie analizy
 * treści, która się nie zmieniła, dałoby ten sam błąd trzy razy i trzy wpisy
 * w logu zamiast jednego. Nierozpoznany spam jest kosztem, który da się
 * odrobić zgłoszeniem od człowieka; zablokowana kolejka nie jest.
 *
 * JEDEN WYJĄTEK OD TEJ REGUŁY: CHWILOWA AWARIA MODELU (#1662)
 * Timeout, 429 i 5xx u dostawcy NIE są „tym samym błędem za każdym razem"
 * — mijają. Do września 2026 kończyły zadanie jak pełny sukces i treść na
 * zawsze zostawała bez oceny modelem. Teraz zadanie wraca do kolejki
 * (`release()`) z rosnącym opóźnieniem i losowym rozrzutem, najwyżej
 * `PROBY` razy. Szczegóły przy `handle()`.
 *
 * CO SIĘ DZIEJE PRZY WYŁĄCZONYM AUTOMACIE
 * `KUKING_SYGNALY_AUTOMATU=false` zatrzymuje zadanie na pierwszej linijce.
 * Zadania i tak są wysyłane — wyłącznik ma być JEDNĄ zmianą w jednym miejscu,
 * a nie zmianą rozsianą po wszystkich akcjach domenowych, które publikują
 * treść.
 */
class PrzeanalizujTresc implements ShouldQueue
{
    use Queueable;

    /**
     * Ile razy najwyżej pytamy model o tę samą treść (#1662).
     *
     * Ponawia się WYŁĄCZNIE chwilowa awaria modelu (`ModelChwilowoNiedostepny`).
     * Błąd lokalnej analizy dalej kończy zadanie za pierwszym razem: ta część
     * jest obliczeniowa i deterministyczna, więc padłaby tak samo.
     *
     * BUDŻET CZASU (#829): każda próba ma własne `$timeout` i NIE doklejamy
     * ponowień do jednego uruchomienia — klient HTTP nie ma `retry()`. Między
     * próbami zadanie leży w kolejce i nie zajmuje workera.
     */
    public const PROBY = 3;

    /** Opóźnienia przed 2. i 3. próbą, w sekundach, przed rozrzutem. */
    private const OPOZNIENIA = [30, 120];

    /** `Retry-After` dłuższy niż to nie odsuwa oceny w nieskończoność. */
    private const NAJDLUZSZE_OPOZNIENIE = 600;

    public int $tries = self::PROBY;

    public int $timeout = 30;

    /** Za wszystkim, co robi człowiek — `docker/entrypoint.sh` uruchamia `--queue=high,default,media,low`. */
    private const KOLEJKA = 'low';

    public const TYP_WPIS = 'post';

    public const TYP_KOMENTARZ = 'comment';

    public function __construct(public string $typ, public string $id)
    {
        $this->onQueue(self::KOLEJKA);
    }

    /**
     * Zlecenie analizy wpisu.
     *
     * Nazwane wejście zamiast gołego `dispatch($typ, $id)`: dwa napisy obok
     * siebie łatwo podać w odwrotnej kolejności, a taki błąd nie rzuca
     * wyjątku — po prostu nic nie znajduje i analiza cicho nie robi nic.
     */
    public static function dlaWpisu(Post $post): PendingDispatch
    {
        return self::dispatch(self::TYP_WPIS, (string) $post->getKey());
    }

    public static function dlaKomentarza(Comment $comment): PendingDispatch
    {
        return self::dispatch(self::TYP_KOMENTARZ, (string) $comment->getKey());
    }

    /**
     * DWA ŹRÓDŁA SYGNAŁÓW, JEDNA POZYCJA W KOLEJCE.
     *
     * Lokalne wzorce (`WykrywaczSygnalow`, D-052) szukają SPAMU; model
     * (`OcenaModelem`, D-055) ocenia nienawiść, przemoc, treści seksualne
     * i samookaleczenie — i robi to także na ZDJĘCIACH. To są rozłączne
     * klasy treści i żadne z nich nie zastępuje drugiego.
     *
     * SKLEJAMY JE W JEDNYM ZADANIU, a nie w dwóch. Dwa zadania próbowałyby
     * postawić dwa oznaczenia tej samej treści, a indeks
     * `reports_jeden_automat_na_tresc` przepuściłby tylko pierwsze — czyli
     * to, które akurat wygrało wyścig. Ocena modelu potrafiłaby wtedy
     * przepaść dlatego, że wpis zawierał numer telefonu.
     *
     * Z TEGO SAMEGO POWODU PRZY CHWILOWEJ AWARII MODELU NIE ZAPISUJEMY NIC
     * (#1662). Oznaczenie postawione z samych lokalnych sygnałów zamknęłoby
     * drogę ocenie modelu z następnej próby (jedno oznaczenie na treść).
     * Zadanie wraca więc do kolejki i przy następnej próbie liczy wszystko od
     * nowa: znowu pyta o treść, o jej widoczność i o `GranicaWysylki`, bo
     * w tym czasie wpis mógł zniknąć albo stać się prywatny.
     *
     * Lokalne sygnały nie giną: jeśli ostatnia próba też trafi na awarię,
     * zapisujemy je bez oceny modelu, a zadanie oznaczamy jako nieudane
     * (`failed_jobs`) — to jest ślad operacyjny, że model tej treści nie
     * ocenił. Drugiego oznaczenia ani drugiego alarmu nie będzie, nawet po
     * `queue:retry`: pilnuje tego `OznaczDoPrzegladu`.
     */
    public function handle(
        WykrywaczSygnalow $wykrywacz,
        OcenaModelem $model,
        OznaczDoPrzegladu $oznacz,
        AlarmujModeratora $alarm,
        GranicaWysylki $granica,
    ): void {
        if (! config('kuking.moderation.sygnaly.wlaczone')) {
            return;
        }

        try {
            $tresc = $this->tresc();

            // #827: komentarz pod rodzicem, który przestał być widoczny, nie
            // stawia oznaczenia. Do OpenAI wychodzi jeszcze mniej — tylko
            // treść publiczna; tego pilnuje `OcenaModelem` (D-240).
            if ($tresc === null || ! $granica->pozaAutorem($tresc)) {
                return;
            }

            $lokalne = $wykrywacz->dla($tresc);
            $awariaModelu = null;

            try {
                $modelowe = $model->dla($tresc);
            } catch (ModelChwilowoNiedostepny $awaria) {
                if ($this->attempts() < self::PROBY && $this->wroci()) {
                    $this->release($this->opoznienie($awaria));

                    return;
                }

                $awariaModelu = $awaria;
                $modelowe = [];
            }

            $sygnaly = array_merge($lokalne, $modelowe);

            // Ocena modelem trwa sekundy. Treść, która w tym czasie stała się
            // prywatna, nie trafia też przed moderatora (D-240).
            if (! $granica->pozaAutorem($tresc)) {
                return;
            }

            $oznaczenie = $oznacz->handle($tresc, $sygnaly);

            if ($oznaczenie !== null) {
                $alarm->handle($oznaczenie, $sygnaly);
            }

            if ($awariaModelu !== null) {
                Log::warning('Ocena modelem nie doszła do skutku mimo ponowień. Treść NIE została sprawdzona przez model.', [
                    'typ' => $this->typ,
                    'id' => $this->id,
                    'proby' => $this->attempts(),
                    'stage' => 'openai_retries_exhausted',
                ]);

                $this->fail($awariaModelu);
            }
        } catch (Throwable $blad) {
            // Bez treści analizowanego wpisu w logu — to jest cudzy tekst,
            // a log błędów nie jest miejscem na treści użytkowników
            // (AGENTS.md §7).
            Log::warning('Analiza treści pod kątem sygnałów nie powiodła się.', [
                'typ' => $this->typ,
                'id' => $this->id,
                ...ExceptionContext::forStage($blad, 'content_analysis'),
            ]);
        }
    }

    /**
     * Czy wydane zadanie naprawdę wróci. Na kolejce `sync` (lokalnie, testy)
     * `release()` niczego nie planuje — każda próba jest tam ostatnia, bo
     * inaczej lokalne sygnały czekałyby na próbę, która nie nadejdzie.
     */
    private function wroci(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob;
    }

    /**
     * Opóźnienie przed kolejną próbą: rosnące, z rozrzutem do 20%, żeby fala
     * zadań po jednej awarii nie wróciła do dostawcy w tej samej sekundzie.
     * `Retry-After` wydłuża je, nigdy nie skraca.
     */
    private function opoznienie(ModelChwilowoNiedostepny $awaria): int
    {
        $podstawa = self::OPOZNIENIA[min($this->attempts(), count(self::OPOZNIENIA)) - 1];
        $podstawa = max($podstawa, $awaria->ponowZaSekund ?? 0);

        return min(self::NAJDLUZSZE_OPOZNIENIE, $podstawa + random_int(0, intdiv($podstawa, 5)));
    }

    /**
     * Treść do analizy — albo `null`, gdy nie ma czego oglądać.
     *
     * TRZY POWODY, DLA KTÓRYCH `null` JEST NORMALNYM WYNIKIEM:
     *
     *  1. treści już nie ma (autor skasował ją w sekundę po opublikowaniu —
     *     zadanie z kolejki przychodzi zawsze później niż żądanie);
     *  2. wpis nie jest opublikowany (szkic, treść ukryta wcześniejszą
     *     decyzją moderatora) — nie ma po co stawiać pozycji w kolejce
     *     przy czymś, czego i tak nikt nie widzi;
     *  3. wpis jest PRYWATNY. To jest granica prywatności, nie optymalizacja:
     *     oznaczenie prywatnego wpisu położyłoby przed moderatorem tekst,
     *     którego autor świadomie nie pokazał nikomu. Spam kierowany do
     *     nikogo nie jest problemem, który warto rozwiązywać tym kosztem.
     *
     * To zapytanie jest tylko wstępnym sitem. Resztę rozstrzyga
     * `GranicaWysylki` — także dla komentarza, którego rodzic mógł
     * w międzyczasie przestać być widoczny (#827, D-240).
     */
    private function tresc(): Post|Comment|null
    {
        if ($this->typ === self::TYP_KOMENTARZ) {
            return Comment::query()
                ->where('status', Comment::STATUS_PUBLISHED)
                ->find($this->id);
        }

        return Post::query()
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS])
            ->find($this->id);
    }
}
