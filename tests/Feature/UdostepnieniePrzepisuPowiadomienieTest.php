<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Push\KanalPush;
use App\Domain\Recipes\Udostepnienia\OdbierzDostepDoPrzepisu;
use App\Domain\Recipes\Udostepnienia\UdostepnijPrzepis;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\WyslijPowiadomieniePush;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as Listy;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Powiadomienie „@nazwa pokazuje Ci przepis" (#2650, decyzja właściciela
 * z 2.10.2026, D-333, punkt 1): wyłącznie w serwisie, jedno na parę,
 * a tytuł prywatnego przepisu tylko przy BIEŻĄCYM `readShared()`.
 *
 * Każda utrata dostępu ma kontrolę dodatnią na tym samym wierszu: tytuł
 * jest na liście, zanim dostęp zniknie — bez tego „nie ma tytułu"
 * przeszłoby także wtedy, gdyby karta nigdy go nie pokazywała.
 *
 * Kontrola ujemna (wykonana 2.10.2026, przywrócona):
 * `CelPowiadomienia::wczytajPrzepisyUdostepnione()` bez sprawdzenia
 * `readShared` (sam przepis z bazy) — wszystkie przypadki utraty dostępu
 * poza usunięciem przepisu oblewają na „Tytuł wyciekł po utracie dostępu".
 */
class UdostepnieniePrzepisuPowiadomienieTest extends TestCase
{
    use RefreshDatabase;

    private const TYTUL = 'Sernik babci Wandy';

    private const NEUTRALNE = 'Pokazany Ci przepis nie jest już dostępny.';

    private User $halina;

