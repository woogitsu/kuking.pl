<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Moderation\Actions\AlarmujModeratora;
use App\Domain\Moderation\Actions\OznaczDoPrzegladu;
use App\Models\Media;
use App\Models\Profile;
use App\Moderacja\ExceptionContext;
use App\Moderacja\OcenaModelem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OCENA ZDJĘCIA PROFILOWEGO MODELEM (issue #237, D-052 + D-055).
 *
 * PO CO OSOBNE ZADANIE
 * Awatar nie należy do żadnego wpisu, więc `PrzeanalizujTresc` nigdy go nie
 * widziało: model oceniał zdjęcia wpisów, a zdjęcie profilowe szło zupełnie
 * inną drogą (`AvatarSettingsController` → `StoreUploadedImage` →
 * `ProcessUploadedImage`) i nikt na niej analizy nie zlecał.
 *
 * A AWATAR JEST WIDOCZNY CZĘŚCIEJ NIŻ JAKIKOLWIEK WPIS. Wpis widzą
 * obserwujący i ci, którzy trafią na niego w feedzie; awatar chodzi za
 * człowiekiem po całym serwisie — przy każdym komentarzu pod cudzym
 * przepisem, na tablicy dnia, na listach obserwujących, w wynikach szukania
 * osób. Do tego jest najtańszym miejscem dla kogoś, kto chce zaszkodzić: nie
 * wymaga napisania ani jednego słowa, więc nie rusza `WykrywaczSygnalow`,
 * który pracuje na tekście.
 *
 * CZEKA NA WARIANTY, ZAMIAST CICHO NIE ZROBIĆ NIC
 * Model dostaje wariant `thumb` — przekodowany, bez EXIF-u. Wariant powstaje
 * w `ProcessUploadedImage` na kolejce `media`, czyli w innym zadaniu i później
 * niż żądanie HTTP, w którym ktoś wgrał zdjęcie. Gdyby to zadanie po prostu
 * kończyło się przy zdjęciu w stanie `processing`, cała funkcja działałaby
 * wyłącznie wtedy, gdy worker mediów wyprzedzi worker kolejki `low` — czyli
 * losowo. Dlatego przy nieprzetworzonym zdjęciu zadanie WRACA DO KOLEJKI
 * (`release`), a nie kończy się powodzeniem.
 *
 * GRANICA BEZ ZMIAN: AUTOMAT PODNOSI RĘKĘ, NIGDY NIE ZAMYKA DRZWI
 * Awatar zostaje widoczny, autor niczego się nie dowiaduje, w `reports`
 * powstaje jedna pozycja dla człowieka (D-052 poz. 3.6, 3.10, D-055).
 * Przy zdjęciu profilowym pokusa jest większa niż zwykle — „przecież
 * wystarczy podmienić na literę" — ale ciche podmienienie komuś awatara
 * przez maszynę to jest dokładnie shadow filtering z poz. 3.16, odrzucone
 * jako sprzeczne z art. 17 DSA.
 *
 * CELEM OZNACZENIA JEST ZDJĘCIE, NIE KONTO
 * Indeks `reports_jeden_automat_na_tresc` przepuszcza jedno oznaczenie
 * automatu na (typ, identyfikator) na zawsze. Przy celu `user` znaczyłoby to
 * „pierwszy awatar tego konta i już nigdy więcej", a podmiana zdjęcia to
 * sekunda pracy. Cel to więc `media` z identyfikatorem konkretnego pliku.
 */
class PrzeanalizujAwatar implements ShouldQueue
{
    use Queueable;

    /**
     * TRZY PRÓBY, W ODRÓŻNIENIU OD `PrzeanalizujTresc`.
     *
     * Tam analiza jest czysto obliczeniowa: padła raz, padnie tak samo trzy
     * razy. Tutaj zadanie ma realny powód, żeby wrócić — zdjęcie może być
     * jeszcze w przetwarzaniu, a to stan przejściowy, który mija sam.
     */
    public int $tries = 3;

    public int $timeout = 30;

    /** Ile sekund czekamy, zanim zapytamy o zdjęcie ponownie. */
    private const PRZERWA = 30;

    /** Za wszystkim, co robi człowiek — tak jak `PrzeanalizujTresc`. */
    private const KOLEJKA = 'low';

    /** Wchodzi do powodu, żeby moderator wiedział, na co patrzy, przed otwarciem podglądu. */
    private const PRZEDMIOT = 'Zdjęcie profilowe';

    public function __construct(public string $mediaId) {}

    public static function dlaZdjecia(Media $media): PendingDispatch
    {
        return self::dispatch((string) $media->getKey())->onQueue(self::KOLEJKA);
    }

    public function handle(OcenaModelem $model, OznaczDoPrzegladu $oznacz, AlarmujModeratora $alarm): void
    {
        if (! config('kuking.moderation.sygnaly.wlaczone')) {
            return;
        }

        try {
            $media = Media::query()->find($this->mediaId);

            if ($media === null) {
                return;
            }

            // ZDJĘCIE MUSI NADAL BYĆ CZYJŚ AWATAREM. Ktoś mógł je podmienić
            // albo usunąć między wgraniem a tym zadaniem; oglądanie pliku,
            // którego nikt już nie widzi, dokładałoby moderatorowi pozycję
            // do kolejki za treść, której nie ma na ekranie.
            if (! $this->jestAwatarem($media)) {
                return;
            }

            if ($media->status === Media::STATUS_PENDING || $media->status === Media::STATUS_PROCESSING) {
                $this->release(self::PRZERWA);

                return;
            }

            if ($media->status !== Media::STATUS_READY) {
                // Odrzucone przy przetwarzaniu — nie ma czego oceniać i nie
                // ma czego pokazywać, bo takie zdjęcie nie wyświetla się
                // nikomu.
                return;
            }

            $sygnaly = $model->dlaZdjecia($media, self::PRZEDMIOT);

            $oznaczenie = $oznacz->handle($media, $sygnaly);

            if ($oznaczenie !== null) {
                $alarm->handle($oznaczenie, $sygnaly);
            }
        } catch (Throwable $blad) {
            // Bez identyfikatora zdjęcia i bez bajtów — to jest cudza
            // fotografia, a dziennik błędów nie jest miejscem na treści
            // użytkowników (AGENTS.md §7).
            Log::warning('Ocena zdjęcia profilowego modelem nie powiodła się.', [
                ...ExceptionContext::forStage($blad, 'avatar_analysis'),
            ]);
        }
    }

    /**
     * Czy to zdjęcie jest w tej chwili czyimś awatarem.
     *
     * Pytamy `profiles`, nie właściciela zdjęcia: awatar to wiersz
     * `profiles.avatar_media_id`, a zdjęcie może zostać w `media` po
     * podmianie (sprząta je `kuking:sprzataj-osierocone-zdjecia`).
     */
    private function jestAwatarem(Media $media): bool
    {
        return Profile::query()->where('avatar_media_id', $media->getKey())->exists();
    }
}
