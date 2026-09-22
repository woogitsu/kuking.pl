<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\OdpowiedzDlaZglaszajacego;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\ContactMessage;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TRZY ROZSTRZYGNIĘCIA WŁAŚCICIELA PO AUDYCIE WARSTW (issue #367, commit
 * `e57b2c9`) — DOPISANE TESTY, KTÓRYCH TAMTA ZMIANA NIE MIAŁA.
 *
 * Commit zmienił cztery pliki Blade i ani jednego testowego. Issue wymagało
 * dla każdej z trzech zmian asercji na klasę warstwy plus sprawdzenia, że
 * formularz kodu zapasowego nadal działa. Bez tego warstwa wróci przy
 * pierwszym sprzątaniu CSS-a i nikt tego nie zauważy — to jest dokładnie to
 * ryzyko, które D-132 nazywa przy migracjach: strażnik bez testu nie jest
 * strażnikiem.
 *
 * Co pilnujemy:
 *
 *  1. `auth/two_factor_challenge.blade.php` — blok kodu zapasowego stoi na
 *     `sekcja-strony`, nie na wgłębionej `ramka-pomocnicza` (D-127: droga
 *     równorzędna nigdy nie schodzi na warstwę wgłębioną). RAZEM z dowodem,
 *     że ten formularz naprawdę wpuszcza na konto — zmiana klasy nie miała
 *     prawa zepsuć drogi wejścia komuś, kto zgubił telefon.
 *  2. `pages/zgloszenia/szczegoly.blade.php` — OBIE gałęzie („Na czym stoi
 *     sprawa" i „Nasza decyzja") na `card`, mierzone OSOBNO, plus niezmiennik
 *     z kodu: wygląd nie zmienia się między stanami (D-128).
 *  3. `pages/admin/uzytkownicy.blade.php` i `pages/admin/wiadomosci.blade.php`
 *     — pusta kolejka mówi komponentem `<x-empty-state>` i NIE MA w nim
 *     przycisku ani odnośnika (D-053: żadnego martwego przycisku).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO TEN PLIK RENDERUJE EKRANY, A NIE CZYTA PLIKÓW BLADE
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bo test sprawdzający, że „w pliku jest napisane `card`", przechodzi także
 * wtedy, gdy klasa stoi w komentarzu albo w zupełnie innym miejscu pliku.
 * W tym repozytorium przewrócił się na tym inny strażnik: wzorzec złapał
 * nazwę selektora wymienioną w komentarzu CSS i odczytał ciało cudzej reguły.
 * Tu każda asercja idzie na WYRENDEROWANY przez HTTP dokument i dotyczy
 * KONKRETNEGO elementu znalezionego przez `DOMXPath` (pułapka 1
 * z `docs/PULAPKI_TESTOW.md`) — nie całego HTML-a odpowiedzi.
 *
 * Element znajdujemy po tym, CZYM JEST, a nie po klasie, której pilnujemy:
 * blok kodu zapasowego po polu `backup_code` w środku, gałęzie zgłoszenia po
 * nagłówku. Szukanie po klasie warstwy dałoby test, który po regresji nie
 * oblewa, tylko nie znajduje elementu — i mówiłby wtedy nie to, co trzeba.
 */
class TrzyRozstrzygnieciaWarstwTest extends TestCase
{
    use RefreshDatabase;

    // =================================================================
    //  1. KOD ZAPASOWY 2FA — WARSTWA I DZIAŁANIE
    // =================================================================

