<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Report;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * `reports.decision_sent_at` znaczy „poinformowaliśmy”, nie „zakolejkowaliśmy”
 * (issue #1838, D-293).
 *
 * Do 26 września 2026 `RozstrzygnijZgloszenie` stawiała znacznik zaraz po
 * `notify()`, a `DecyzjaWSprawieZgloszenia` jest `ShouldQueue` — więc znacznik
 * powstawał, zanim worker w ogóle spróbował wysłać list. Worker mógł potem
 * wyczerpać próby, a kolumna dalej twierdziła, że zgłaszający wie o decyzji.
 *
 * Testy idą PRAWDZIWĄ kolejką `database` i prawdziwym `queue:work`, nie
 * `Notification::fake()` — fałszywka nie wykonuje kanału pocztowego, więc
 * nie umiałaby odróżnić zakolejkowania od wysyłki, a właśnie o to tu chodzi.
 */
class DecyzjaZgloszeniaOznaczanaPoWysylceTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = 'zglaszajaca@example.test';

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
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $wpis): void {
            $this->dziennik[] = $wpis;
        });
    }

    /**
     * SEDNO. Po decyzji list czeka w kolejce i znacznik jest PUSTY; dopiero
     * worker, który list wysłał, stawia znacznik.
     */
    public function test_znacznik_stoi_dopiero_po_wyslaniu_listu_a_nie_po_zakolejkowaniu(): void
    {
        $zgloszenie = $this->zgloszeniePrawne();

        $this->rozstrzygnij($zgloszenie);

        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->refresh()->status);
        $this->assertSame(1, DB::table('jobs')->count(), 'Decyzja ma zostawić dokładnie jedno zadanie z listem.');
        $this->assertNull(
            $zgloszenie->decision_sent_at,
            'Samo zakolejkowanie listu nie jest poinformowaniem zgłaszającego (#1838).',
        );
        $this->assertSame(1, Report::query()->decyzjaNieprzekazanaMailem()->count());

        $this->przepracuj();

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertCount(1, $this->wyslaneListy(), 'Worker miał wysłać jeden list z decyzją.');
        $this->assertNotNull($zgloszenie->refresh()->decision_sent_at, 'Udana wysyłka ma postawić znacznik.');
        $this->assertSame(0, Report::query()->decyzjaNieprzekazanaMailem()->count());
    }

    /**
     * Worker wyczerpał próby: znacznik zostaje pusty, sprawa jest policzalna,
     * a dziennik mówi, KTÓREJ sprawy to dotyczy — bez adresu zgłaszającego.
     */
    public function test_ostateczna_porazka_zostawia_sprawe_bez_znacznika_i_slad_w_dzienniku(): void
    {
        $zgloszenie = $this->zgloszeniePrawne();

        $this->rozstrzygnij($zgloszenie);

        config(['mail.default' => 'odmawia']);
        Mail::purge('odmawia');

        $this->przepracuj();

        $this->assertSame(1, DB::table('failed_jobs')->count(), 'List miał wylądować w `failed_jobs`.');
        $this->assertNull(
            $zgloszenie->refresh()->decision_sent_at,
            'Po ostatecznej porażce kolumna nie ma prawa twierdzić, że poinformowaliśmy.',
        );
        $this->assertSame(1, Report::query()->decyzjaNieprzekazanaMailem()->count());

        $wpisy = array_values(array_filter(
            $this->dziennik,
            fn (MessageLogged $w): bool => $w->level === 'error'
                && str_contains($w->message, 'Decyzja w sprawie zgłoszenia prawnego nie doszła'),
        ));

        $this->assertCount(1, $wpisy, 'Porażka listu z decyzją ma zostawić jeden wpis w dzienniku.');
        $this->assertSame($zgloszenie->numer_sprawy, $wpisy[0]->context['numer_sprawy'] ?? null);
        $this->assertStringNotContainsString(self::ADRES, json_encode($wpisy[0]->context, JSON_THROW_ON_ERROR));
    }

    /**
     * Ponowienie po udanej wysyłce nie wysyła drugiego listu i nie przesuwa
     * znacznika — to jest ta sama decyzja, o której już poinformowaliśmy.
     */
    public function test_ponowienie_po_udanej_wysylce_nie_wysyla_drugiego_listu(): void
    {
        $zgloszenie = $this->zgloszeniePrawne();

        $this->rozstrzygnij($zgloszenie);
        $this->przepracuj();

        $pierwszy = $zgloszenie->refresh()->decision_sent_at;
        $this->assertNotNull($pierwszy);

        $this->travel(2)->hours();

        $decyzja = ModerationAction::query()->where('report_id', $zgloszenie->getKey())->sole();
        Notification::route('mail', self::ADRES)->notify(new DecyzjaWSprawieZgloszenia($zgloszenie, $decyzja));
        $this->przepracuj();

        $this->assertCount(1, $this->wyslaneListy(), 'Powtórzenie tej samej decyzji wysłało drugi list.');
        $this->assertTrue(
            $pierwszy->equalTo($zgloszenie->refresh()->decision_sent_at),
            'Znacznik ma zostać z chwili pierwszej wysyłki.',
        );
    }

    /**
     * „List przyjęty, zapis znacznika padł” — rozstrzygnięte jawnie (D-293):
     * zadanie NIE pada (padnięcie = kolejna próba = kolejny identyczny list),
     * znacznik zostaje pusty, a dziennik niesie numer sprawy.
     *
     * Awarię zapisu wywołuje wyzwalacz w bazie odrzucający zmianę kolumny.
     */
    public function test_padniety_zapis_znacznika_po_wysylce_nie_wysyla_listu_drugi_raz(): void
    {
        $zgloszenie = $this->zgloszeniePrawne();

        $this->rozstrzygnij($zgloszenie);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION test_odmow_decision_sent_at() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'zapis znacznika odrzucony w teście';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER test_odmow_decision_sent_at BEFORE UPDATE OF decision_sent_at ON reports
                FOR EACH ROW EXECUTE FUNCTION test_odmow_decision_sent_at();
            SQL);

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'high,default',
            '--once' => true,
            '--tries' => 3,
        ]);

        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Zadanie nie ma padać po przyjętym liście.');
        $this->assertSame(0, DB::table('jobs')->count(), 'Zadanie nie ma wracać do kolejki na kolejną próbę.');
        $this->assertCount(1, $this->wyslaneListy());
        $this->assertNull($zgloszenie->refresh()->decision_sent_at);

        $wpisy = array_filter(
            $this->dziennik,
            fn (MessageLogged $w): bool => $w->level === 'error'
                && str_contains($w->message, 'nie udało się zapisać znacznika decision_sent_at')
                && ($w->context['numer_sprawy'] ?? null) === $zgloszenie->numer_sprawy,
        );
        $this->assertCount(1, $wpisy);
    }

    private function zgloszeniePrawne(): Report
    {
        return Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_id' => null,
            'target_url' => 'adres opisany z pamięci',
            'reason' => 'other',
            'illegality_explanation' => 'Widziałam tam treść naruszającą prawo.',
            'good_faith_at' => now(),
            'notifier_name' => 'Anna Zgłaszająca',
            'notifier_email' => self::ADRES,
            'status' => Report::STATUS_OPEN,
        ]);
    }

    private function rozstrzygnij(Report $zgloszenie): void
    {
        $this->actingAs($this->moderator())->post(route('admin.reports.decide', $zgloszenie), [
            'action' => ModerationAction::ACTION_NONE,
            'reason_code' => 'nierozpoznany-adres',
        ])->assertSessionHasNoErrors();
    }

    private function przepracuj(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'high,default',
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
}
