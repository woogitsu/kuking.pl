<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use App\Mail\OdpowiedzNaWiadomosc;
use App\Models\AuditLogEntry;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Odpowiadanie na wiadomości z „Napisz do nas" WPROST Z PANELU (D-058).
 *
 * ZGŁOSZENIE, KTÓRE TO ZAMÓWIŁO, brzmiało: „widzę je, przychodzą, ale jak mam
 * odpisać? Nie ma nigdzie funkcji »odpisz osobie«, tylko notatka dla siebie".
 * Przedtem ekran miał odnośnik `mailto:` — odpisywało się więc z własnego
 * programu poczty, a w serwisie nie zostawał żaden ślad, że odpowiedź poszła.
 *
 * CZEGO TE TESTY PILNUJĄ NAJMOCNIEJ
 * Nie tego, że formularz istnieje (to widać na ekranie), a tego, że NIE
 * KŁAMIE. Przy odpowiedzi na wiadomość od człowieka cicha porażka poczty
 * (issue #234: `TransportException`, trzy próby, `failed_jobs`, cisza) jest
 * gorsza niż brak funkcji: moderator zobaczyłby „wysłano", zamknął sprawę
 * i przeszedł dalej, a osoba nigdy nie dostałaby odpowiedzi. Dlatego jeden
 * test wysyła list PRAWDZIWĄ drogą (transport EmailLabs z podstawionym
 * klientem HTTP), na której da się wymusić odmowę dostawcy.
 */
class OdpowiedzNaWiadomoscDoNasTest extends TestCase
{
    use RefreshDatabase;

    /** Adres API EmailLabs — podstawiamy pod niego odpowiedzi w teście porażki. */
    private const ADRES_API = 'https://api.emaillabs.io/v2.1/email';

    private const TRESC = 'Dzień dobry, przycisk Opublikuj poprawiliśmy dziś rano. Prosimy o sprawdzenie.';

    public function test_odpowiedz_naprawde_wychodzi_do_osoby_ktora_napisala(): void
    {
        Mail::fake();

        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->actingAs($this->moderator())
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC])
            ->assertRedirect(route('admin.contact.show', $wiadomosc))
            ->assertSessionHas('status');

        Mail::assertSent(OdpowiedzNaWiadomosc::class, function (OdpowiedzNaWiadomosc $list): bool {
            $this->assertTrue($list->hasTo('basia@wp.pl'), 'List poszedł nie na ten adres, na który człowiek prosił o odpowiedź.');
            $this->assertStringContainsString(self::TRESC, $list->render());

            return true;
        });
    }

    /**
     * NADAWCĄ JEST SERWIS, NIE MODERATOR — a `Reply-To` prowadzi na skrzynkę,
     * którą ktoś naprawdę czyta.
     *
     * To jest wymóg, nie kosmetyka. Osoba z zewnątrz nie ma dostać listu
     * z prywatnego adresu moderatora: pisała do serwisu, po drugie moderator
     * ma prawo do własnej skrzynki bez cudzej korespondencji, po trzecie lista
     * moderatorów nie jest informacją publiczną.
     */
    public function test_nadawca_to_serwis_a_odpowiedz_wraca_na_skrzynke_kontaktowa(): void
    {
        Mail::fake();

        $moderator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->actingAs($moderator)
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC])
            ->assertRedirect(route('admin.contact.show', $wiadomosc));

        Mail::assertSent(OdpowiedzNaWiadomosc::class, function (OdpowiedzNaWiadomosc $list) use ($moderator): bool {
            $this->assertTrue(
                $list->hasFrom((string) config('mail.from.address')),
                'Nadawcą listu musi być serwis.',
            );
            $this->assertFalse(
                $list->hasFrom($moderator->email),
                'Prywatny adres moderatora wyszedł na zewnątrz jako nadawca.',
            );
            $this->assertTrue(
                $list->hasReplyTo((string) config('kuking.community.contact_email')),
                'Reply-To musi prowadzić na skrzynkę, którą ktoś czyta — inaczej odpowiedź człowieka nie dojdzie nigdzie.',
            );
            $this->assertFalse(
                $list->hasReplyTo($moderator->email),
                'Odpowiedź człowieka trafiłaby do prywatnej skrzynki moderatora.',
            );

            return true;
        });
    }

    public function test_odpowiedz_jest_zapisana_przy_wiadomosci_i_widoczna_po_odswiezeniu(): void
    {
        Mail::fake();

        $moderator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->actingAs($moderator)
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC]);

        $odpowiedz = $wiadomosc->odpowiedzi()->sole();

        $this->assertSame(ContactMessageReply::STATUS_WYSLANA, $odpowiedz->status);
        $this->assertSame($moderator->getKey(), $odpowiedz->author_id);
        $this->assertNotNull(
            $odpowiedz->sent_at,
            'Bez `sent_at` „wysłana" znaczyłoby „poszło, ale nie wiadomo kiedy" — czyli dokładnie tę ciszę, '
            .'którą ta funkcja ma usunąć.',
        );

        // ŚWIEŻE ŻĄDANIE, nie ta sama odpowiedź HTTP: pytanie brzmi „czy to
        // zostało w bazie", a nie „czy kontroler oddał to, co dostał".
        $this->actingAs($moderator)
            ->get(route('admin.contact.show', $wiadomosc))
            ->assertOk()
            ->assertSee(self::TRESC, false)
            ->assertSee('Wysłana', false);
    }

    /**
     * NIEUDANA WYSYŁKA NIE MELDUJE SUKCESU I NIE GUBI WPISANEJ TREŚCI.
     *
     * Najważniejszy test w tym pliku. Idzie PRAWDZIWĄ drogą wysyłki
     * (`App\Poczta\TransportEmailLabs`) z podstawionym klientem HTTP, bo
     * `Mail::fake()` nie umie odmówić — a odmowa dostawcy jest tu jedynym
     * scenariuszem, w którym da się skrzywdzić człowieka po drugiej stronie.
     */
    public function test_nieudana_wysylka_nie_melduje_sukcesu_i_nie_gubi_wpisanej_tresci(): void
    {
        $this->pocztaOdmawia();

        $moderator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $odpowiedzHttp = $this->actingAs($moderator)
            ->from(route('admin.contact.show', $wiadomosc))
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => $key = (string) Str::uuid(), 'odpowiedz' => self::TRESC]);

        $odpowiedzHttp
            ->assertRedirect(route('admin.contact.show', $wiadomosc))
            ->assertSessionHasErrors('odpowiedz')
            // Brak „status" w sesji = brak zielonego komunikatu „wysłano".
            ->assertSessionMissing('status')
            // POPRAWNIE WPISANE DANE NIGDY NIE ZNIKAJĄ (docs/UX_50_PLUS.md).
            ->assertSessionHasInput('odpowiedz', self::TRESC);

        $odpowiedz = $wiadomosc->odpowiedzi()->sole();

        $this->assertSame(ContactMessageReply::STATUS_NIEUDANA, $odpowiedz->status);
        $this->assertNull($odpowiedz->sent_at, 'List, który nie wyszedł, nie ma prawa mieć godziny wysłania.');
        $this->assertSame(self::TRESC, $odpowiedz->body, 'Napisany tekst musi zostać zapisany także wtedy, gdy wysyłka padła.');
        $this->assertNotNull($odpowiedz->error);

        // ADRES ODBIORCY NIE MA PRAWA WYJŚĆ W POWODZIE ODMOWY (audyt A6-01):
        // ten łańcuch idzie do bazy i na ekran, a przy wiadomości od gościa
        // jest to adres osoby, która nie ma nawet konta.
        $this->assertStringNotContainsString('basia@wp.pl', (string) $odpowiedz->error);

        // Ekran mówi wprost, co się stało i co zrobić.
        $this->actingAs($moderator)
            ->get(route('admin.contact.show', $wiadomosc))
            ->assertOk()
            ->assertSee('Ta wiadomość NIE wyszła', false)
            ->assertDontSee('Poczta przyjęła ten list', false);

        $this->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => $key, 'odpowiedz' => self::TRESC])
            ->assertSessionHasErrors('odpowiedz');
        Http::assertSentCount(1);
        $this->assertSame(1, $wiadomosc->odpowiedzi()->count());
        $this->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC])
            ->assertSessionHasErrors('odpowiedz');
        Http::assertSentCount(2);
        $this->assertSame(2, $wiadomosc->odpowiedzi()->count());
    }

    /**
     * POWÓD ODMOWY NIE WYNOSI ADRESU ODBIORCY (audyt A6-01).
     *
     * `OdmowaEmailLabs` jest zredagowana już u źródła, więc tamtą drogą adres
     * nie wyjdzie — ale to nie jest jedyny wyjątek, który tu doleci. Symfony
     * rzuca własnymi przy adresie, którego nie umie sparsować, i wypisuje go
     * w komunikacie WPROST. Ten łańcuch idzie do bazy i na ekran, więc
     * redakcja musi stać także na wyjściu z naszej akcji, nie tylko
     * w transporcie dostawcy.
     *
     * Podstawiamy transport, który rzuca dokładnie takim komunikatem —
     * inaczej ten warunek byłby nie do sprawdzenia (i przez chwilę był:
     * kontrola ujemna na samej redakcji przechodziła, bo droga EmailLabs
     * czyści adres wcześniej).
     */
    public function test_powod_odmowy_nie_wynosi_adresu_odbiorcy(): void
    {
        $this->pocztaRzucaZAdresemWKomunikacie();

        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->actingAs($this->moderator())
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC])
            ->assertSessionHasErrors('odpowiedz');

        $powod = (string) $wiadomosc->odpowiedzi()->sole()->error;

        $this->assertStringNotContainsString('basia@wp.pl', $powod);
        $this->assertStringContainsString('[adres]', $powod);
    }

    /** Wysłanie zostawia ślad w dzienniku — i nie przepisuje tam cudzych danych. */
    public function test_wyslanie_zostawia_wpis_w_dzienniku_audytu(): void
    {
        Mail::fake();

        $moderator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->actingAs($moderator)
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC]);

        $wpis = AuditLogEntry::query()->where('action', 'admin.contact_reply_sent')->sole();

        $this->assertSame($moderator->getKey(), $wpis->actor_id);
        $this->assertSame($wiadomosc->getKey(), $wpis->subject_id);
        $this->assertSame('ContactMessage', $wpis->subject_type);

        $metadane = json_encode($wpis->metadata, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('basia@wp.pl', (string) $metadane, 'Adres nie ma prawa trafić do dziennika.');
        $this->assertStringNotContainsString(self::TRESC, (string) $metadane, 'Dziennik zapisuje FAKT i AKTORA, nigdy treści.');
    }

    /** Nieudana próba też zostawia ślad — „nikt mi nie odpisał" musi mieć odpowiedź. */
    public function test_nieudana_wysylka_tez_zostawia_wpis_w_dzienniku_audytu(): void
    {
        $this->pocztaOdmawia();

        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->actingAs($this->moderator())
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC]);

        $this->assertDatabaseHas('audit_log', ['action' => 'admin.contact_reply_failed']);
        $this->assertDatabaseMissing('audit_log', ['action' => 'admin.contact_reply_sent']);
    }

    /** Wiadomość od osoby BEZ KONTA — najczęstszy przypadek na tej kolejce (D-045). */
    public function test_wiadomosc_od_osoby_bez_konta_tez_da_sie_obsluzyc(): void
    {
        Mail::fake();

        $wiadomosc = ContactMessage::factory()->create([
            'user_id' => null,
            'contact_email' => 'ktos@onet.pl',
        ]);

        $this->actingAs($this->moderator())
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC])
            ->assertSessionHas('status');

        Mail::assertSent(OdpowiedzNaWiadomosc::class, fn (OdpowiedzNaWiadomosc $list): bool => $list->hasTo('ktos@onet.pl'));
    }

    /** Wiadomość od ZALOGOWANEGO idzie na adres z konta — formularz go nie pyta. */
    public function test_odpowiedz_dla_zalogowanego_idzie_na_adres_z_konta(): void
    {
        Mail::fake();

        $basia = $this->user('basia', ['email' => 'basia.z.konta@wp.pl']);
        $wiadomosc = ContactMessage::factory()->odZalogowanego($basia)->create();

        $this->actingAs($this->moderator())
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC])
            ->assertSessionHas('status');

        Mail::assertSent(OdpowiedzNaWiadomosc::class, fn (OdpowiedzNaWiadomosc $list): bool => $list->hasTo('basia.z.konta@wp.pl'));
    }

    /**
     * Bez adresu nie ma formularza ANI wysyłki — i ekran mówi, dlaczego.
     *
     * Gość może wysłać wiadomość bez adresu (pole jest nieobowiązkowe), a konto
     * autora mogło zostać wymazane. `EraseAccountData` wpisuje wtedy w `email`
     * adres z naszej domeny technicznej, pod którym nie ma skrzynki — wysyłka
     * tam zameldowałaby sukces nad listem, którego nikt nie przeczyta.
     */
    public function test_bez_adresu_nie_da_sie_wyslac_i_nic_nie_wychodzi(): void
    {
        Mail::fake();

        $wiadomosc = ContactMessage::factory()->create([
            'user_id' => null,
            'contact_email' => null,
        ]);

        $this->actingAs($this->moderator())
            ->get(route('admin.contact.show', $wiadomosc))
            ->assertOk()
            ->assertSee('Nie ma jak odpisać tej osobie', false)
            ->assertDontSee(route('admin.contact.reply', $wiadomosc), false);

        $this->actingAs($this->moderator())
            ->from(route('admin.contact.show', $wiadomosc))
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC])
            ->assertSessionHasErrors('odpowiedz')
            ->assertSessionHasInput('odpowiedz', self::TRESC);

        Mail::assertNothingSent();
        $this->assertSame(0, $wiadomosc->odpowiedzi()->count());
    }

    public function test_konto_wymazane_nie_ma_adresu_do_odpowiedzi(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@wp.pl']);
        $wiadomosc = ContactMessage::factory()->odZalogowanego($basia)->create();

        $this->assertSame('basia@wp.pl', $wiadomosc->adresDoOdpowiedzi());

        $basia->forceFill(['status' => User::STATUS_ERASED, 'data_erased_at' => now()])->save();

        $this->assertNull(
            $wiadomosc->fresh()->adresDoOdpowiedzi(),
            'Adres konta wymazanego (`usuniete+…@konto.kuking.pl`) nie prowadzi do żadnej skrzynki.',
        );
    }

    /** Puste pole nie wysyła listu bez treści i mówi, co zrobić. */
    public function test_pusta_odpowiedz_nie_wychodzi(): void
    {
        Mail::fake();

        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->actingAs($this->moderator())
            ->from(route('admin.contact.show', $wiadomosc))
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => '   '])
            ->assertSessionHasErrors(['odpowiedz' => 'Napisz odpowiedź, zanim ją wyślesz.']);

        Mail::assertNothingSent();
    }

    /**
     * DWIE ODPOWIEDZI, DWA ŚLADY — granica decyzji o „wątku" (D-058).
     *
     * Panel pokazuje to, co wyszło Z NIEGO, i nie nadpisuje pierwszej
     * odpowiedzi drugą. Pełnego wątku nie ma i nie udajemy, że jest:
     * odpowiedź człowieka wraca na skrzynkę `kontakt@kuking.pl`.
     */
    public function test_druga_odpowiedz_nie_nadpisuje_pierwszej(): void
    {
        Mail::fake();

        $moderator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->actingAs($moderator)->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => 'Sprawdzamy, damy znać.']);
        $this->actingAs($moderator)->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => 'Poprawione, dziękujemy za sygnał.']);

        $this->assertSame(2, $wiadomosc->odpowiedzi()->count());

        $this->actingAs($moderator)
            ->get(route('admin.contact.show', $wiadomosc))
            ->assertOk()
            ->assertSee('Sprawdzamy, damy znać.', false)
            ->assertSee('Poprawione, dziękujemy za sygnał.', false);
    }

    /** Wysłanie odpowiedzi ŚWIADOMIE nie rusza stanu ani zegara retencji. */
    public function test_odpowiedz_nie_zmienia_stanu_wiadomosci(): void
    {
        Mail::fake();

        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->actingAs($this->moderator())
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC]);

        $wiadomosc->refresh();

        $this->assertSame(ContactMessage::STATUS_NOWA, $wiadomosc->status);
        $this->assertNull($wiadomosc->handled_at, 'Odpowiedź nie ma prawa uruchomić zegara retencji za moderatora.');
    }

    public function test_gosc_nie_odpisze(): void
    {
        Mail::fake();

        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC])
            ->assertRedirect(route('login'));

        Mail::assertNothingSent();
    }

    public function test_zwykly_uzytkownik_nie_odpisze(): void
    {
        Mail::fake();

        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        // 404, nie 403 — panel moderacji nie potwierdza nikomu, że istnieje.
        $this->actingAs($this->user('basia'))
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC])
            ->assertNotFound();

        Mail::assertNothingSent();
        $this->assertSame(0, $wiadomosc->odpowiedzi()->count());
    }

    /**
     * Polityka odmawia NIEZALEŻNIE OD TRASY — middleware pilnuje wejścia do
     * panelu, nie prawa do wiersza (AGENTS.md §7). `reply` jest osobną
     * zdolnością niż `handle`, bo wysłanie listu na czyjś adres nie jest tym
     * samym co przestawienie stanu w naszej kolejce.
     */
    public function test_polityka_pilnuje_wysylania_odpowiedzi(): void
    {
        $wiadomosc = ContactMessage::factory()->create();

        $this->assertFalse(Gate::forUser($this->user('basia'))->allows('reply', $wiadomosc));
        $this->assertTrue(Gate::forUser($this->moderator())->allows('reply', $wiadomosc));
    }

    /**
     * ODPOWIEDZI GINĄ RAZEM Z WIADOMOŚCIĄ (RODO art. 17 i retencja z D-045).
     *
     * Retencja robi masowy `DELETE` na `contact_messages` i omija modele, więc
     * kaskada MUSI być w bazie. Bez niej treść listu wysłanego w konkretnej
     * sprawie zostałaby w tabeli jako sierota — bez wiadomości, do której
     * należała, i bez niczego, co by ją kiedykolwiek usunęło.
     */
    public function test_odpowiedzi_znikaja_razem_z_wiadomoscia_przy_retencji(): void
    {
        Mail::fake();

        $moderator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->actingAs($moderator)
            ->post(route('admin.contact.reply', $wiadomosc), ['reply_key' => (string) Str::uuid(), 'odpowiedz' => self::TRESC]);

        $wiadomosc->oznaczJako(ContactMessage::STATUS_ZALATWIONA, $moderator);
        $wiadomosc->forceFill(['handled_at' => now()->subMonths(13)])->save();

        $usuniete = (new PrzedawnioneWiadomosciDoOperatora)->posprzataj(12);

        $this->assertSame(1, $usuniete);
        $this->assertDatabaseCount('contact_messages', 0);
        $this->assertDatabaseCount('contact_message_replies', 0);
    }

    /**
     * Transport, który wywraca wysyłkę komunikatem NIOSĄCYM ADRES ODBIORCY —
     * dokładnie tak, jak robi to Symfony przy adresie niezgodnym z RFC 2822.
     */
    private function pocztaRzucaZAdresemWKomunikacie(): void
    {
        Mail::extend('rzucajacy', fn (): AbstractTransport => new class extends AbstractTransport
        {
            public function __toString(): string
            {
                return 'rzucajacy://test';
            }

            protected function doSend(SentMessage $wiadomosc): void
            {
                throw new TransportException(
                    'Address "basia@wp.pl" does not comply with addr-spec of RFC 2822',
                );
            }
        });

        config([
            'mail.default' => 'rzucajacy',
            'mail.mailers.rzucajacy' => ['transport' => 'rzucajacy'],
        ]);

        Mail::purge('rzucajacy');
    }

    /**
     * Prawdziwa droga wysyłki z odmową dostawcy.
     *
     * `Mail::fake()` nie umie odmówić, a odmowa jest tu jedynym scenariuszem,
     * który potrafi skrzywdzić człowieka po drugiej stronie. Podstawiamy więc
     * klienta HTTP pod transport EmailLabs — dokładnie tak, jak robi to
     * `PocztaPrzezApiEmailLabsTest`.
     */
    private function pocztaOdmawia(): void
    {
        config([
            'mail.default' => 'emaillabs',
            'services.emaillabs.key' => 'klucz-aplikacji-do-testu',
            'services.emaillabs.secret' => 'klucz-autoryzacyjny-do-testu',
            'services.emaillabs.smtp_account' => '1.kuking.smtp',
            'services.emaillabs.endpoint' => self::ADRES_API,
            'services.emaillabs.tracking' => false,
        ]);

        // Konfiguracja mailera jest zapamiętywana po pierwszym użyciu.
        Mail::purge('emaillabs');

        Http::fake([
            self::ADRES_API => Http::response([
                'meta' => ['numberOfErrors' => 1, 'numberOfData' => 0, 'status' => 400, 'uniqId' => 'blad12345'],
                'errors' => [[
                    'code' => 'E-0-004',
                    'title' => 'Invalid parameter',
                    // Dostawca potrafi wstawić w „details" wartość parametru —
                    // przy odrzuconym odbiorcy byłby to jego adres. Test
                    // sprawdza, że stąd nie wyjdzie.
                    'message' => 'Field to is invalid: basia@wp.pl',
                ]],
            ], 400),
        ]);
    }
}