    /**
     * BLOK KODU ZAPASOWEGO STOI NA WARSTWIE RÓWNORZĘDNEJ (D-127).
     *
     * W środku jest pełna, samodzielna droga do konta: własny `<form>`,
     * własne pole i własny przycisk. Warstwa wgłębiona mówi wizualnie „to
     * jest coś obok" — komuś, kto stracił telefon, mówiłaby nieprawdę
     * w najgorszym momencie kontaktu z serwisem.
     */
    #[Test]
    public function test_kod_zapasowy_nie_stoi_na_warstwie_wglebionej(): void
    {
        $html = $this->ekranWyzwania2fa();

        $blok = $this->element(
            $html,
            "//details[.//input[@name='backup_code']]",
            'Nie znalazłem bloku `<details>` z polem `backup_code` na ekranie wyzwania 2FA. '
            .'Asercja warstwy nie ma czego sprawdzić.',
        );

        $klasy = $this->klasy($blok);

        $this->assertContains(
            'sekcja-strony',
            $klasy,
            'Blok z kodem zapasowym zszedł z warstwy sekcji. D-127: droga równorzędna '
            .'do wejścia na konto nigdy nie stoi na warstwie wgłębionej.',
        );

        $this->assertNotContains(
            'ramka-pomocnicza',
            $klasy,
            'Blok z kodem zapasowym wrócił na wgłębioną `ramka-pomocnicza` — czyli na warstwę, '
            .'która mówi „to jest coś obok". W środku jest pełna droga do konta (D-127).',
        );

        // KONTROLA, ŻE TEST W OGÓLE ODRÓŻNIA WARSTWY: główny formularz na tym
        // samym ekranie jest panelem i ma nim zostać. Bez tej pary asercja
        // wyżej przechodziłaby także dla ekranu, na którym wszystko jest
        // sekcją, bo klasy warstw przestały być używane.
        $panel = $this->element(
            $html,
            "//form[.//input[@name='code']]",
            'Nie znalazłem głównego formularza z kodem z aplikacji.',
        );

        $this->assertContains(
            'panel-formularza',
            $this->klasy($panel),
            'Główny formularz 2FA przestał być panelem formularza — sprawdź, czy klasy warstw '
            .'w ogóle jeszcze trafiają do HTML-a.',
        );
    }

    /**
     * WYRENDEROWANY FORMULARZ PROWADZI TAM, GDZIE POWINIEN.
     *
     * Asercje niżej (poprawny kod wpuszcza, zły nie) wysyłają POST-a wprost
     * na trasę. Same w sobie nie dowodzą więc, że ekran ma formularz, który
     * do tej trasy prowadzi — a zmiana warstwy dotknęła właśnie tego bloku.
     * Ten test domyka lukę: sprawdza kontrakt WYRENDEROWANEGO formularza.
     */
    #[Test]
    public function test_formularz_kodu_zapasowego_prowadzi_na_wlasciwa_trase(): void
    {
        $html = $this->ekranWyzwania2fa();

        $formularz = $this->element(
            $html,
            "//details//form[.//input[@name='backup_code']]",
            'W bloku kodu zapasowego nie ma formularza.',
        );

        $this->assertSame(
            route('login.two_factor.store'),
            $formularz->getAttribute('action'),
            'Formularz kodu zapasowego prowadzi pod inny adres niż weryfikacja drugiego składnika.',
        );

        $this->assertSame('POST', mb_strtoupper($formularz->getAttribute('method')));

        // Bez tokenu CSRF formularz odbiłby się o 419 — czyli blok byłby
        // martwym przyciskiem, mimo że wygląda na działający.
        $this->assertNotNull(
            (new DOMXPath($formularz->ownerDocument))->query(".//input[@name='_token']", $formularz)->item(0),
            'Formularz kodu zapasowego nie ma pola `_token` — wysłanie skończyłoby się na 419.',
        );
    }

