<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use Illuminate\Http\Response;

/**
 * Sitemapa i robots.txt.
 *
 * Wersja MVP generuje mapę na żądanie z cache'em, bo przy kilku tysiącach
 * przepisów to jest tanie. Podział na chunki i job GenerateSitemapChunk
 * wchodzą dopiero przy dziesiątkach tysięcy adresów (docs/seo/SEO_TECHNICAL.md).
 *
 * Do mapy trafia TYLKO to, co realnie ma wartość dla czytelnika:
 * publiczne przepisy, publiczne wpisy z treścią oraz profile, które mają
 * co najmniej jedną publiczną treść. Puste profile to cienka treść.
 */
class SitemapController extends Controller
{
    public function index(): Response
    {
        $urls = cache()->remember('sitemap.urls', now()->addHours(6), function (): array {
            $urls = [
                ['loc' => route('landing'), 'priority' => '1.0', 'changefreq' => 'daily'],
                ['loc' => route('discover'), 'priority' => '0.8', 'changefreq' => 'daily'],
                ['loc' => route('help'), 'priority' => '0.3', 'changefreq' => 'monthly'],
                ['loc' => route('rules'), 'priority' => '0.3', 'changefreq' => 'monthly'],
            ];

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
                    foreach ($profiles as $profile) {
                        $urls[] = [
                            'loc' => route('profile.show', $profile->username),
                            'lastmod' => $profile->updated_at?->toAtomString(),
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
            //
            // `/szukaj` CELOWO NIE MA TU `Disallow` (issue #964). Strona wysyła
            // `noindex` w meta i w `X-Robots-Tag` — ale robot, któremu
            // robots.txt zabrania wejścia, nigdy tej reguły nie odczyta,
            // a adres odkryty z zewnętrznego linku zostaje w indeksie jako
            // goły URL bez opisu. Google Search Central: `noindex` działa
            // tylko na stronie, której robots.txt nie blokuje. To samo mówi
            // `docs/seo/SEO_TECHNICAL.md` §1.3 i §3.1.
            'Disallow: /home',
            'Disallow: /dodaj',
            'Disallow: /witaj',
            'Disallow: /powiadomienia',
            'Disallow: /ustawienia',
            // `/zeszyt` BEZ PRZEDROSTKA CAŁOŚCI (issue #965). Publiczny zeszyt
            // („Ten zeszyt widzą wszyscy") stoi pod `/zeszyt/{uuid}` i ma być
            // dostępny dla gościa i robota. Zablokowane zostają: sama lista
            // `/zeszyt` (za logowaniem) i wszystko pod `/zeszyt/{uuid}/...`
            // (edycja). Prywatny zeszyt robot i tak dostaje jako 403,
            // a właścicielowi widok dokłada `noindex`.
            'Disallow: /zeszyt$',
            'Disallow: /zeszyt/*/',
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
