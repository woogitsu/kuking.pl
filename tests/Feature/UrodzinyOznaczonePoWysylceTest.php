<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * `users.birthday_email_sent_on` znaczy „list wyszedł”, nie „zakolejkowaliśmy”
 * (issue #1956) — ten sam wzorzec co D-293 (`DecyzjaZgloszeniaOznaczanaPoWysylceTest`).
 *
 * Do 26 września 2026 `kuking:wyslij-zyczenia-urodzinowe` stawiało znacznik
 * zaraz po `Mail::queue()`, a `ZyczeniaUrodzinowe` jest `ShouldQueue` — więc
 * znacznik powstawał, zanim worker w ogóle spróbował wysłać list. Worker mógł
 * potem wyczerpać próby, a kolumna dalej twierdziła, że list dotarł — a to
 * jest JEDYNY list w roku dla tej osoby.
 *
 * Testy idą PRAWDZIWĄ kolejką `database` i prawdziwym `queue:work`, nie
 * `Mail::fake()` — fałszywka nie wykonuje `ZyczeniaUrodzinowe::send()`, więc
 * nie umiałaby odróżnić zakolejkowania od wysyłki, a właśnie o to tu chodzi.
 */
class UrodzinyOznaczonePoWysylceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $dziennik = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::extend('odmawia', fn (array $config): AbstractTransport => new class extends AbstractTransport
        {
            public function __toString(): string
            {
                return 'odmawia://';
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Dostawca nie przyjął wiadomości.');
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Dostawca nie przyjął wiadomości.');
            }
        });

        config([
            'queue.default' => 'database',
            'mail.default' => 'array',
            'mail.mailers.odmawia' => ['transport' => 'odmawia'],
            'kuking.urodziny.mail_wlaczony' => true,
            'kuking.urodziny.mail_dzienny_sufit' => 20,
        ]);

        // 12 marca 2026, 08:40 UTC — pora z harmonogramu.
        $this->travelTo(Carbon::parse('2026-03-12 08:40:00', 'UTC'));

        Event::listen(MessageLogged::class, function (MessageLogged $wpis): void {
            $this->dziennik[] = $wpis;
        });
    }

    private function osoba(string $nazwa): User
    {
        $osoba = $this->user($nazwa, ['display_name' => ucfirst($nazwa)]);
        $osoba->forceFill([
            'birthday_day' => 12,
            'birthday_month' => 3,
            'wants_birthday_email' => true,
        ])->save();

        return $osoba->fresh();
    }

    private function wyslij(): string
    {
        Artisan::call('kuking:wyslij-zyczenia-urodzinowe');

        return Artisan::output();
    }

    private function przepracuj(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--once' => true,
            '--tries' => 1,
        ]);
    }

    /** @return list<SentMessage> */
    private function wyslaneListy(): array
    {
        /** @var ArrayTransport $transport */
        $transport = Mail::mailer('array')->getSymfonyTransport();

        return $transport->messages()->values()->all();
    }

    /**
     * SEDNO. Po komendzie list czeka w kolejce, `birthday_email_queued_on`
     * jest ustawione (bariera przed dublem), a `birthday_email_sent_on` jest
     * PUSTE — dopiero worker, który list wysłał, stawia ten drugi znacznik.
     */
    public function test_znacznik_wyslania_stoi_dopiero_po_wyslaniu_a_nie_po_zakolejkowaniu(): void
    {
        $basia = $this->osoba('basia');

        $this->wyslij();

        $this->assertSame(1, DB::table('jobs')->count(), 'Komenda ma zostawić dokładnie jedno zadanie z listem.');
        $this->assertSame('2026-03-12', $basia->refresh()->birthday_email_queued_on?->toDateString());
        $this->assertNull(
            $basia->refresh()->birthday_email_sent_on,
            'Samo zakolejkowanie listu nie jest wysłaniem go (#1956).',
        );

        $this->przepracuj();

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertCount(1, $this->wyslaneListy(), 'Worker miał wysłać jeden list z życzeniami.');
        $this->assertSame(
            '2026-03-12',
            $basia->refresh()->birthday_email_sent_on?->toDateString(),
            'Udana wysyłka ma postawić znacznik.',
        );
    }

    /**
     * Worker wyczerpał próby: `birthday_email_sent_on` zostaje puste, a dziennik
     * mówi, KTÓREGO konta to dotyczy — bez adresu.
     */
    public function test_ostateczna_porazka_zostawia_konto_bez_znacznika_wyslania_i_slad_w_dzienniku(): void
    {
        $basia = $this->osoba('basia');

        // Mailer „odmawia" ustawiony PRZED zakolejkowaniem: `Mailer::queue()`
        // wypala nazwę mailera na obiekcie w chwili `Mail::to()->queue()`
        // (`$view->mailer($this->name)`), nie w chwili wysyłki — przełączenie
        // konfiguracji PO zakolejkowaniu nie miałoby żadnego wpływu na
        // zadanie, które worker uruchomi później.
        config(['mail.default' => 'odmawia']);
        Mail::purge('odmawia');

        $this->wyslij();
        $this->przepracuj();

        $this->assertSame(1, DB::table('failed_jobs')->count(), 'List miał wylądować w `failed_jobs`.');
        $this->assertNull(
            $basia->refresh()->birthday_email_sent_on,
            'Po ostatecznej porażce kolumna nie ma prawa twierdzić, że list wyszedł.',
        );
        // Rezerwacja dnia ZOSTAJE — dzień jest już „zużyty" wobec dostawcy,
        // a kolejny rok to i tak inny dzień (świadomy wybór, D-077).
        $this->assertSame('2026-03-12', $basia->refresh()->birthday_email_queued_on?->toDateString());

        $wpisy = array_values(array_filter(
            $this->dziennik,
            fn (MessageLogged $w): bool => $w->level === 'error'
                && str_contains($w->message, 'List z życzeniami urodzinowymi nie doszedł'),
        ));

        $this->assertCount(1, $wpisy, 'Porażka listu ma zostawić jeden wpis w dzienniku.');
        $this->assertSame((string) $basia->getKey(), $wpisy[0]->context['user_id'] ?? null);
        $this->assertStringNotContainsString((string) $basia->email, json_encode($wpisy[0]->context, JSON_THROW_ON_ERROR));
    }

    /**
     * Awaria samego ZAKOLEJKOWANIA (nie wysyłki) zwalnia rezerwację dnia
     * i miejsce w budżecie — ponowienie komendy tego samego dnia wysyła
     * dokładnie jeden list, zamiast czekać do przyszłego roku.
     */
    public function test_awaria_zakolejkowania_zwalnia_rezerwacje_i_pozwala_ponowic_tego_samego_dnia(): void
    {
        $basia = $this->osoba('basia');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION test_odmow_insert_jobs() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'zakolejkowanie odrzucone w teście';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER test_odmow_insert_jobs BEFORE INSERT ON jobs
                FOR EACH ROW EXECUTE FUNCTION test_odmow_insert_jobs();
            SQL);

        $wynik = $this->wyslij();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNull(
            $basia->refresh()->birthday_email_queued_on,
            'Nieudane zakolejkowanie nie ma prawa zostawić dnia zajętego (#1956).',
        );
        $this->assertNull($basia->refresh()->birthday_email_sent_on);
        $this->assertStringContainsString('Nie udało się zakolejkować: 1', $wynik);

        DB::unprepared('DROP TRIGGER test_odmow_insert_jobs ON jobs');
        DB::unprepared('DROP FUNCTION test_odmow_insert_jobs()');

        $wynik = $this->wyslij();

        $this->assertSame(1, DB::table('jobs')->count(), 'Ponowienie tego samego dnia ma wysłać dokładnie jeden list.');
        $this->assertStringContainsString('Wysłano: 1', $wynik);
    }

    /**
     * Ponowienie po udanej wysyłce nie wysyła drugiego listu tego samego dnia.
     */
    public function test_drugi_przebieg_po_udanej_wysylce_nie_wysyla_drugiego_listu(): void
    {
        $this->osoba('basia');

        $this->wyslij();
        $this->przepracuj();
        $this->wyslij();

        $this->assertSame(0, DB::table('jobs')->count(), 'Drugi przebieg nie miał zakolejkować kolejnego listu.');
        $this->assertCount(1, $this->wyslaneListy());
    }
}
