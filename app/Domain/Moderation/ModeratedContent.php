<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;

/**
 * Jedno miejsce, które wie, CO MODERACJA MOŻE ROBIĆ Z KTÓRYM RODZAJEM TREŚCI.
 *
 * DLACZEGO TO ISTNIEJE
 * Wiedza „post i przepis mają status, komentarz ma inny zestaw statusów,
 * a «Ugotowałem» nie ma statusu wcale" była wcześniej rozsypana po prywatnych
 * metodach kontrolera (`hide()`, `cel()`) i po macierzy `ModerationAction::DOZWOLONE`.
 * Skutek widać było gołym okiem: `hide` na „Ugotowałem" przechodziło walidację
 * i nie robiło NIC — bo `hide()` miało gałęzie tylko dla trzech modeli, a
 * czwarty po cichu wypadał.
 *
 * Przywracanie treści (#65) potrzebuje dokładnie tej samej wiedzy, tyle że
 * w drugą stronę. Przepisana drugi raz rozjechałaby się z pierwszą przy
 * pierwszej zmianie — dlatego jest tu, a nie tam.
 */
final class ModeratedContent
{
    /**
     * Nazwa typu w `reports.target_type` i `moderation_actions.target_type`.
     *
     * @var array<class-string, string>
     */
    public const TYPY = [
        User::class => 'user',
        Post::class => 'post',
        Recipe::class => 'recipe',
        Comment::class => 'comment',
        CookedEvent::class => 'cooked_event',

        // ZDJĘCIE JAKO OSOBNY CEL (issue #237). Dziś trafia tu wyłącznie
        // zdjęcie profilowe: model ocenia je po przetworzeniu, a oznaczenie
        // musi wskazywać KONKRETNY plik, nie konto — inaczej „jedno
        // oznaczenie automatu na treść" znaczyłoby „pierwszy awatar tego
        // konta i już nigdy więcej", a podmiana zdjęcia to sekunda pracy.
        //
        // Nazwa typu to `media`, a nie `avatar`, bo ta mapa jest po KLASIE,
        // a klasa jest ta sama dla awatara i dla zdjęcia we wpisie.
        Media::class => 'media',
    ];

    /**
     * Status oznaczający „ukryte przez moderację" — per model.
     *
     * Brak wpisu znaczy: tego rodzaju treści NIE DA SIĘ ukryć (nie ma czego
     * ustawić). To nie jest przeoczenie, tylko odpowiedź.
     *
     * @var array<class-string, string>
     */
    public const UKRYTY = [
        Post::class => Post::STATUS_HIDDEN,
        Recipe::class => Recipe::STATUS_HIDDEN,
        Comment::class => Comment::STATUS_HIDDEN,
    ];

    /**
     * Statusy, do których wolno WRÓCIĆ po zdjęciu ukrycia.
     *
     * Lista jest zamknięta, bo `previous_status` w bazie to zwykły tekst.
     * Wiersz sprzed lat, wiersz dopisany ręcznie w psql podczas incydentu albo
     * literówka nie mogą przywrócić treści do stanu, którego produkt nie zna —
     * ani, co gorsza, z powrotem do `hidden`.
     *
     * @var array<class-string, list<string>>
     */
    public const PRZYWRACALNE_STATUSY = [
        Post::class => [Post::STATUS_DRAFT, Post::STATUS_PUBLISHED],
        Recipe::class => [Recipe::STATUS_DRAFT, Recipe::STATUS_PUBLISHED],

        // Komentarz nie ma szkicu — albo jest, albo go nie ma.
        Comment::class => [Comment::STATUS_PUBLISHED],
    ];

    /**
     * Dokąd wraca treść, gdy stanu sprzed ukrycia NIE ZNAMY.
     *
     * Dotyczy treści ukrytych ZANIM `moderation_actions.previous_status`
     * zaczęło istnieć, oraz ukrytych ręcznie w bazie.
     *
     * DLACZEGO SZKIC, A NIE „OPUBLIKOWANY"
     * Pomyłka w stronę szkicu jest odwracalna przez autora jednym kliknięciem
     * („Opublikuj"). Pomyłka w drugą stronę jest nieodwracalna: upublicznia
     * treść, której właściciel nigdy nikomu nie pokazał, a raz pokazanego
     * zdjęcia nie da się „odpokazać". Przy niepewności wybieramy stan
     * mniej widoczny.
     *
     * Komentarz nie ma tu wyboru — wraca opublikowany albo wcale.
     *
     * @var array<class-string, string>
     */
    public const DOMYSLNY_PO_PRZYWROCENIU = [
        Post::class => Post::STATUS_DRAFT,
        Recipe::class => Recipe::STATUS_DRAFT,
        Comment::class => Comment::STATUS_PUBLISHED,
    ];

