<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * A6-03: kod jednorazowy dawał się zużyć dwa razy, a zużyty kod zapasowy
 * wracał na listę.
 *
 * CO BYŁO NIE TAK
 * `TwoFactorAuthenticator` brał stan 2FA z PRZEKAZANEGO obiektu `User`
 * i zapisywał wynik z powrotem, nie sprawdzając, czy w międzyczasie ktoś
 * tym stanem nie ruszył. Dwa nakładające się przebiegi czytały więc ten sam
 * stan sprzed zużycia i oba go akceptowały:
 *
 *   TOTP            A i B odczytują pusty znacznik; A zapisuje czas kodu,
 *                   B akceptuje TEN SAM kod, bo porównuje ze swoim starym
 *                   znacznikiem;
 *   kody zapasowe   A i B odczytują komplet [kod1, kod2]; A zużywa kod1
 *                   i zapisuje [kod2]; B zużywa kod2 ze STAREGO kompletu
 *                   i zapisuje [kod1] — czyli zużyty kod1 WRACA na listę.
 *
 * Drugi przypadek jest gorszy niż pierwszy: kod zapasowy leży u człowieka na
 * kartce i ma działać dokładnie raz. „Wraca na listę" znaczy, że kartka,
 * którą ktoś uznał za zużytą i wyrzucił, znowu otwiera konto.
 *
 * CZEGO TE TESTY NIE ROBIĄ — I DLACZEGO TO WYSTARCZA
 * Nie odpalają dwóch równoległych żądań HTTP. Odtwarzają PRZEPLOT ODCZYTÓW
 * I ZAPISÓW, czyli dokładnie ten stan, w którym oba przebiegi mają w ręku
 * model sprzed zużycia. Dwa obiekty `User` wczytane osobno z bazy to jest
 * ta sama sytuacja, w jakiej są dwa procesy PHP — i to ona, nie samo
 * „równolegle", była przyczyną usterki. Blokada wiersza zamyka przy okazji
 * wariant prawdziwie równoległy, którego test w jednym procesie i tak by
 * nie pokazał.
 */
final class DrugiSkladnikZuzywaSieRazTest extends TestCase
{
    use RefreshDatabase;

    private function totp(): TwoFactorAuthenticator
    {
        return app(TwoFactorAuthenticator::class);
    }

    /** Konto z włączonym 2FA i podanymi kodami zapasowymi; zwraca sekret. */
    private function zDwuskladnikowym(User $user, array $kodyZapasowe = ['ABCD-1234']): string
    {
        $sekret = $this->totp()->generateSecret();
        $user->beginTwoFactorSetup($sekret);
        $user->confirmTwoFactor($this->totp()->hashBackupCodes($kodyZapasowe));
        $user->refresh();

        return $sekret;
    }

    #[Test]
    public function ten_sam_kod_totp_nie_przechodzi_drugi_raz_na_starym_obiekcie(): void
    {
        $basia = $this->user('basia');
        $sekret = $this->zDwuskladnikowym($basia);
        $kod = (new Google2FA)->getCurrentOtp($sekret);

        // Dwa obiekty wczytane NIEZALEŻNIE, oba ze stanem sprzed zużycia —
        // dokładnie to, co ma w ręku drugie żądanie, które weszło, zanim
        // pierwsze zdążyło zapisać.
        $a = User::query()->findOrFail($basia->getKey());
        $b = User::query()->findOrFail($basia->getKey());

        $this->assertNull($a->two_factor_last_used_at, 'Konto ma już zapisany znacznik — przeplot nie odtworzy stanu sprzed zużycia.');
        $this->assertNull($b->two_factor_last_used_at);

        $this->assertTrue($this->totp()->verifyCode($a, $sekret, $kod),
            'Pierwsze użycie kodu ma się udać — bez tego nie wiadomo, czy drugie odmówiło z właściwego powodu.');

        $this->assertFalse($this->totp()->verifyCode($b, $sekret, $kod),
            'TEN SAM kod przeszedł drugi raz. Kod przedstawiany człowiekowi jako jednorazowy przestaje nim być, gdy dwa wejścia się nałożą.');
    }

