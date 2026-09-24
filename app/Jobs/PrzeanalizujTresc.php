<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Moderation\Actions\AlarmujModeratora;
use App\Domain\Moderation\Actions\OznaczDoPrzegladu;
use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Domain\Moderation\Sygnaly\WykrywaczSygnalow;
use App\Models\Comment;
use App\Models\Post;
use App\Moderacja\BudzetCzasu;
use App\Moderacja\ExceptionContext;
use App\Moderacja\GranicaWysylki;
use App\Moderacja\KlientOpenAI;
use App\Moderacja\OcenaModelem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;
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
 * ZAWODZI CICHO I W DOBRĄ STRONĘ
 * Wyjątek w analizie NIE MOŻE mieć żadnego skutku dla autora — jego wpis
 * został opublikowany dawno temu, w innym żądaniu. Dlatego `handle()` łapie
 * wszystko, zapisuje w logu i kończy się powodzeniem: ponawianie analizy
 * treści, która się nie zmieniła, dałoby ten sam błąd trzy razy i trzy wpisy
 * w logu zamiast jednego. Nierozpoznany spam jest kosztem, który da się
 * odrobić zgłoszeniem od człowieka; zablokowana kolejka nie jest.
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
     * JEDNA PRÓBA, ŚWIADOMIE.
     *
     * Analiza jest czysto obliczeniowa i deterministyczna: jeśli padła raz,
     * padnie tak samo drugi i trzeci raz. Powtarzanie kosztuje trzy razy
     * więcej pracy workera dokładnie wtedy, gdy coś jest zepsute.
     */
    public int $tries = 1;

    public int $timeout = 30;

    /**
     * Czas zadania, którego ocena modelem NIE dostaje (#829): sygnały
     * lokalne, zapisy w bazie i przygotowanie ostatniego zdjęcia. Reszta
     * `$timeout` to wspólny budżet wszystkich żądań do modelu — przy
     * dowolnym `zdjec_na_wpis` i `limit_czasu` zadanie kończy się samo,
     * zanim worker je ubije.
     */
    public const ZAPAS_SEKUND = 8;

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
     * a powody już obecne nie są dopisywane drugi raz (`OznaczDoPrzegladu::dolacz`).
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
     * (`OznaczDoPrzegladu::dolacz`), więc nadal nic nie przepada na
     * deduplikacji, a wolny model nie zabiera lokalnych sygnałów ze sobą.
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

            $sygnalyModelu = $model->dla($tresc, $budzet);

            // Ocena modelem trwa sekundy. Treść, która w tym czasie stała się
            // prywatna, nie trafia też przed moderatora (D-240).
            if (! $granica->pozaAutorem($tresc)) {
                return;
            }

            $this->zapisz($oznacz, $alarm, $tresc, $sygnalyModelu);

            if ($budzet->pominiete() > 0) {
                $this->ocenaNiepelna($oznacz, $tresc, $budzet->pominiete());
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
    private function zapisz(OznaczDoPrzegladu $oznacz, AlarmujModeratora $alarm, Post|Comment $tresc, array $sygnaly): void
    {
        $wynik = $oznacz->dolacz($tresc, $sygnaly);

        if ($wynik !== null) {
            $alarm->handle($wynik[0], $wynik[1]);
        }
    }

    /**
     * Brak sygnału modelu po przekroczeniu budżetu to „nie wiemy", nie
     * „czysto" (#829). Ślad w dzienniku zawsze; uwaga dla moderatora tylko
     * przy istniejącym oznaczeniu — sama nie zakłada sprawy.
     */
    private function ocenaNiepelna(OznaczDoPrzegladu $oznacz, Post|Comment $tresc, int $pominiete): void
    {
        Log::warning('Ocena modelem niepełna: zabrakło czasu zadania na część ocen.', [
            'typ' => $this->typ,
            'id' => $this->id,
            'pominiete' => $pominiete,
            'stage' => 'model_budzet',
        ]);

        $oznacz->dopiszUwage($tresc, "Ocena modelem NIEPEŁNA: zabrakło czasu na {$pominiete} z ocen (tekst lub zdjęcia). Brak sygnału modelu nie znaczy, że ta część jest w porządku — obejrzyj całość.");
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
