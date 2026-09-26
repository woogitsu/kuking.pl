<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Jedno miejsce unieważniania zapamiętanej mapy strony (issue #1006).
 *
 * `/sitemap.xml` trzyma listę adresów w cache przez sześć godzin. Do
 * 24 września 2026 nic tego klucza nie kasowało: ukryty wpis, przepis
 * przestawiony na prywatny albo zbanowany autor zostawali w mapie do
 * wygaśnięcia — mapa zapraszała wyszukiwarki pod adresy, które dają 403
 * albo 404. Sześć godzin zostaje wyłącznie jako zabezpieczenie awaryjne
 * (np. zmiana z pominięciem Eloquenta).
 *
 * DLACZEGO HAKI MODELI, A NIE WYWOŁANIA W AKCJACH
 * Ścieżek zapisu jest kilkanaście (publikacja, edycja, moderacja,
 * przywrócenie, usuwanie konta, zmiana nazwy profilu). Wywołanie
 * w każdej akcji to kilkanaście miejsc do zapomnienia przy następnej.
 * Hak na modelu łapie każdą z nich — także te, których jeszcze nie ma.
 *
 * DLACZEGO TYLKO WYBRANE KOLUMNY
 * Wpis zapisuje się też przy rzeczach, które mapy nie dotyczą. Klucz
 * kasujemy wyłącznie wtedy, gdy zmieniło się coś, od czego zależy
 * OBECNOŚĆ adresu w mapie albo sam adres. Każde skasowanie to jedno
 * ponowne złożenie mapy przy najbliższym żądaniu — tanie, ale nie
 * dokładamy go do każdego zapisu.
 *
 * DLACZEGO PO ZATWIERDZENIU TRANSAKCJI
 * `DB::afterCommit()` wykonuje się od razu poza transakcją, a w niej —
 * dopiero po COMMIT, i wcale po ROLLBACK. Skasowanie przed zatwierdzeniem
 * pozwoliłoby równoległemu żądaniu odbudować mapę ze starych danych
 * i zapamiętać ją na kolejne sześć godzin.
 *
 * Kasujemy WYŁĄCZNIE ten klucz — nigdy `Cache::flush()`.
 */
final class MapaStrony
{
    public const KLUCZ = 'sitemap.urls';

    /** Awaryjny czas życia mapy, gdyby któraś zmiana ominęła haki. */
    public const CZAS_ZYCIA_GODZINY = 6;

    /**
     * Kolumny, od których zależy obecność adresu w mapie albo sam adres.
     *
     * @var array<class-string<Model>, list<string>>
     */
    public const KOLUMNY = [
        Recipe::class => ['status', 'visibility', 'published_at', 'slug', 'author_id', 'deleted_at'],
        Post::class => ['status', 'visibility', 'published_at', 'body', 'kind', 'author_id', 'deleted_at'],
        Profile::class => ['username', 'user_id'],
        User::class => ['status'],
    ];

    public static function uniewaznij(): void
    {
        DB::afterCommit(static fn () => Cache::forget(self::KLUCZ));
    }

    public static function zarejestrujHaki(): void
    {
        // Nowy przepis albo wpis może wejść do mapy od razu. Nowe konto
        // i nowy profil nie — nie mają jeszcze treści. `created`, a nie
        // `wasRecentlyCreated` w `saved`: ta flaga zostaje na egzemplarzu
        // i kasowałaby mapę przy każdym kolejnym zapisie w tym żądaniu.
        foreach ([Recipe::class, Post::class] as $model) {
            $model::created(static fn () => self::uniewaznij());
        }

        foreach (self::KOLUMNY as $model => $kolumny) {
            $model::saved(static function (Model $zapisany) use ($kolumny): void {
                if ($zapisany->wasChanged($kolumny)) {
                    self::uniewaznij();
                }
            });

            $model::deleted(static fn () => self::uniewaznij());
        }
    }
}