    #[Test]
    public function zuzyty_kod_zapasowy_nie_wraca_na_liste(): void
    {
        $basia = $this->user('basia');
        $this->zDwuskladnikowym($basia, ['AAAA-1111', 'BBBB-2222']);

        $a = User::query()->findOrFail($basia->getKey());
        $b = User::query()->findOrFail($basia->getKey());

        $this->assertCount(2, $a->two_factor_backup_codes ?? [], 'Konto nie ma dwóch kodów — przeplot nie odtworzy usterki.');

        $this->assertTrue($this->totp()->consumeBackupCode($a, 'AAAA-1111'), 'Pierwszy kod zapasowy ma zadziałać.');
        $this->assertTrue($this->totp()->consumeBackupCode($b, 'BBBB-2222'), 'Drugi kod zapasowy też ma zadziałać — jest osobny i nikt go jeszcze nie użył.');

        // WŁAŚCIWY DOWÓD: po obu zużyciach nie ma prawa zostać ŻADEN kod.
        // Przed poprawką zostawał `AAAA-1111`, bo drugi zapis nadpisywał
        // listę stanem sprzed pierwszego zużycia.
        $basia->refresh();
        $this->assertSame([], $basia->two_factor_backup_codes ?? [],
            'Po zużyciu obu kodów na liście coś zostało — zapis drugiego cofnął zużycie pierwszego.');

        $swiezy = User::query()->findOrFail($basia->getKey());
        $this->assertFalse($this->totp()->consumeBackupCode($swiezy, 'AAAA-1111'),
            'Zużyty kod zapasowy da się użyć jeszcze raz. Kartka, którą człowiek uznał za wykorzystaną, nadal otwiera konto.');
    }

    /**
     * Kontrola w DRUGĄ stronę. Blokada i ponowny odczyt nie mogą zepsuć
     * zwykłej ścieżki: poprawny kod ma nadal przechodzić, a niepoprawny
     * odpadać. Bez tego przypadku implementacja odmawiająca ZAWSZE
     * przechodziłaby oba testy wyżej.
     */
    #[Test]
    public function zwykla_sciezka_dziala_bez_zmian(): void
    {
        $basia = $this->user('basia');
        $sekret = $this->zDwuskladnikowym($basia, ['CCCC-3333']);

        $this->assertFalse($this->totp()->verifyCode($basia, $sekret, '000000'),
            'Zmyślony kod przeszedł.');

        $this->assertTrue($this->totp()->verifyCode($basia, $sekret, (new Google2FA)->getCurrentOtp($sekret)),
            'Poprawny kod nie przeszedł — poprawka zepsułaby logowanie wszystkim, którzy mają 2FA.');

        $this->assertTrue($this->totp()->consumeBackupCode($basia, 'cccc-3333'),
            'Kod zapasowy wpisany małymi literami nie zadziałał — normalizacja wielkości liter miała zostać.');
    }

    /**
     * Sekret wymieniony w międzyczasie (ktoś włączył 2FA od nowa w drugiej
     * karcie) unieważnia kody policzone ze starego sekretu.
     */
    #[Test]
    public function kod_ze_starego_sekretu_nie_przechodzi_po_wymianie(): void
    {
        $basia = $this->user('basia');
        $staryS = $this->zDwuskladnikowym($basia);
        $staryKod = (new Google2FA)->getCurrentOtp($staryS);

        $zStarym = User::query()->findOrFail($basia->getKey());

        // Druga karta zaczyna konfigurację od nowa.
        $basia->beginTwoFactorSetup($this->totp()->generateSecret());

        $this->assertFalse($this->totp()->verifyCode($zStarym, $staryS, $staryKod),
            'Kod policzony ze starego sekretu przeszedł mimo wymiany sekretu.');
    }
}