    public static function typ(object $model): ?string
    {
        return self::TYPY[$model::class] ?? null;
    }

    /**
     * Obiekt wskazany przez `target_type` + `target_id`.
     *
     * `$zUsunietymi` sięga po treści miękko usunięte — potrzebne przy
     * przywracaniu i przy odwołaniu od decyzji `remove`, bo tam celem jest
     * z definicji coś, czego zwykłe zapytanie już nie widzi.
     */
    public static function znajdz(?string $typ, ?string $id, bool $zUsunietymi = false): ?object
    {
        // NULL po obu stronach jest normalnym stanem, nie błędem wywołania:
        // zgłoszenie nielegalnej treści (DSA art. 16) przyjmujemy nawet wtedy,
        // gdy nie umieliśmy rozwiązać wklejonego adresu. Typ jest wtedy
        // `unknown`, którego i tak nie ma w TYPY, a cel jest pusty.
        if ($typ === null || $id === null) {
            return null;
        }

        $klasa = array_search($typ, self::TYPY, true);

        if ($klasa === false) {
            return null;
        }

        $zapytanie = $klasa::query();

        if ($zUsunietymi && method_exists($klasa, 'bootSoftDeletes')) {
            $zapytanie->withTrashed();
        }

        return $zapytanie->find($id);
    }

    /**
     * Osoba, której dotyczy decyzja: zgłoszone konto albo autor treści.
     *
     * Modele używają różnych nazw relacji, więc pytamy o to, co faktycznie
     * istnieje, zamiast zakładać jedną wspólną konwencję.
     */
    public static function osoba(object $model): ?User
    {
        if ($model instanceof User) {
            return $model;
        }

        foreach (['author', 'user', 'owner'] as $relacja) {
            if (method_exists($model, $relacja)) {
                $osoba = $model->{$relacja};

                if ($osoba instanceof User) {
                    return $osoba;
                }
            }
        }

        return null;
    }

    /**
     * Czy treść jest już zdjęta z serwisu — miękko usunięta albo (komentarz
     * z odpowiedziami) zastąpiona napisem „Komentarz usunięty.” (G31).
     *
     * Drugi przypadek wiersz fizycznie ma i nawet status `published`, ale
     * tekstu już nie — decyzja o nim byłaby decyzją o napisie.
     */
    public static function jestZdjeta(object $model): bool
    {
        if (method_exists($model, 'trashed') && $model->trashed()) {
            return true;
        }

        return $model instanceof Comment && $model->body_removed_at !== null;
    }

    /** Czy tę treść w ogóle da się ukryć (a więc i przywrócić). */
    public static function daSieUkryc(object $model): bool
    {
        return isset(self::UKRYTY[$model::class]);
    }

    public static function jestUkryta(object $model): bool
    {
        $ukryty = self::UKRYTY[$model::class] ?? null;

        return $ukryty !== null && ($model->status ?? null) === $ukryty;
    }

    /**
     * Status, do którego treść ma wrócić.
     *
     * `$zapisany` to `moderation_actions.previous_status` z decyzji o ukryciu.
     * Wartość nieznana albo pusta oddaje pole domyślnemu, bezpiecznemu stanowi.
     */
    public static function statusPoPrzywroceniu(object $model, ?string $zapisany): string
    {
        $dozwolone = self::PRZYWRACALNE_STATUSY[$model::class] ?? [];

        if ($zapisany !== null && in_array($zapisany, $dozwolone, true)) {
            return $zapisany;
        }

        return self::DOMYSLNY_PO_PRZYWROCENIU[$model::class]
            ?? throw new BladDlaCzlowieka('Tej treści nie da się przywrócić.');
    }
}
