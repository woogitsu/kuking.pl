<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Zgody\PrzestawZgodeNaDigest;
use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * KOMUNIKAT WYJĄTKU Z CUDZEJ BIBLIOTEKI NIE WCHODZI SUROWY DO DZIENNIKA.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  REGUŁA I JEJ POWÓD
 * ────────────────────────────────────────────────────────────────────────
 *
 * `$e->getMessage()` wygląda jak zdanie napisane przez kogoś z nas w kodzie.
 * Dla dwóch najczęstszych wyjątków w tym serwisie to założenie jest FAŁSZYWE
 * i oba przypadki są już w repozytorium zmierzone i opisane:
 *
 *  1. `QueryException` — komunikat buduje STEROWNIK i wkłada w niego SQL
 *     RAZEM Z WARTOŚCIAMI: `DETAIL: Key (email)=(basia@wp.pl) already
 *     exists. (…, SQL: insert into "users" (…) values (basia@wp.pl, $2y$12$…))`
 *     — czyli adres i hash hasła (audyt A6-01,
 *     `App\Logging\WebhookBleduHandler`; ta sama lekcja w
 *     `App\Domain\Analytics\ZapiszSygnal`, gdzie w komunikacie stała
 *     wpisana przez człowieka fraza wyszukiwania).
 *
 *  2. wyjątek transportu poczty — niesie odpowiedź serwera, a w niej
 *     ADRES ODRZUCONEGO ODBIORCY: `550 5.1.1 <basia@wp.pl>: Recipient
 *     address rejected` (`App\Poczta\BezpiecznyKomunikat`).
 *
 * AGENTS.md §7 zakazuje danych osobowych w dzienniku. Repozytorium zna tę
 * regułę i stosuje ją w kilku miejscach wprost — ale stosowało ją
 * NIERÓWNO, a dziennik z jednym adresem e-mail jest tak samo dziurawy jak
 * dziennik z tysiącem.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO OBA PRZYPADKI SĄ W JEDNYM PLIKU
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo to jest JEDNA reguła o dwóch wystąpieniach, a nie dwie reguły.
 * Rozdzielenie ich na dwa pliki oznaczałoby, że trzecie wystąpienie nie ma
 * dokąd trafić — i dopisze się jako trzeci plik, albo nie dopisze wcale.
 *
 * CZEGO TEN TEST NIE OBEJMUJE: pozostałych `$e->getMessage()` w dzienniku
 * (m.in. `GenerateUserExport::handle()` — tam pełen komunikat zostaje
 * ŚWIADOMIE, bo to jedyne miejsce diagnozy awarii magazynu). Lista jest
 * w raporcie audytu; ten plik pilnuje dwóch dróg, na których po drugiej
 * stronie stoi adres e-mail konkretnego człowieka.
 */
final class KomunikatWyjatkuNieWchodziSurowyDoDziennikaTest extends TestCase
{
    use RefreshDatabase;

    /** Tekst, jaki naprawdę oddaje transport przy odrzuconym odbiorcy. */
    private const ODMOWA_Z_ADRESEM = '550 5.1.1 <basia@example.com>: Recipient address rejected';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config([
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    /**
     * Paczka RODO jest gotowa, ale list z linkiem nie wyszedł, bo dostawca
     * odrzucił odbiorcę — i powiedział to, wymieniając jego adres.
     */
    public function test_odmowa_wysylki_paczki_nie_zostawia_adresu_w_dzienniku(): void
    {
        $basia = User::factory()->create(['email' => 'basia@example.com']);

        $export = DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        Mail::shouldReceive('to')->andThrow(new RuntimeException(self::ODMOWA_Z_ADRESEM));

        $dziennik = Log::spy();

        (new GenerateUserExport((string) $export->getKey()))->handle();

        // KONTROLA DODATNIA: paczka ma być gotowa mimo nieudanego listu,
        // a wpis w dzienniku ma w ogóle powstać. Bez tego dwa `not contains`
        // niżej przechodziłyby także wtedy, gdyby nic się nie wydarzyło.
        $this->assertSame(DataExport::STATUS_READY, $export->refresh()->status);

        $dziennik->shouldHaveReceived('warning')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($basia): bool {
                if (! str_contains($wiadomosc, 'e-mail nie wyszedł')) {
                    return false;
                }

                $caly = $wiadomosc.' '.json_encode($kontekst, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $this->assertStringNotContainsString($basia->email, $caly);
                $this->assertStringNotContainsString('@example.com', $caly);

                // …a jednocześnie wpis dalej mówi, CO się stało — inaczej
                // byłaby to cisza, nie redakcja.
                $this->assertStringContainsString('550 5.1.1', $caly);

                return true;
            })->once();
    }

    /**
     * Zapis wycofania zgody na digest wywraca się na bazie — a komunikat
     * `QueryException` niesie SQL razem z wartościami.
     */
    public function test_awaria_bazy_przy_zgodzie_nie_zostawia_sql_w_dzienniku(): void
    {
        $basia = User::factory()->create([
            'email' => 'basia@example.com',
            'wants_weekly_digest' => true,
        ]);

        $sql = 'insert into "dziennik_zgod" ("email") values (?)';

        $dziennik = Log::spy();

        // KONTROLA DODATNIA: mimo awarii dziennika zgód sama zgoda ma zostać
        // wycofana i akcja ma oddać `true` — to jest jej obietnica wobec
        // człowieka, który kliknął „nie chcę więcej".
        // Awaria dotyczy zapisu dowodu, nie transakcji chroniącej konto.
        $dispatcher = WpisZgody::getEventDispatcher();
        WpisZgody::setEventDispatcher(clone $dispatcher);
        try {
            WpisZgody::creating(static function () use ($sql): never {
                throw new QueryException('pgsql', $sql, ['basia@example.com'], new RuntimeException('SQLSTATE[23505]'));
            });
            $this->assertTrue((new PrzestawZgodeNaDigest)->handle($basia, false, WpisZgody::ZRODLO_USTAWIENIA));
        } finally {
            WpisZgody::setEventDispatcher($dispatcher);
        }
        $this->assertFalse($basia->fresh()->wants_weekly_digest);

        $dziennik->shouldHaveReceived('error')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($sql): bool {
                if (! str_contains($wiadomosc, 'wycofania zgody')) {
                    return false;
                }

                $caly = $wiadomosc.' '.json_encode($kontekst, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $this->assertStringNotContainsString('basia@example.com', $caly);
                $this->assertStringNotContainsString('insert into', $caly);
                $this->assertStringNotContainsString($sql, $caly);

                // Nazwa klasy zostaje — po to, żeby dało się rozpoznać rodzaj
                // awarii bez oglądania jej treści.
                $this->assertStringContainsString('QueryException', $caly);

                return true;
            })->once();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
