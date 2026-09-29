<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Moderation\Actions\AlarmujModeratora;
use App\Domain\Moderation\Actions\DolozDoOznaczenia;
use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Domain\Moderation\Sygnaly\WykrywaczSygnalow;
use App\Models\Comment;
use App\Models\Post;
use App\Moderacja\BudzetCzasu;
use App\Moderacja\ExceptionContext;
use App\Moderacja\GranicaWysylki;
use App\Moderacja\KlientOpenAI;
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

    /**
     * Czas zadania, którego ocena modelem NIE dostaje (#829): sygnały
     * lokalne, zapisy w bazie i przygotowanie ostatniego zdjęcia. Reszta
     * `$timeout` to wspólny budżet wszystkich żądań do modelu — przy
     * dowolnym `zdjec_na_wpis` i `limit_czasu` zadanie kończy się samo,
     * zanim worker je ubije.
     */
    public const ZAPAS_SEKUND = 8;

    /** Kolejka `low`: w osobnym kontenerze workera ma własny proces (`listy_kolejek()` w `docker/entrypoint.sh`, #1030). */
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
     * ZDJĘCIE GOTOWE PO PUBLIKACJI (#830).
     *
     * Wpis wolno opublikować ze zdjęciem, które jeszcze się przetwarza
     * (`ZdjeciaDoPrzypiecia`), a `OcenaModelem` ocenia tylko `ready` —
     * analiza, która ruszyła przed wariantami, pomijała zdjęcie i nikt do
     * niego nie wracał. Woła to `ProcessUploadedImage` raz, w chwili
     * przejścia do `ready`; spóźniona kopia tamtego zadania nic nie
     * przejmuje, więc drugi raz tu nie trafia.
     *
     * OGRANICZONE: tylko opublikowane wpisy publiczne lub dla obserwujących,
     * i tylko gdy to zdjęcie jest wśród `zdjec_na_wpis` pierwszych (limit
     * D-055 — innych model i tak nie ogląda). Zdjęcie gotowe przed
     * publikacją nie jest jeszcze do niczego przypięte, więc nie zleca nic.
     *
     * IDEMPOTENTNE: ponowna analiza stawia to samo jedno oznaczenie,
     * a powody już obecne nie są dopisywane drugi raz (`DolozDoOznaczenia::handle`).
     * Granica prywatności jest sprawdzana od nowa w chwili wykonania —
     * odpięte zdjęcie albo wpis przełączony na prywatny nie wychodzi.
     *
     * @return int ile analiz zlecono
     */
    public static function poPrzygotowaniuZdjecia(string $mediaId): int
    {
        $ile = (int) config('kuking.moderation.model.zdjec_na_wpis');

        if ($ile <= 0 || ! config('kuking.moderation.model.ocenia_zdjecia') || ! KlientOpenAI::oceniamy()) {
            return 0;
        }

        $wpisy = Post::query()
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS])
            ->whereHas('media', fn ($q) => $q->whereKey($mediaId))
            ->limit(10)
            ->get();

        $zlecone = 0;

        foreach ($wpisy as $wpis) {
            $oceniane = $wpis->media()->limit($ile)->pluck('media.id')->map(fn ($id): string => (string) $id);

            if ($oceniane->contains($mediaId)) {
                self::dlaWpisu($wpis);
                $zlecone++;
            }
        }

        return $zlecone;
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
     * OD #829 ZAPISUJEMY JE W DWÓCH KROKACH tego samego zadania: najpierw
     * lokalne, potem model — dołożony do TEGO SAMEGO oznaczenia
     * (`DolozDoOznaczenia::handle`), więc nadal nic nie przepada na
     * deduplikacji, a wolny model nie zabiera lokalnych sygnałów ze sobą.
     *
     * PRZY CHWILOWEJ AWARII MODELU ZADANIE WRACA DO KOLEJKI (#1662). Sygnały
     * lokalne są już wtedy zapisane (#829), a ocena modelu z następnej próby
     * DOKŁADA SIĘ do tej samej sprawy (`DolozDoOznaczenia`) — jedno
     * oznaczenie na treść nie zamyka jej drogi, więc lokalne sygnały nie
     * muszą czekać na model. Następna próba liczy wszystko od nowa: znowu
     * pyta o treść, o jej widoczność i o `GranicaWysylki`, bo w tym czasie
     * wpis mógł zniknąć albo stać się prywatny; sygnały już zapisane nie
     * wracają drugi raz ani do sprawy, ani do alarmu.
     *
     * Jeśli ostatnia próba też trafi na awarię, zadanie oznaczamy jako
     * nieudane (`failed_jobs`) — to jest ślad operacyjny, że model tej treści
     * nie ocenił — a istniejąca sprawa dostaje uwagę „Ocena modelem
     * NIEPEŁNA” (#829). Jeśli model przy ponowieniu wskaże kategorię pilną,
     * istniejąca sprawa dostaje alarm — jeden, bo `AlarmujModeratora` nie
     * wysyła drugiego (#1051).
     */
    public function handle(
        WykrywaczSygnalow $wykrywacz,
        OcenaModelem $model,
        DolozDoOznaczenia $oznacz,
        AlarmujModeratora $alarm,
        GranicaWysylki $granica,
    ): void {
        if (! config('kuking.moderation.sygnaly.wlaczone')) {
            return;
        }

        // Liczony od startu zadania, więc obejmuje też sygnały lokalne (#829).
        $budzet = BudzetCzasu::naSekund($this->timeout - self::ZAPAS_SEKUND);

        try {
            $tresc = $this->tresc();

            // #827: komentarz pod rodzicem, który przestał być widoczny, nie
            // stawia oznaczenia. Do OpenAI wychodzi jeszcze mniej — tylko
            // treść publiczna; tego pilnuje `OcenaModelem` (D-240).
            if ($tresc === null || ! $granica->pozaAutorem($tresc)) {
                return;
            }

            // #829: SYGNAŁY LOKALNE ZAPISANE PRZED MODELEM. Wolny dostawca
            // albo worker ubity w trakcie oceny nie może zabrać ze sobą
            // wyniku, który mamy od razu i bez wychodzenia z serwera.
            $this->zapisz($oznacz, $alarm, $tresc, $wykrywacz->dla($tresc));

            $awariaModelu = null;

            try {
                $sygnalyModelu = $model->dla($tresc, $budzet);
            } catch (ModelChwilowoNiedostepny $awaria) {
                // Sygnały z ocen, które w tej próbie się udały, nie przepadają
                // (#829): zapisujemy je niżej, a następna próba dokłada resztę
                // do tej samej sprawy. Nieudane żądania `OcenaModelem` już
                // policzyła w budżecie jako ocenę niepełną.
                $awariaModelu = $awaria;
                $sygnalyModelu = $awaria->czesciowe;
            }

            // Ocena modelem trwa sekundy. Treść, która w tym czasie stała się
            // prywatna, nie trafia też przed moderatora (D-240).
            if (! $granica->pozaAutorem($tresc)) {
                return;
            }

            $this->zapisz($oznacz, $alarm, $tresc, $sygnalyModelu);

            // #1662: chwilowa awaria wraca do kolejki. Uwagi „NIEPEŁNA” przy
            // tym nie stawiamy — następna próba może ocenić całość.
            if ($awariaModelu !== null && $this->attempts() < self::PROBY && $this->wroci()) {
                $this->release($this->opoznienie($awariaModelu));

                return;
            }

            if ($budzet->niepelne() > 0) {
                $this->ocenaNiepelna($oznacz, $tresc, $budzet);
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
     * Jedno oznaczenie na treść, dopisywane (D-052, #829). List idzie tylko
     * o sygnałach, które naprawdę trafiły do sprawy — ponowna analiza nie
     * wysyła drugi raz alarmu o tym samym.
     *
     * @param  list<Sygnal>  $sygnaly
     */
    private function zapisz(DolozDoOznaczenia $oznacz, AlarmujModeratora $alarm, Post|Comment $tresc, array $sygnaly): void
    {
        $wynik = $oznacz->handle($tresc, $sygnaly);

        if ($wynik !== null) {
            $alarm->handle($wynik[0], $wynik[1]);
        }
    }

    /**
     * Brak sygnału modelu po przekroczeniu budżetu albo po nieudanym żądaniu
     * to „nie wiemy", nie „czysto" (#829). Ślad w dzienniku zawsze; uwaga
     * dla moderatora tylko przy istniejącym oznaczeniu — sama nie zakłada
     * sprawy — i najwyżej raz na sprawę.
     */
    private function ocenaNiepelna(DolozDoOznaczenia $oznacz, Post|Comment $tresc, BudzetCzasu $budzet): void
    {
        Log::warning('Ocena modelem niepełna: część ocen nie odbyła się albo nie wróciła z wynikiem w czasie zadania.', [
            'typ' => $this->typ,
            'id' => $this->id,
            'niepelne' => $budzet->niepelne(),
            'pominiete' => $budzet->pominiete(),
            'nieudane' => $budzet->nieudane(),
            'stage' => 'model_budzet',
        ]);

        $oznacz->uwaga(
            $tresc,
            "Ocena modelem NIEPEŁNA: {$budzet->niepelne()} z ocen (tekst lub zdjęcia) nie odbyło się albo nie dostało odpowiedzi w czasie. Brak sygnału modelu nie znaczy, że ta część jest w porządku — obejrzyj całość.",
            znacznik: 'Ocena modelem NIEPEŁNA:',
        );
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
