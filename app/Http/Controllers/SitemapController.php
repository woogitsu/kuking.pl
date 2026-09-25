<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Support\MapaStrony;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Sitemapa i robots.txt.
 *
 * Wersja MVP generuje mapę na żądanie z cache'em, bo przy kilku tysiącach
 * przepisów to jest tanie. Podział na chunki i job GenerateSitemapChunk
 * wchodzą dopiero przy dziesiątkach tysięcy adresów (docs/seo/SEO_TECHNICAL.md).
 *
 * Do mapy trafia TYLKO to, co realnie ma wartość dla czytelnika:
 * jawna lista publicznych hubów (`publiczneWejscia()`), publiczne przepisy, publiczne wpisy z treścią oraz profile, które mają
 * co najmniej jedną publiczną treść. Puste profile to cienka treść.
 */
class SitemapController extends Controller
{
    public function index(): Response
    {
        // Klucz kasuje `MapaStrony` po każdej zatwierdzonej zmianie widoczności
        // (issue #1006); sześć godzin to tylko zabezpieczenie awaryjne.
        $urls = cache()->remember(MapaStrony::KLUCZ, now()->addHours(MapaStrony::CZAS_ZYCIA_GODZINY), function (): array {
            $urls = self::publiczneWejscia();

            // `dostepnyJakoAutor()` OBOK `publiclyVisible()` — to są dwie
            // różne granice (audyt W5-09). `publiclyVisible` koduje status
            // i widoczność TREŚCI; nie wie nic o tym, że autor został
            // zbanowany albo kasuje konto. Bez tego mapa podawała Google'owi
            // adresy, pod którymi zwykły człowiek dostaje 403 — czyli
            // zapraszała do drzwi, które sama zamknęła.
            Recipe::query()
                ->publiclyVisible()
                ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
                ->select(['id', 'slug', 'updated_at'])
                ->chunkById(500, function ($recipes) use (&$urls): void {
                    foreach ($recipes as $recipe) {
                        $urls[] = [
                            'loc' => route('recipes.show', $recipe->slug),
                            'lastmod' => $recipe->updated_at?->toAtomString(),
                            'priority' => '0.9',
                            'changefreq' => 'weekly',
                        ];
                    }
                });

            // Adres przez `Post::url()`, nie ręczne `posts.show` (#968):
            // pytanie ma własną stronę `/pytania/{id}`, a `/wpisy/{id}` tylko
            // na nią przekierowuje. Pytanie wchodzi też z samym tytułem —
            // tytuł jest tam obowiązkowy i to on jest treścią. Dla zwykłych
            // wpisów granica `body` zostaje: pusty wpis to zapowiedź przepisu.
            Post::query()
                ->publiclyVisible()
                ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
                ->where(function ($maTresc): void {
                    // Tytułu nie trzeba sprawdzać: `posts_kind_title_check`
                    // wymusza go przy każdym pytaniu.
                    $maTresc->whereNotNull('body')->orWhere('kind', Post::KIND_QUESTION);
                })
                ->select(['id', 'kind', 'updated_at'])
                ->chunkById(500, function ($posts) use (&$urls): void {
                    foreach ($posts as $post) {
                        $urls[] = [
                            'loc' => $post->url(),
                            'lastmod' => $post->updated_at?->toAtomString(),
                            'priority' => '0.5',
                            'changefreq' => 'monthly',
                        ];
                    }
                });

            // Profil też — `UserPolicy::viewProfile()` odrzuca konto
            // zbanowane i kasowane tą samą regułą, więc mapa nie może go
            // ogłaszać. Audyt tego wprost nie wymienił, ale to ten sam brak.
            //
            // Tu jednak `widocznyJakoOsoba()`, a NIE `dostepnyJakoAutor()`
            // (D-022). Profil konta `erased` da się otworzyć — jest adresem,
            // pod który prowadzi podpis „Użytkownik usunięty" — ale nie ma po
            // co zapraszać do niego wyszukiwarek. Nie ma tam ani nazwy, ani
            // opisu, ani zdjęcia: to strona bez treści własnej, a takich mapa
            // strony nie ogłasza (patrz nagłówek tego pliku: „Puste profile
            // to cienka treść"). Treści tej osoby zostają w mapie osobno,
            // wyżej — bo tam granicą jest autorstwo, nie osoba.
            // „Co najmniej jedna publiczna treść" znaczy WPIS **ALBO** PRZEPIS.
            // Stało tu samo `user.posts` i przez to o obecności profilu
            // w mapie decydowała nie treść autora, tylko zapowiedź.
            //
            // Publikacja przepisu zakłada zapowiedź (wpis z `recipe_id`
            // i pustym `body`), więc dopóki zapowiedź żyje, profil wchodził
            // do mapy „przy okazji" i wada się nie pokazywała. Wystarczyło
            // jednak, żeby zapowiedź zniknęła — autor ją skasował albo
            // moderacja ją ukryła — i profil wypadał z mapy, choć jego
            // publiczny przepis nadal w niej stał. Mapa ogłaszała wtedy
            // przepis, ale nie autora, który go napisał.
            //
            // To jest wprost wbrew nagłówkowi tego pliku („profile, które
            // mają co najmniej jedną publiczną treść") i wbrew
            // `docs/seo/SEO_TECHNICAL.md` §3, wiersz „Profil z ≥1 publiczną
            // treścią → index, follow".
            //
            // Granica zostaje `widocznyJakoOsoba()` (D-022), nie
            // `dostepnyJakoAutor()` — patrz komentarz wyżej.
            Profile::query()
                ->whereHas('user', fn ($autor) => $autor->widocznyJakoOsoba())
                ->where(function ($maPubliczonaTresc): void {
                    $maPubliczonaTresc
                        ->whereHas('user.posts', fn ($query) => $query->publiclyVisible())
                        ->orWhereHas('user.recipes', fn ($query) => $query->publiclyVisible());
                })
                ->select(['user_id', 'username', 'updated_at'])
                ->chunkById(500, function ($profiles) use (&$urls): void {
                    $zmianyTresci = self::zmianyTresciAutorow($profiles->pluck('user_id')->all());

                    foreach ($profiles as $profile) {
                        $lastmod = collect([$profile->updated_at, $zmianyTresci[$profile->user_id] ?? null])
                            ->filter()
                            ->max();

                        $urls[] = [
                            'loc' => route('profile.show', $profile->username),
                            'lastmod' => $lastmod?->toAtomString(),
                            'priority' => '0.6',
                            'changefreq' => 'weekly',
                        ];
                    }
                }, 'user_id');

            return $urls;
        });

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * Publiczne punkty wejścia, które mapa ogłasza z nazwy (#1032).
     *
     * Lista jest JAWNA i zamknięta, a nie „wszystkie trasy GET": kryterium
     * wejścia to strona publiczna (bez logowania), bez `noindex`, pod jednym
     * adresem bez parametrów. Wcześniej stały tu cztery adresy dobrane
     * przypadkiem — Pomoc i Zasady były, a Poradźcie, Tagi i O Kuking nie.
     *
     * - `questions.index` tylko przy włączonej fladze pytań: przy wyłączonej
     *   `/pytania` oddaje 404, więc mapa nie może go ogłaszać.
     * - Regulamin i Prywatność wchodzą, bo są indeksowane (własny opis,
     *   brak `noindex`) i ludzie ich szukają z zewnątrz.
     * - „Napisz do nas" wchodzi ŚWIADOMIE — komentarz przy trasie i w widoku:
     *   kto nie może się zalogować, szuka kontaktu w wyszukiwarce.
     *   `/napisz-do-nas/dziekujemy` nie: to potwierdzenie, nie wejście.
     * - Pojedyncze `/tag/{tag}` NIE wchodzą — to osobny próg jakości (#1007).
     *
     * @return list<array{loc: string, priority: string, changefreq: string}>
     */
    public static function publiczneWejscia(): array
    {
        $wejscia = [
            ['loc' => route('landing'), 'priority' => '1.0', 'changefreq' => 'daily'],
            ['loc' => route('discover'), 'priority' => '0.8', 'changefreq' => 'daily'],
        ];

        if (config('kuking.questions.enabled')) {
            $wejscia[] = ['loc' => route('questions.index'), 'priority' => '0.8', 'changefreq' => 'daily'];
        }

        return [
            ...$wejscia,
            ['loc' => route('tags.index'), 'priority' => '0.6', 'changefreq' => 'weekly'],
            ['loc' => route('about'), 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['loc' => route('help'), 'priority' => '0.3', 'changefreq' => 'monthly'],
            ['loc' => route('rules'), 'priority' => '0.3', 'changefreq' => 'monthly'],
            ['loc' => route('kontakt'), 'priority' => '0.3', 'changefreq' => 'monthly'],
            ['loc' => route('terms'), 'priority' => '0.2', 'changefreq' => 'yearly'],
            ['loc' => route('privacy'), 'priority' => '0.2', 'changefreq' => 'yearly'],
        ];
    }

    /**
     * Ostatnia zmiana treści, którą profil pokazuje publicznie (#1280).
     *
     * Profil to głównie lista wpisów i przepisów autora, więc sama data
     * `profiles.updated_at` zaniżała `lastmod`: nowy przepis zmieniał stronę,
     * a mapa podawała datę zmiany opisu sprzed miesięcy.
     *
     * KONTRAKT: maksimum `updated_at` z treści, które KIEDYŚ BYŁY publiczne
     * i opublikowane — `visibility = public` i `published_at` ustawione —
     * WŁĄCZNIE z ukrytymi przez moderację i usuniętymi. Publikacja i edycja
     * przesuwają `updated_at` treści; ukrycie i usunięcie też (zmiana statusu,
     * `deleted_at`), a właśnie wtedy z profilu coś znika. Szkice, treści
     * prywatne i dla obserwujących nie wpływają na datę, więc mapa nie zdradza,
     * że autor pracuje nad czymś niepublicznym. Komentarze i „Ugotowałem" nie
     * dotykają `posts.updated_at` ani `recipes.updated_at`, więc też nie.
     *
     * ZNANA GRANICA: zmiana widoczności z publicznej na prywatną zostawia
     * treść poza tym zbiorem, więc tego jednego zdarzenia data nie pokaże —
     * inaczej trzeba by liczyć także zmiany treści prywatnych.
     *
     * DWA zapytania na partię 500 profili, nie po jednym na profil (bez N+1).
     *
     * @param  list<string>  $autorzy
     * @return array<string, Carbon>
     */
    private static function zmianyTresciAutorow(array $autorzy): array
    {
        $zmiany = [];

        foreach ([Post::withTrashed()->enabledKinds(), Recipe::withTrashed()] as $zapytanie) {
            $zapytanie->toBase()
                ->whereIn('author_id', $autorzy)
                ->where('visibility', 'public')
                ->whereNotNull('published_at')
                ->groupBy('author_id')
                ->selectRaw('author_id, max(updated_at) as zmiana')
                ->get()
                ->each(function ($wiersz) use (&$zmiany): void {
                    $zmiana = Carbon::parse($wiersz->zmiana);
                    $obecna = $zmiany[$wiersz->author_id] ?? null;
                    $zmiany[$wiersz->author_id] = $obecna?->greaterThan($zmiana) ? $obecna : $zmiana;
                });
        }

        return $zmiany;
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            // Wyszukiwanie nigdy nie jest treścią — to nieskończone kombinacje
            // parametrów, klasyczna pułapka indeksowania.
            // ADRESY MUSZĄ BYĆ TE, KTÓRE SERWIS NAPRAWDĘ MA.
            // Wcześniej stało tu `/search`, `/home` i `/add` — po angielsku,
            // czyli pod adresami, których w tym serwisie nie ma. Wyszukiwarka
            // i ekran dodawania NIE BYŁY wyłączone z indeksowania.
            //
            // Ta sama lista, tylko poprawna, stała dziesięć plików dalej:
            // `ApplySecurityHeaders` wysyła `X-Robots-Tag` na polskich
            // prefiksach. Strony nie trafiały więc do indeksu, ale budżet
            // indeksowania szedł na `/szukaj?q=...`, a plik twierdził coś,
            // czego nie robił.
            'Disallow: /szukaj',
            'Disallow: /home',
            'Disallow: /dodaj',
            'Disallow: /witaj',
            'Disallow: /powiadomienia',
            'Disallow: /ustawienia',
            'Disallow: /zeszyt',
            'Disallow: /admin',
            // UKOŚNIK NA KOŃCU MA ZNACZENIE. `Disallow: /zglos` to dopasowanie
            // po przedrostku, więc blokowało też `/zglos-nielegalna-tresc` —
            // publiczną drogę zgłoszenia nielegalnej treści, którą DSA art. 16
            // ust. 1 każe udostępnić w sposób ŁATWO DOSTĘPNY. Formularz
            // społecznościowy stoi pod `/zglos/{typ}/{id}` i tylko on ma tu
            // zostać: jest za logowaniem i dotyczy konkretnej treści.
            'Disallow: /zglos/',
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines))
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