    /**
     * POPRAWNY KOD ZAPASOWY WPUSZCZA NA KONTO.
     *
     * To jest najważniejsza asercja w tym pliku. Zmiana warstwy dotyczyła
     * bloku, w którym siedzi jedyna droga wejścia dla kogoś, kto zgubił
     * telefon — a zmiany wyglądu bywają robione w tym samym miejscu co
     * zmiany struktury.
     */
    #[Test]
    public function test_poprawny_kod_zapasowy_wpuszcza_na_konto(): void
    {
        $basia = $this->kontoZ2fa();

        $this->ekranWyzwania2fa($basia);

        $this->post(route('login.two_factor.store'), ['backup_code' => 'ABCD-1234'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia->fresh());
    }

    /**
     * ZŁY KOD ZAPASOWY NIE WPUSZCZA.
     *
     * Druga połowa tej samej pary: bez niej test wyżej przechodziłby także
     * dla formularza, który wpuszcza na cokolwiek (pułapka 4
     * z `docs/PULAPKI_TESTOW.md` — asercja bez kontroli w drugą stronę).
     */
    #[Test]
    public function test_zly_kod_zapasowy_nie_wpuszcza(): void
    {
        $this->ekranWyzwania2fa();

        $this->post(route('login.two_factor.store'), ['backup_code' => 'ZZZZ-9999'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    // =================================================================
    //  2. ZGŁOSZENIE — OBIE GAŁĘZIE, MIERZONE OSOBNO
    // =================================================================

    /**
     * OBIE GAŁĘZIE EKRANU ZGŁOSZENIA STOJĄ NA KARCIE TREŚCI (D-128) — I STOJĄ
     * NA TEJ SAMEJ.
     *
     * Ekran ma dwa stany: sprawa bez decyzji („Na czym stoi sprawa") i sprawa
     * z decyzją („Nasza decyzja"). Mierzone są OSOBNO, bo ekran o dwóch
     * stanach bywa mierzony w jednym i drugi zostaje bez ochrony (D-099,
     * D-106).
     *
     * Trzecia asercja pilnuje niezmiennika, który stoi wprost w kodzie
     * widoku: wygląd NIE ZMIENIA SIĘ między stanami. Różnica warstw kazałaby
     * ekranowi wyglądać inaczej zależnie od tego, czy sprawa jest już
     * rozstrzygnięta — a to nie jest różnica rangi.
     */
    #[Test]
    public function test_obie_galezie_ekranu_zgloszenia_sa_karta_tresci(): void
    {
        [$zglaszajaca, $zgloszenie] = $this->zgloszenieWTrakcie();

        // ---- gałąź pierwsza: sprawa bez decyzji ----
        $przed = $this->htmlZgloszenia($zglaszajaca, $zgloszenie);

        $this->assertFalse($zgloszenie->refresh()->jestRozstrzygniete(), 'Sprawa nie jest w stanie „bez decyzji".');

        $galazCzeka = $this->element(
            $przed,
            "//article[h2[normalize-space()='Na czym stoi sprawa']]",
            'Nie znalazłem bloku „Na czym stoi sprawa" — gałąź „sprawa bez decyzji" się nie wyrenderowała.',
        );

        $this->assertContains(
            'card',
            $this->klasy($galazCzeka),
            'Gałąź „Na czym stoi sprawa" zeszła z karty treści. D-128: odpowiedź na sprawę jest tym, '
            .'PO CO człowiek na ten ekran wszedł.',
        );

        // ---- gałąź druga: ta sama sprawa, po decyzji ----
        $this->rozstrzygnij($zgloszenie);

        $po = $this->htmlZgloszenia($zglaszajaca, $zgloszenie);

        $this->assertTrue($zgloszenie->refresh()->jestRozstrzygniete(), 'Sprawa nie przeszła w stan „po decyzji".');

        $galazDecyzja = $this->element(
            $po,
            "//article[h2[normalize-space()='Nasza decyzja']]",
            'Nie znalazłem bloku „Nasza decyzja" — gałąź „sprawa rozstrzygnięta" się nie wyrenderowała.',
        );

        $this->assertContains(
            'card',
            $this->klasy($galazDecyzja),
            'Gałąź „Nasza decyzja" zeszła z karty treści (D-128).',
        );

        // ---- niezmiennik: obie gałęzie to TA SAMA warstwa ----
        $this->assertSame(
            $this->klasy($galazCzeka),
            $this->klasy($galazDecyzja),
            'Gałęzie „Na czym stoi sprawa" i „Nasza decyzja" mają różne klasy, czyli ekran zmienia '
            .'wygląd zależnie od tego, czy sprawa jest rozstrzygnięta. To nie jest różnica rangi — '
            .'to jeden blok w dwóch stanach.',
        );
    }

    /**
     * KONTROLA, ŻE TEST WYŻEJ MIERZY WARSTWĘ, A NIE OBECNOŚĆ SŁOWA `card`.
     *
     * Sąsiednie bloki tego samego ekranu zostają SEKCJAMI: treść zgłoszenia
     * opisuje sprawę, a pouczenie DSA art. 16 ust. 5 jest środkiem prawnym
     * obok niej — żadne z nich nie jest odpowiedzią na sprawę (D-128: rolę
     * powierzchni nadaje miejsce). Gdyby ktoś podniósł na kartę cały ekran,
     * test wyżej dalej by przechodził, a ten oblewa.
     */
    #[Test]
    public function test_sasiednie_bloki_ekranu_zgloszenia_zostaja_sekcjami(): void
    {
        [$zglaszajaca, $zgloszenie] = $this->zgloszenieWTrakcie();

        $przed = $this->htmlZgloszenia($zglaszajaca, $zgloszenie);

        $this->assertSame(
            ['sekcja-strony'],
            $this->klasy($this->element(
                $przed,
                "//article[h2[normalize-space()='Treść zgłoszenia']]",
                'Nie znalazłem bloku „Treść zgłoszenia".',
            )),
            'Blok „Treść zgłoszenia" przestał być sekcją — opisuje sprawę, a nie jest odpowiedzią na nią.',
        );

        $this->rozstrzygnij($zgloszenie);

        $po = $this->htmlZgloszenia($zglaszajaca, $zgloszenie);

        $pouczenie = $this->element(
            $po,
            '//article[h2[normalize-space()='
            .$this->xpathTekst(OdpowiedzDlaZglaszajacego::NAGLOWEK_POUCZENIA)
            .']]',
            'Nie znalazłem bloku z pouczeniem DSA art. 16 ust. 5.',
        );

        $this->assertContains(
            'sekcja-strony',
            $this->klasy($pouczenie),
            'Pouczenie o dostępnych środkach zmieniło warstwę. Ma być sekcją: D-042 stawia je '
            .'w miejsce formularza skargi, ale odpowiedzią na sprawę jest blok wyżej.',
        );

        $this->assertNotContains('card', $this->klasy($pouczenie));
    }

    // =================================================================
    //  3. PUSTE KOLEJKI W PANELU — KOMPONENT, I ANI JEDNEGO MARTWEGO PRZYCISKU
    // =================================================================

    /**
     * PUSTA LISTA KONT MÓWI KOMPONENTEM PUSTEGO STANU, BEZ AKCJI.
     *
     * `<x-empty-state>` przyjmuje `action`/`href` i ktoś je kiedyś doda „żeby
     * nie było pusto" — a moderator nie zakłada kont za ludzi, więc każdy taki
     * przycisk byłby martwy (D-053). Asercja negatywna jest tu istotą rzeczy.
     */
    #[Test]
    public function test_pusta_lista_kont_to_komponent_bez_przycisku(): void
    {
        $moderator = $this->moderator();

        $html = $this->actingAs($moderator)
            ->get(route('admin.users', ['szukaj' => 'nie-ma-takiego-konta-zzz']))
            ->assertOk()
            ->getContent();

        $pusty = $this->element(
            $html,
            $this->xpathKlasy('empty-state'),
            'Pusta lista kont nie renderuje `<x-empty-state>` — wróciła do gołego akapitu?',
        );

        $this->assertBrakAkcji($pusty, 'Pusty stan listy kont');

        // KONTROLA DODATNIA: bez tego test przechodziłby także dla ekranu,
        // który ZAWSZE pokazuje „nic tu nie ma".
        $pelna = $this->actingAs($moderator)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertNull(
            $this->pierwszy($pelna, $this->xpathKlasy('empty-state')),
            'Lista kont pokazuje pusty stan, mimo że konta są. Test pustego stanu nie mierzyłby wtedy niczego.',
        );
    }

    /**
     * PUSTA KOLEJKA WIADOMOŚCI — TAK SAMO.
     *
     * Ten sam komponent co w „Użytkownikach" i w „Tagach promowanych": dwa
     * różne kształty pustego stanu w jednym panelu to dwie rzeczy do
     * nauczenia się zamiast jednej.
     */
    #[Test]
    public function test_pusta_kolejka_wiadomosci_to_komponent_bez_przycisku(): void
    {
        $moderator = $this->moderator();

        $html = $this->actingAs($moderator)
            ->get(route('admin.contact'))
            ->assertOk()
            ->getContent();

        $pusty = $this->element(
            $html,
            $this->xpathKlasy('empty-state'),
            'Pusta kolejka wiadomości nie renderuje `<x-empty-state>` — wróciła do gołego akapitu?',
        );

        $this->assertBrakAkcji($pusty, 'Pusty stan kolejki wiadomości');

        // KONTROLA DODATNIA: przy niepustej kolejce pustego stanu nie ma.
        ContactMessage::factory()->create(['status' => ContactMessage::STATUS_NOWA]);

        $pelna = $this->actingAs($moderator)
            ->get(route('admin.contact'))
            ->assertOk()
            ->getContent();

        $this->assertNull(
            $this->pierwszy($pelna, $this->xpathKlasy('empty-state')),
            'Kolejka wiadomości pokazuje pusty stan, mimo że wiadomość czeka.',
        );
    }

    // =================================================================
    //  Pomocnicze
    // =================================================================

    /** Konto z NAPRAWDĘ włączonym 2FA i jednym znanym kodem zapasowym. */
    private function kontoZ2fa(): User
    {
        $totp = app(TwoFactorAuthenticator::class);

        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $basia->beginTwoFactorSetup($totp->generateSecret());
        $basia->confirmTwoFactor($totp->hashBackupCodes(['ABCD-1234']));

        return $basia->refresh();
    }

    /**
     * Przechodzi pierwszy krok logowania i zwraca HTML ekranu wyzwania 2FA.
     *
     * Droga jest ta sama co w produkcji: hasło przez `/login`, potem GET na
     * ekran drugiego składnika. Niczego tu nie osłabiamy — konto ma
     * potwierdzone 2FA, a ekran wyzwania pokazuje się dlatego, że sesja
     * naprawdę czeka na drugi składnik.
     */
    private function ekranWyzwania2fa(?User $konto = null): string
    {
        $konto ??= $this->kontoZ2fa();

        $this->post('/login', ['login' => $konto->email, 'password' => 'haslo-testowe-123']);

        // Pierwszy krok NIE loguje — sesja czeka na drugi składnik. Gdyby tu
        // było inaczej, ekran wyzwania nie byłby tym, co mierzymy.
        $this->assertGuest();

        return $this->get(route('login.two_factor'))->assertOk()->getContent();
    }

    /**
     * Zgłoszenie złożone przez formularz „Zgłoś" i CZEKAJĄCE na decyzję.
     *
     * @return array{0: User, 1: Report}
     */
    private function zgloszenieWTrakcie(): array
    {
        $zglaszajaca = $this->user('halina');
        $autor = $this->user('krzysztof', ['display_name' => 'Krzysztof Zgłoszony']);

        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($zglaszajaca)
            ->post(
                route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]),
                ['reason' => 'harassment', 'details' => 'Nazywa mnie oszustką.'],
            )
            ->assertSessionHasNoErrors();

        $zgloszenie = Report::query()
            ->where('reporter_id', $zglaszajaca->getKey())
            ->latest('created_at')
            ->firstOrFail();

        return [$zglaszajaca, $zgloszenie];
    }

    /** Decyzja moderatora — przez trasę panelu, nie przez podmianę kolumny. */
    private function rozstrzygnij(Report $zgloszenie): void
    {
        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'harassment',
                'user_message' => 'Ukrywamy wpis, bo obraża konkretną osobę.',
                'suspend_days' => '7',
            ])
            ->assertSessionHasNoErrors();
    }

