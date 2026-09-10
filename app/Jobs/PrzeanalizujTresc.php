<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Moderation\Actions\AlarmujModeratora;
use App\Domain\Moderation\Actions\OznaczDoPrzegladu;
use App\Domain\Moderation\Sygnaly\WykrywaczSygnalow;
use App\Models\Comment;
use App\Models\Post;
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
     */
    public function handle(
        WykrywaczSygnalow $wykrywacz,
        OcenaModelem $model,
        OznaczDoPrzegladu $oznacz,
        AlarmujModeratora $alarm,
    ): void {
        if (! config('kuking.moderation.sygnaly.wlaczone')) {
            return;
        }

        try {
            $tresc = $this->tresc();

            if ($tresc === null) {
                return;
            }

            $sygnaly = array_merge($wykrywacz->dla($tresc), $model->dla($tresc));

            $oznaczenie = $oznacz->handle($tresc, $sygnaly);

            if ($oznaczenie !== null) {
                $alarm->handle($oznaczenie, $sygnaly);
            }
        } catch (Throwable $blad) {
            // Bez treści analizowanego wpisu w logu — to jest cudzy tekst,
            // a log błędów nie jest miejscem na treści użytkowników
            // (AGENTS.md §7).
            Log::warning('Analiza treści pod kątem sygnałów nie powiodła się.', [
                'typ' => $this->typ,
                'id' => $this->id,
                'blad' => $blad->getMessage(),
            ]);
        }
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
