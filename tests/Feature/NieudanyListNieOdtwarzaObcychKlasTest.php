<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\PolecenieZadania;
use App\Models\MailFailure;
use App\Models\User;
use App\Poczta\ZapiszNieudanyList;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Ślad nieudanego listu NIE ODTWARZA obcych klas z `failed_jobs` (issue #1841).
 *
 * Do 26.09.2026 `ZapiszNieudanyList::ktoCzekal()` wołało `unserialize()` bez
 * `allowed_classes`: każda klasa opisana w ładunku powstawała — z `__wakeup`
 * i `__destruct` — a `instanceof SendQueuedNotifications` odrzucał ją dopiero
 * potem. Żaden `try` tego nie cofa.
 *
 * Atrapa niżej zapisuje w polu statycznym, że się obudziła. Test ma DWIE
 * strony, bo każda sama byłaby pusta (pułapka 4 z `docs/PULAPKI_TESTOW.md`):
 *
 *  - kontrola dodatnia: z prawidłowego ładunku odbiorca NADAL się ustala
 *    (`user_id` w śladzie) — lista klas nie jest za wąska;
 *  - właściwa asercja: atrapa, choć stoi w tym samym ładunku, nie obudziła
 *    się ani razu — lista nie jest za szeroka.
 */
class NieudanyListNieOdtwarzaObcychKlasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AtrapaZWybudzeniem::$obudzona = 0;
    }

    public function test_prawidlowy_ladunek_ustala_odbiorce_bez_odtwarzania_powiadomienia(): void
    {
        $osoba = User::factory()->create();
        $polecenie = serialize(new SendQueuedNotifications($osoba, new AtrapaZWybudzeniem));

        $this->assertStringContainsString(
            AtrapaZWybudzeniem::class,
            $polecenie,
            'Kontrola dodatnia: atrapa NAPRAWDĘ leży w ładunku — inaczej „nie obudziła się" nic nie znaczy.',
        );

        app(ZapiszNieudanyList::class)($this->porazka($polecenie));

        $this->assertSame(
            $osoba->getKey(),
            MailFailure::query()->sole()->user_id,
            'Lista dozwolonych klas nie może zabrać śladowi odbiorcy — to po nią ten odczyt istnieje.',
        );
        $this->assertSame(
            0,
            AtrapaZWybudzeniem::$obudzona,
            'Klasa powiadomienia spoza `PolecenieZadania::WOLNO_ODTWORZYC` powstała z `failed_jobs`.',
        );
    }

    public function test_ladunek_z_obca_klasa_nie_tworzy_jej_i_nie_daje_odbiorcy(): void
    {
        app(ZapiszNieudanyList::class)($this->porazka(serialize(new AtrapaZWybudzeniem)));

        $slad = MailFailure::query()->sole();

        $this->assertNull($slad->user_id, 'Ładunek bez `SendQueuedNotifications` nie mówi, kto czekał.');
        $this->assertSame(
            0,
            AtrapaZWybudzeniem::$obudzona,
            'Obca klasa z `failed_jobs` została odtworzona — `unserialize()` bez `allowed_classes` (#1841).',
        );
    }

    public function test_obca_klasa_wraca_jako_niekompletna(): void
    {
        $osoba = User::factory()->create();

        $obiekt = PolecenieZadania::powiadomienie(
            serialize(new SendQueuedNotifications($osoba, new AtrapaZWybudzeniem)),
        );

        $this->assertInstanceOf(SendQueuedNotifications::class, $obiekt);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $obiekt->notification);
        $this->assertNull(PolecenieZadania::powiadomienie(serialize(new AtrapaZWybudzeniem)));
        $this->assertNull(PolecenieZadania::powiadomienie('O:8:"uszkodzo'));
        $this->assertSame(0, AtrapaZWybudzeniem::$obudzona);
    }

    private function porazka(string $polecenie): JobFailed
    {
        $zadanie = $this->createMock(Job::class);
        $zadanie->method('uuid')->willReturn('11111111-2222-3333-4444-555555555555');
        $zadanie->method('getQueue')->willReturn('high');
        $zadanie->method('attempts')->willReturn(3);
        $zadanie->method('resolveName')->willReturn(SendQueuedNotifications::class);
        $zadanie->method('payload')->willReturn([
            'displayName' => AtrapaZWybudzeniem::class,
            'data' => ['commandName' => SendQueuedNotifications::class, 'command' => $polecenie],
        ]);

        return new JobFailed('database', $zadanie, new TransportException('Dostawca odmówił.'));
    }
}

/**
 * Stoi w miejscu klasy z efektem ubocznym przy odtwarzaniu. `__unserialize`,
 * bo `Notification` ma je z `SerializesModels` (wtedy `__wakeup` nie leci),
 * a nie `__destruct`, bo ten odpaliłby także na obiekcie zbudowanym w teście.
 */
final class AtrapaZWybudzeniem extends Notification
{
    public static int $obudzona = 0;

    /** @param array<string, mixed> $values */
    public function __unserialize(array $values): void
    {
        self::$obudzona++;
    }
}