    private function htmlZgloszenia(User $zglaszajaca, Report $zgloszenie): string
    {
        return $this->actingAs($zglaszajaca)
            ->get(route('reports.mine.show', $zgloszenie))
            ->assertOk()
            ->getContent();
    }

    /**
     * Element wskazany wyrażeniem XPath — z asercją, że JEST DOKŁADNIE JEDEN.
     *
     * Brak elementu znaczy, że test nie ma czego mierzyć, a kilka elementów
     * znaczy, że mierzy przypadkowy z nich. Oba przypadki są tu błędem
     * i mają oblać z własnym komunikatem, a nie cicho przejść.
     */
    private function element(string $html, string $xpath, string $komunikat): DOMElement
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $trafienia = (new DOMXPath($dokument))->query($xpath);

        $this->assertNotFalse($trafienia, "Błędne wyrażenie XPath: {$xpath}");
        $this->assertSame(1, $trafienia->length, $komunikat." (trafień: {$trafienia->length})");

        $element = $trafienia->item(0);
        $this->assertInstanceOf(DOMElement::class, $element);

        return $element;
    }

    /** Pierwszy pasujący element albo `null` — do asercji „tego tu nie ma". */
    private function pierwszy(string $html, string $xpath): ?DOMElement
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $element = (new DOMXPath($dokument))->query($xpath)?->item(0);

        return $element instanceof DOMElement ? $element : null;
    }

    /**
     * Klasy elementu jako lista — bez `mt-5` i reszty odstępów.
     *
     * Odstępy zmieniają się przy każdym porządkowaniu układu i nie są
     * warstwą, a porównanie „obie gałęzie mają tę samą warstwę" ma dotyczyć
     * warstwy, nie marginesu.
     *
     * @return list<string>
     */
    private function klasy(DOMElement $element): array
    {
        $klasy = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        $klasy = array_values(array_filter(
            $klasy,
            // `^[mp][tblrxy]?-\d` — czyli `mt-5`, `mb-0`, `px-4`, `p-6`.
            // Wzorzec musi być WĄSKI: `^[mp][a-z]*-` zjadałby także
            // `panel-formularza`, czyli dokładnie to, czego test pilnuje.
            static fn (string $klasa): bool => $klasa !== '' && preg_match('/^[mp][tblrxy]?-\d/', $klasa) !== 1,
        ));

        sort($klasy);

        return $klasy;
    }

    /** XPath trafiający w element z podaną klasą — jako OSOBNYM słowem. */
    private function xpathKlasy(string $klasa): string
    {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' ".$klasa." ')]";
    }

    /** Literał XPath odporny na apostrofy w polskim tekście. */
    private function xpathTekst(string $tekst): string
    {
        if (! str_contains($tekst, "'")) {
            return "'".$tekst."'";
        }

        return 'concat('.implode(", \"'\", ", array_map(
            static fn (string $kawalek): string => "'".$kawalek."'",
            explode("'", $tekst),
        )).')';
    }

    /**
     * W tym elemencie nie ma żadnej akcji do kliknięcia (D-053).
     *
     * Sprawdzamy odnośnik ORAZ przycisk: `<x-empty-state>` robi dziś `<a>`,
     * ale martwy przycisk jest zakazany niezależnie od tego, którym znacznikiem
     * ktoś go kiedyś wstawi.
     */
    private function assertBrakAkcji(DOMElement $element, string $nazwa): void
    {
        $xpath = new DOMXPath($element->ownerDocument);

        foreach (['a' => 'odnośnik', 'button' => 'przycisk'] as $znacznik => $opis) {
            $this->assertSame(
                0,
                $xpath->query('.//'.$znacznik, $element)->length,
                "{$nazwa} dostał {$opis}. `<x-empty-state>` przyjmuje `action`/`href`, ale tu nie ma "
                .'sensownej akcji do zaproponowania — a martwy przycisk jest zakazany (D-053).',
            );
        }
    }
}
