<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ResolveAppeal;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KTO ROZSTRZYGA ODWOŁANIE — PILNUJE TEGO DOMENA, NIE JEDEN KONTROLER
 * (issue #1087).
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  CO BYŁO ZEPSUTE
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Reguła A-4 („odwołanie zamyka administrator, nie każdy moderator",
 * `UserPolicy::resolveAppeals`) stała WYŁĄCZNIE w
 * `AppealController::resolve()`. `ResolveAppeal::handle()` przyjmowało
 * parametr o nazwie `$moderator` i nie pytało o nim o nic — nazwa
 * parametru była jedyną „kontrolą" roli w całej akcji.
 *
 * Skutek: reguła obowiązywała na dokładnie JEDNEJ drodze do tej czynności.
 * Komenda artisan, zadanie w kolejce, test, nowy endpoint albo webhook
 * wołający tę samą akcję zamykał cudze odwołanie, cofał decyzję
 * moderacyjną i ODWIESZAŁ ZBANOWANE KONTO (`cofnij()` →
 * `User::reinstate()`) bez żadnej bramki. Bramka pilnująca skutków
 * stała po drugiej stronie kontrolera niż skutki.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  DLACZEGO POMIAR OMIJA HTTP
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Testy trasy `admin.appeals.resolve` (`KazdaTrasaZIdentyfikatoremPodPolicyTest`,
 * `OdwolanieOdDecyzjiTest`) mierzą bramkę KONTROLERA i były zielone także
 * przed tą poprawką — dziura była poza ich zasięgiem z definicji. Ten plik
 * woła `ResolveAppeal::handle()` z kontenera, tak jak zrobi to każde
 * przyszłe wywołanie spoza HTTP.
 *
 * `docs/PULAPKI_TESTOW.md` pułapka 4: odmowa dla wszystkich wygląda tu
 * identycznie jak poprawna bramka, więc każdemu „nie wolno" towarzyszy
 * przebieg administratora, który MA przejść i zrobić wszystko, co należy.
 */
class RolaRozstrzygajacegoOdwolanieStoiWDomenieTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SEDNO #1087: moderator bez roli `admin` nie zamknie odwołania nawet
     * wtedy, gdy kontroler w ogóle nie bierze udziału.
     */
    public function test_moderator_bez_roli_admina_nie_rozstrzygnie_odwolania_z_pominieciem_kontrolera(): void
    {
        $moderator = $this->moderator();
        [$decyzja, $odwolanie] = $this->sprawa($moderator);

        try {
            app(ResolveAppeal::class)->handle(
                moderator: $moderator,
                odwolanie: $odwolanie,
                wynik: Appeal::STATUS_UPHELD,
                uzasadnienie: 'Podtrzymuję decyzję po ponownym sprawdzeniu sprawy.',
            );

            $this->fail(
                'Moderator bez roli `admin` zamknął odwołanie przez samą akcję domenową — '
                .'rola jest egzekwowana tylko w kontrolerze (#1087).',
            );
        } catch (AuthorizationException) {
            // Tego oczekujemy.
        }

        $odwolanie->refresh();

        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->status,
            'Odwołanie zmieniło status mimo odmowy.');
        $this->assertNull($odwolanie->decided_by);
        $this->assertNull($odwolanie->decided_at);
        $this->assertSame(0, AuditLogEntry::where('action', 'appeal.resolved')->count(),
            'Do dziennika audytu trafił wpis o rozstrzygnięciu, którego nie było.');
    }

    /**
     * NAJDROŻSZY SKUTEK: „cofam decyzję" na banie ODWIESZA KONTO.
     *
     * Ta asercja mierzy nie wpis w kolumnie, tylko realne uprawnienie —
     * bez bramki w domenie dowolny moderator (albo dowolne wywołanie spoza
     * HTTP) odwieszał zbanowane konto.
     */
    public function test_cofniecie_bana_bez_roli_admina_nie_odwiesza_konta(): void
    {
        $moderator = $this->moderator();
        [$decyzja, $odwolanie] = $this->sprawa($moderator, ModerationAction::ACTION_BAN);

        $ukarany = $decyzja->subject;
        $ukarany->ban();

        $this->assertSame(User::STATUS_BANNED, $ukarany->refresh()->status,
            'Konto nie jest zbanowane — ten test mierzyłby wtedy nic.');

        try {
            app(ResolveAppeal::class)->handle(
                moderator: $moderator,
                odwolanie: $odwolanie,
                wynik: Appeal::STATUS_OVERTURNED,
                uzasadnienie: 'Cofam bana, decyzja była pomyłką.',
            );

            $this->fail('Moderator bez roli `admin` cofnął bana przez akcję domenową (#1087).');
        } catch (AuthorizationException) {
            // Tego oczekujemy.
        }

        $this->assertSame(User::STATUS_BANNED, $ukarany->refresh()->status,
            'Konto zostało ODWIESZONE mimo braku uprawnień do rozstrzygania odwołań.');
    }

    /**
     * Zwykłe konto bez żadnej roli — ta sama odmowa.
     */
    public function test_zwykle_konto_nie_rozstrzygnie_odwolania(): void
    {
        $moderator = $this->moderator();
        [, $odwolanie] = $this->sprawa($moderator);

        $ktokolwiek = $this->user('ktokolwiek');

        $this->expectException(AuthorizationException::class);

        app(ResolveAppeal::class)->handle(
            moderator: $ktokolwiek,
            odwolanie: $odwolanie,
            wynik: Appeal::STATUS_OVERTURNED,
            uzasadnienie: 'Cofam tę decyzję, bo mogę.',
        );
    }

    /**
     * KONTROLA DODATNIA — ADMINISTRATOR DALEJ ROZSTRZYGA I SKUTKI DALEJ
     * ZACHODZĄ.
     *
     * Bez tego przebiegu wszystkie trzy testy wyżej byłyby zielone także
     * przy `handle()` rzucającym wyjątkiem bezwarunkowo — czyli przy
     * systemie skarg (DSA art. 20) zepsutym dla wszystkich.
     */
    public function test_administrator_dalej_rozstrzyga_odwolanie_i_cofa_skutki(): void
    {
        $moderator = $this->moderator();
        $admin = $this->admin();
        [$decyzja, $odwolanie] = $this->sprawa($moderator, ModerationAction::ACTION_BAN);

        $ukarany = $decyzja->subject;
        $ukarany->ban();

        $wynik = app(ResolveAppeal::class)->handle(
            moderator: $admin,
            odwolanie: $odwolanie,
            wynik: Appeal::STATUS_OVERTURNED,
            uzasadnienie: 'Cofam bana — po ponownym czytaniu sprawa wygląda inaczej.',
        );

        $this->assertSame(Appeal::STATUS_OVERTURNED, $wynik->status,
            'Administrator nie zamknął odwołania — bramka #1087 odmawia wszystkim.');
        $this->assertSame($admin->getKey(), $wynik->decided_by);
        $this->assertNotNull($wynik->decided_at);

        $this->assertSame(User::STATUS_ACTIVE, $ukarany->refresh()->status,
            'Cofnięcie decyzji nie odwiesiło konta — odwołanie, po którym nic się '
            .'nie zmienia, nie jest odwołaniem.');

        $this->assertSame(1, AuditLogEntry::where('action', 'appeal.resolved')->count(),
            'Rozstrzygnięcie nie trafiło do dziennika audytu.');
    }

    /**
     * KONTROLA DODATNIA #2 — BRAMKA ROLI NIE ZJADŁA KARENCJI.
     *
     * Karencja na PODTRZYMANIE własnej decyzji
     * (`ResolveAppeal::sprawdzKarencje()`) stoi PO nowej bramce. Gdyby
     * nowa bramka rzucała wcześniej niż trzeba albo przestawiła kolejność,
     * karencja przestałaby działać, a testy odmowy tego by nie zauważyły.
     */
    public function test_karencja_na_podtrzymanie_wlasnej_decyzji_dalej_dziala(): void
    {
        $admin = $this->admin();
        [, $odwolanie] = $this->sprawa($admin);

        // Administrator jest tu AUTOREM pierwotnej decyzji sprzed chwili —
        // podtrzymać jej nie może przez pierwsze `appeal_self_uphold_hours`.
        $this->expectException(BladDlaCzlowieka::class);

        app(ResolveAppeal::class)->handle(
            moderator: $admin,
            odwolanie: $odwolanie,
            wynik: Appeal::STATUS_UPHELD,
            uzasadnienie: 'Podtrzymuję własną decyzję sprzed chwili.',
        );
    }

    /**
     * @return array{0: ModerationAction, 1: Appeal}
     */
    private function sprawa(User $moderator, string $akcja = ModerationAction::ACTION_HIDE): array
    {
        $ukarany = $this->user(null);

        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'user',
            'target_id' => (string) $ukarany->getKey(),
            'subject_user_id' => $ukarany->getKey(),
            'action' => $akcja,
            'reason_code' => 'spam-reklama',
            'user_message' => 'Konto rozsyłało reklamy.',
        ]);

        $odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $ukarany->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'Proszę o ponowne rozpatrzenie tej sprawy.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        return [$decyzja->refresh(), $odwolanie->refresh()];
    }
}
