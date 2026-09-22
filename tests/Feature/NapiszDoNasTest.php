<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * „Napisz do nas" — droga podstawowa, ta która MUSI działać bez JavaScriptu.
 *
 * CO TU JEST SPRAWDZANE, A CO NIE
 * Nie to, że formularz „ładnie wygląda" — tego nie sprawdzi asercja. Sprawdzane
 * są niezmienniki, które łatwo złamać przy następnej zmianie:
 *
 *  - strona jest osiągalna BEZ KONTA (bo najczęstsza wiadomość na starcie
 *    brzmi „nie mogę się zalogować"),
 *  - to jest zwykły formularz POST z tokenem CSRF, bez ani jednego atrybutu
 *    `wire:` i bez `x-on:` — czyli działa z samego HTML-a,
 *  - poprawnie napisany tekst NIE ZNIKA po nieudanej walidacji (AGENTS.md §5),
 *  - `status` nie da się ustawić z formularza (AGENTS.md §7),
 *  - podwójne kliknięcie „Wyślij" daje JEDNĄ wiadomość, nie dwie (D-027).
 */
class NapiszDoNasTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    private function poprawneDane(array $nadpisz = []): array
    {
        return array_merge([
            'kind' => ContactMessage::KIND_BLAD,
            'message' => 'Nie mogę wgrać zdjęcia z telefonu, po kliknięciu Opublikuj nic się nie dzieje.',
            'contact_email' => 'basia@example.com',
        ], $nadpisz);
    }

    public function test_gosc_bez_konta_otwiera_formularz(): void
    {
        $odpowiedz = $this->get(route('kontakt'))->assertOk();

        // NA TREŚCI EKRANU, NIE NA CAŁYM DOKUMENCIE (pułapka 1).
        // „Napisz do nas" stoi na tej stronie także w `<title>`, w czterech
        // `<meta>` i w odnośniku STOPKI — a stopka jest na każdym ekranie.
        // Zmierzone: po skasowaniu nagłówka `<h1>` asercja na całej odpowiedzi
        // dalej przechodziła.
        $tresc = $this->trescEkranu((string) $odpowiedz->getContent());

        $this->assertStringContainsString('Napisz do nas', $tresc);
        $this->assertStringContainsString('action="'.route('kontakt.store').'"', $tresc);
    }

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU.
     *
     * Formularz kontaktowy jest drogą dla człowieka, któremu coś nie działa —
     * a bardzo często „nie działa" znaczy „nie dociągnął się skrypt". Gdyby
     * wysyłka zależała od JavaScriptu, funkcja psułaby się dokładnie wtedy,
     * kiedy jest potrzebna.
     *
     * Sprawdzamy to na renderze, nie na deklaracji: w HTML-u formularza nie
     * ma być ANI JEDNEGO atrybutu Livewire'a ani Alpine'a, a strona nie ma
     * ciągnąć skryptu Livewire'a (`:livewire` w layoucie zostaje domyślnie
     * wyłączone).
     */
    public function test_formularz_dziala_bez_javascriptu(): void
    {
        $html = $this->get(route('kontakt'))->assertOk()->getContent();

        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);

        foreach (['wire:model', 'wire:submit', 'wire:click', 'x-on:', '@click', 'livewire.js'] as $slad) {
            $this->assertStringNotContainsString(
                $slad,
                $html,
                'Formularz „Napisz do nas" niesie „'.$slad.'" — czyli zaczął zależeć od skryptu. '
                .'To jest droga dla kogoś, komu właśnie coś nie działa; ma działać z samego HTML-a.',
            );
        }
    }

    public function test_gosc_wysyla_wiadomosc_i_widzi_potwierdzenie(): void
    {
        $odpowiedz = $this->post(route('kontakt.store'), $this->poprawneDane());

        $odpowiedz->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');

        $wiadomosc = ContactMessage::sole();

        $this->assertSame(ContactMessage::KIND_BLAD, $wiadomosc->kind);
        $this->assertSame('basia@example.com', $wiadomosc->contact_email);
        $this->assertNull($wiadomosc->user_id);
        $this->assertSame(ContactMessage::STATUS_NOWA, $wiadomosc->status);

        // Potwierdzenie ma powiedzieć, NA JAKI ADRES przyjdzie odpowiedź.
        // „Dziękujemy" bez tego zostawia człowieka z pytaniem, czy w ogóle
        // ma na co czekać.
        $this->followingRedirects()
            ->post(route('kontakt.store'), $this->poprawneDane(['message' => 'Druga wiadomość, zupełnie inna treść.']))
            ->assertOk()
            ->assertSee('basia@example.com');
    }

    public function test_zalogowany_nie_podaje_adresu_bo_jest_na_koncie(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        $this->actingAs($basia)
            ->post(route('kontakt.store'), $this->poprawneDane(['contact_email' => null]))
            ->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');

        $wiadomosc = ContactMessage::sole();

        $this->assertSame($basia->getKey(), $wiadomosc->user_id);
        $this->assertNull(
            $wiadomosc->contact_email,
            'Adres zalogowanego skopiował się do drugiej tabeli. To jest powielanie danych '
            .'osobowych bez powodu — adres jest na koncie i stamtąd go czytamy.',
        );
        $this->assertSame('basia@example.com', $wiadomosc->adresDoOdpowiedzi());
    }

    /**
     * Podstawiony adres od ZALOGOWANEGO ma zostać po cichu pominięty, a nie
     * zapisany obok konta ani zamieniony w błąd walidacji.
     */
    public function test_adres_podstawiony_przez_zalogowanego_nie_trafia_do_bazy(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        $this->actingAs($basia)
            ->post(route('kontakt.store'), $this->poprawneDane(['contact_email' => 'ktos.inny@example.com']))
            ->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');

        $this->assertNull(ContactMessage::sole()->contact_email);
    }

    public function test_pusta_wiadomosc_nie_przechodzi_a_reszta_nie_znika(): void
    {
        $odpowiedz = $this->from(route('kontakt'))->post(route('kontakt.store'), [
            'kind' => ContactMessage::KIND_POMYSL,
            'message' => '',
            'contact_email' => 'basia@example.com',
        ]);

        $odpowiedz->assertRedirect(route('kontakt'));
        $odpowiedz->assertSessionHasErrors('message');
        $this->assertSame(0, ContactMessage::count());

        // POPRAWNE DANE NIGDY NIE ZNIKAJĄ (AGENTS.md §5). Adres i wybrany
        // rodzaj mają wrócić do formularza — inaczej po literówce w jednym
        // polu trzeba wypełniać wszystko od nowa.
        $odpowiedz->assertSessionHasInput('contact_email', 'basia@example.com');
        $odpowiedz->assertSessionHasInput('kind', ContactMessage::KIND_POMYSL);
    }

    /**
     * Najdotkliwszy przypadek utraty danych: człowiek napisał długi opis
     * awarii i pomylił się w adresie e-mail. Tekst MUSI zostać w polu.
     */
    public function test_dlugi_opis_zostaje_w_polu_po_bledzie_w_adresie(): void
    {
        $opis = 'Od wczoraj nie mogę dodać zdjęcia. Wybieram plik z galerii, '
            .'klikam Opublikuj i strona się przewija do góry, a zdjęcia nie ma.';

        $odpowiedz = $this->from(route('kontakt'))->post(route('kontakt.store'), [
            'kind' => ContactMessage::KIND_BLAD,
            'message' => $opis,
            'contact_email' => 'basia-bez-malpy',
        ]);

        $odpowiedz->assertSessionHasErrors('contact_email');
        $odpowiedz->assertSessionHasInput('message', $opis);

        // I naprawdę wraca na EKRAN, nie tylko do sesji.
        $this->followingRedirects()
            ->from(route('kontakt'))
            ->post(route('kontakt.store'), [
                'kind' => ContactMessage::KIND_BLAD,
                'message' => $opis,
                'contact_email' => 'basia-bez-malpy',
            ])
            ->assertOk()
            ->assertSee($opis, false);
    }

    public function test_komunikaty_bledow_sa_po_polsku_i_mowia_co_zrobic(): void
    {
        $this->from(route('kontakt'))
            ->post(route('kontakt.store'), ['message' => 'Coś tu nie gra u Was od wczoraj.'])
            ->assertSessionHasErrors('kind');

        $komunikat = session('errors')->first('kind');

        $this->assertStringContainsString('Zaznacz', $komunikat);
        $this->assertStringNotContainsString('field', $komunikat);
        $this->assertStringNotContainsString('validation.', $komunikat);
    }

    /**
     * `status` NIE JEST W `$fillable` — ta sama zasada, co dla `status`
     * i `role` użytkownika (AGENTS.md §7). Bez tego wystarczyłoby dopisać
     * jedno pole do żądania POST, żeby wiadomość wpadła do bazy jako
     * załatwiona i nigdy nie pojawiła się w kolejce.
     */
    public function test_nie_da_sie_ustawic_statusu_z_formularza(): void
    {
        $this->post(route('kontakt.store'), $this->poprawneDane([
            'status' => ContactMessage::STATUS_ZALATWIONA,
            'handled_at' => now()->toDateTimeString(),
        ]));

        $wiadomosc = ContactMessage::sole();

        $this->assertSame(ContactMessage::STATUS_NOWA, $wiadomosc->status);
        $this->assertNull($wiadomosc->handled_at);
        $this->assertNull($wiadomosc->handled_by);
    }

    public function test_podwojne_klikniecie_daje_jedna_wiadomosc(): void
    {
        $klucz = (string) Str::uuid7();
        $dane = $this->poprawneDane(['klucz_wyslania' => $klucz]);

        $this->post(route('kontakt.store'), $dane)->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');
        $this->post(route('kontakt.store'), $dane)->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');

        $this->assertSame(
            1,
            ContactMessage::count(),
            'Dwa kliknięcia „Wyślij" założyły dwie wiadomości. Przy jednoosobowej obsłudze '
            .'to jest ta sama praca do wykonania dwa razy (D-027).',
        );
    }

    /**
     * Adres strony, z której człowiek pisał, to połowa diagnozy przy „coś nie
     * działa" — ale wolno zapisać WYŁĄCZNIE ścieżkę z naszego serwisu.
     */
    public function test_zapisujemy_sciezke_z_naszego_serwisu_a_cudzy_adres_pomijamy(): void
    {
        $this->post(route('kontakt.store'), $this->poprawneDane([
            'page_path' => '/przepisy/rosol-babci?skad=newsletter',
        ]));

        $this->assertSame('/przepisy/rosol-babci', ContactMessage::sole()->page_path);

        ContactMessage::query()->delete();

        $this->post(route('kontakt.store'), $this->poprawneDane([
            'message' => 'Zupełnie inna wiadomość, żeby nie wpaść w idempotencję.',
            'page_path' => 'https://wyszukiwarka.example.com/szukaj?q=kuking+nie+dziala',
        ]));

        $this->assertNull(
            ContactMessage::sole()->page_path,
            'Do bazy trafił adres z CUDZEGO serwisu. To jest informacja o człowieku, '
            .'nie o naszej awarii.',
        );
    }

    public function test_formularz_odsyla_do_zglaszania_tresci_zamiast_je_zastepowac(): void
    {
        $html = $this->get(route('kontakt'))->assertOk()->getContent();

        $this->assertStringContainsString(route('zglos.nielegalna'), $html);
        $this->assertStringContainsString('Zgłoś', $html);
    }

    public function test_napisz_do_nas_jest_w_stopce_kazdej_strony(): void
    {
        // Gość — strona powitalna.
        $this->get(route('landing'))->assertOk()->assertSee(route('kontakt'), false);

        // Zalogowany — tablica.
        $this->actingAs($this->user('basia'))
            ->get(route('home'))
            ->assertOk()
            ->assertSee(route('kontakt'), false);
    }

    public function test_trasa_wysylki_ma_limit_zapytan(): void
    {
        $srodkowe = collect(app('router')->getRoutes()->getByName('kontakt.store')->gatherMiddleware())
            ->filter(fn (string $m): bool => str_starts_with($m, 'throttle:'))
            ->values();

        $this->assertNotEmpty(
            $srodkowe,
            'Trasa `kontakt.store` nie ma limitu zapytań. To jedyny zapis do bazy '
            .'osiągalny bez konta poza formularzami zgłoszeń — limit jest tu jedyną ochroną.',
        );

        $this->assertStringContainsString(
            (string) config('kuking.limits.kontakt'),
            $srodkowe->first(),
            'Limit nie pochodzi z `config/kuking.php` — AGENTS.md §7 wymaga jednego miejsca na progi.',
        );
    }
}