    private User $jurek;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->halina = $this->user('halina', ['display_name' => 'Halina']);
        $this->jurek = $this->user('jurek', ['display_name' => 'Jurek']);
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->halina->getKey(),
            'visibility' => 'private',
            'title' => self::TYTUL,
        ]);
    }

    private function udostepnij(): RecipeShare
    {
        [$udostepnienie] = app(UdostepnijPrzepis::class)->poNazwie($this->halina->fresh(), $this->przepis->fresh(), 'jurek');

        return $udostepnienie;
    }

    /** @return Collection<int, Notification> */
    private function powiadomienia(): Collection
    {
        return Notification::query()
            ->where('user_id', $this->jurek->getKey())
            ->where('type', Notification::TYPE_RECIPE_SHARED)
            ->get();
    }

    private function lista(?User $kto = null): string
    {
        return (string) $this->actingAs(($kto ?? $this->jurek)->fresh())
            ->get(route('notifications.index'))
            ->assertOk()
            ->getContent();
    }

    public function test_odbiorca_dostaje_jedno_powiadomienie_w_serwisie_z_odnosnikiem_do_strony_czytania(): void
    {
        $this->udostepnij();

        $powiadomienia = $this->powiadomienia();
        $this->assertCount(1, $powiadomienia);
        $this->assertSame($this->halina->getKey(), $powiadomienia[0]->actor_id);
        // Tytuł nie jest przechowywany — liczy się przy wyświetlaniu.
        $this->assertSame(['recipe_id' => (string) $this->przepis->getKey()], $powiadomienia[0]->data);

        $html = $this->lista();
        $this->assertStringContainsString('@halina pokazuje Ci przepis', $html);
        $this->assertStringContainsString('„'.self::TYTUL.'”', $html);
        $this->assertStringContainsString('>Zobacz</button>', $html);

        $this->actingAs($this->jurek)
            ->post(route('notifications.open', $powiadomienia[0]))
            ->assertRedirect(route('recipes.shared.show', $this->przepis));
        $this->assertNotNull($powiadomienia[0]->fresh()->read_at);

        // Paczka danych: rodzaj i nadawca są, tytułu nie ma (nie jest przechowywany).
        $paczka = app(CollectUserExportData::class)->handle($this->jurek->fresh(), new ExportPhotoPlan($this->jurek->fresh()), Carbon::now());
        $this->assertSame([Notification::TYPE_RECIPE_SHARED], array_column($paczka['powiadomienia'], 'rodzaj'));
        $this->assertStringNotContainsString(self::TYTUL, (string) json_encode($paczka['powiadomienia'], JSON_UNESCAPED_UNICODE));

        // Autorka nie dostaje niczego o własnej akcji.
        $this->assertSame(0, Notification::query()->where('user_id', $this->halina->getKey())->where('type', Notification::TYPE_RECIPE_SHARED)->count());
    }

    public function test_bez_listu_i_bez_web_push(): void
    {
        config([
            'kuking.push.vapid_public_key' => 'BTestowyKluczPublicznyNieDoWysylki',
            'kuking.push.vapid_private_key' => 'testowy-klucz-prywatny',
            'kuking.notifications.zewnetrzne.wlaczone' => true,
        ]);
        Queue::fake();
        Mail::fake();
        Listy::fake();
        $urzadzenie = new PushSubscription;
        $urzadzenie->forceFill([
            'user_id' => $this->jurek->getKey(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/udostepnienie-2650',
            'klucz_p256dh' => str_repeat('A', 87),
            'klucz_auth' => str_repeat('B', 22),
        ])->save();

        $this->udostepnij();

        $this->assertCount(1, $this->powiadomienia(), 'Kontrola dodatnia: powiadomienie w serwisie powstało.');
        $this->assertNotContains(Notification::TYPE_RECIPE_SHARED, KanalPush::TYPY);
        $this->assertFalse(KanalPush::dotyczy(Notification::TYPE_RECIPE_SHARED, ['recipe_id' => (string) $this->przepis->getKey()]));
        Queue::assertNotPushed(WyslijPowiadomieniePush::class);
        Mail::assertNothingOutgoing();
        Listy::assertNothingSent();
    }

    public function test_ponowne_udostepnienie_takze_po_cofnieciu_nie_mnozy_powiadomien(): void
    {
        $udostepnienie = $this->udostepnij();
        $this->udostepnij();
        $this->assertCount(1, $this->powiadomienia());

        app(OdbierzDostepDoPrzepisu::class)->odbierz($this->halina, $udostepnienie);
        $this->udostepnij();

        $this->assertSame(1, RecipeShare::query()->count(), 'Kontrola dodatnia: udostępnienie po cofnięciu powstało od nowa.');
        $this->assertCount(1, $this->powiadomienia(), 'Pętla udostępnij → odbierz → udostępnij nie może dokładać powiadomień.');
        // Ten sam wiersz znów pokazuje tytuł, bo dostęp wrócił.
        $this->assertStringContainsString(self::TYTUL, $this->lista());
    }

    /** @return array<string, array{string}> */
    public static function utratyDostepu(): array
    {
        return [
            'autorka cofa dostęp' => ['cofniecie'],
            'odbiorca rezygnuje' => ['rezygnacja'],
            'odbiorca blokuje autorkę' => ['blokada_odbiorcy'],
            'autorka blokuje odbiorcę' => ['blokada_autorki'],
            'przepis usunięty' => ['usuniecie'],
            'przepis ukryty przez moderację' => ['moderacja'],
            'autorka zawieszona' => ['zawieszenie_autorki'],
            'odbiorca zawieszony' => ['zawieszenie_odbiorcy'],
            'autorka zbanowana' => ['ban_autorki'],
            'konto autorki wymazane' => ['wymazanie_autorki'],
        ];
    }

    #[DataProvider('utratyDostepu')]
    public function test_po_utracie_dostepu_tytul_nie_wycieka_ani_z_listy_ani_z_otwarcia(string $przypadek): void
    {
        $udostepnienie = $this->udostepnij();
        $powiadomienie = $this->powiadomienia()->sole();

        // KONTROLA DODATNIA na tym samym wierszu.
        $this->assertStringContainsString(self::TYTUL, $this->lista());

        match ($przypadek) {
            'cofniecie' => app(OdbierzDostepDoPrzepisu::class)->odbierz($this->halina, $udostepnienie),
            'rezygnacja' => $this->actingAs($this->jurek)->delete(route('recipes.shared.leave', $udostepnienie))->assertRedirect(),
            'blokada_odbiorcy' => app(BlockUser::class)->handle($this->jurek, $this->halina),
            'blokada_autorki' => app(BlockUser::class)->handle($this->halina, $this->jurek),
            'usuniecie' => $this->actingAs($this->halina)->delete(route('recipes.destroy', $this->przepis))->assertRedirect(),
            'moderacja' => $this->przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save(),
            'zawieszenie_autorki' => $this->halina->suspend(now()->addDays(3)),
            'zawieszenie_odbiorcy' => $this->jurek->suspend(now()->addDays(3)),
            'ban_autorki' => $this->halina->ban(),
            'wymazanie_autorki' => $this->wymaz($this->halina),
            default => throw new \LogicException("Nieznany przypadek: {$przypadek}"),
        };

        $html = $this->lista();
        $this->assertStringNotContainsString(self::TYTUL, $html, 'Tytuł wyciekł po utracie dostępu.');
        $this->assertStringNotContainsString(route('recipes.shared.show', $this->przepis), $html);

        // Wiersz albo znika (blokada, ban sprawcy), albo mówi neutralnie — bez „Zobacz".
        if (str_contains($html, 'powiadomienie-'.$powiadomienie->getKey())) {
            $this->assertStringContainsString(self::NEUTRALNE, $html);
        }

        $otwarcie = $this->actingAs($this->jurek->fresh())
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie));
        $this->assertNotSame(route('recipes.shared.show', $this->przepis), $otwarcie->headers->get('Location'), 'Otwarcie powiadomienia prowadzi na stronę przepisu mimo utraty dostępu.');
        $this->assertStringNotContainsString(self::TYTUL, (string) json_encode(session()->all(), JSON_UNESCAPED_UNICODE));

        // Sekcja powiadomień w paczce danych odbiorcy też nie niesie tytułu
        // (sekcja `udostepnione_przepisy` opisuje sam wiersz `recipe_shares`,
        // który przy karze i ukryciu zostaje — to inna, zatwierdzona reguła).
        $paczka = app(CollectUserExportData::class)->handle($this->jurek->fresh(), new ExportPhotoPlan($this->jurek->fresh()), Carbon::now());
        $this->assertStringNotContainsString(self::TYTUL, (string) json_encode($paczka['powiadomienia'], JSON_UNESCAPED_UNICODE));
    }

    public function test_dostep_wraca_po_koncu_kary_a_z_nim_tytul(): void
    {
        $this->udostepnij();
        $this->halina->suspend(now()->addDays(3));
        $this->assertStringContainsString(self::NEUTRALNE, $this->lista());

        $this->travel(4)->days();

        $this->assertStringContainsString(self::TYTUL, $this->lista());
    }

    /** @return array<string, array{string}> */
    public static function kontaNieaktywne(): array
    {
        return [
            'odbiorca zawieszony' => ['zawieszenie_odbiorcy'],
            'odbiorca zbanowany' => ['ban_odbiorcy'],
            'autorka zawieszona' => ['zawieszenie_autorki'],
            'autorka zbanowana' => ['ban_autorki'],
        ];
    }

    #[DataProvider('kontaNieaktywne')]
    public function test_brak_powiadomienia_gdy_ktores_konto_jest_ukarane_w_chwili_udostepnienia(string $przypadek): void
    {
        match ($przypadek) {
            'zawieszenie_odbiorcy' => $this->jurek->suspend(now()->addDays(3)),
            'ban_odbiorcy' => $this->jurek->ban(),
            'zawieszenie_autorki' => $this->halina->suspend(now()->addDays(3)),
            'ban_autorki' => $this->halina->ban(),
            default => throw new \LogicException("Nieznany przypadek: {$przypadek}"),
        };

        try {
            $this->udostepnij();
            $this->fail('Udostępnienie przy ukaranym koncie powinno odmówić.');
        } catch (BladDlaCzlowieka|AuthorizationException) {
            // oczekiwane
        }

        $this->assertSame(0, RecipeShare::query()->count());
        $this->assertCount(0, $this->powiadomienia());
    }

    /**
     * Strażnik w samym `powiadom()` — niezależny od reguł `share`
     * i `moznaPokazac()`, które dziś odmawiają wcześniej.
     */
    public function test_powiadomienie_samo_odmawia_przy_nieaktywnym_koncie(): void
    {
        $powiadom = new \ReflectionMethod(UdostepnijPrzepis::class, 'powiadom');

        $this->jurek->suspend(now()->addDays(3));
        $powiadom->invoke(app(UdostepnijPrzepis::class), $this->halina->fresh(), $this->jurek->fresh(), $this->przepis);
        $this->halina->ban();
        $powiadom->invoke(app(UdostepnijPrzepis::class), $this->halina->fresh(), $this->user('basia'), $this->przepis);
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_RECIPE_SHARED)->count());

        // Kontrola dodatnia: przy aktywnych kontach to samo wywołanie zapisuje.
        $kasia = $this->user('kasia');
        $autor = $this->user('autor_aktywny');
        $powiadom->invoke(app(UdostepnijPrzepis::class), $autor, $kasia, $this->przepis);
        $this->assertSame(1, Notification::query()->where('type', Notification::TYPE_RECIPE_SHARED)->count());
    }

    private function wymaz(User $konto): void
    {
        $konto->markForDeletion(User::DELETE_SCOPE_MINIMUM);
        app(EraseAccountData::class)->handle($konto->fresh());
    }
}
