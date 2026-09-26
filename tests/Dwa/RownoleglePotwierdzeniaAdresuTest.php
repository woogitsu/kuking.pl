<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\LoginLinkToken;
use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

/**
 * DWA KONTA POTWIERDZAJĄ TEN SAM NOWY ADRES W TEJ SAMEJ CHWILI (#1435).
 *
 * `ConfirmEmailChange` blokuje wiersz WŁASNEGO konta, nie docelowy adres.
 * Dwa różne konta przechodzą więc równolegle aplikacyjne „czy adres wolny",
 * a zapis przegranego odrzuca dopiero `users_email_lower_unique`. Przed
 * poprawką ten wyjątek wychodził z akcji jako HTTP 500; teraz ma być tym
 * samym komunikatem dla człowieka, co przy zwykłym zajętym adresie.
 *
 * ── PRZEPLOT ──
 *
 * Bariera trzyma wyłączną blokadę doradczą (1435, 1). Każdy uczestnik
 * zaraz PO zapytaniu `exists(... lower(email) = ?)` sięga po jej
 * współdzieloną wersję i staje w kolejce — czyli obaj NA PEWNO mają
 * sprawdzenie za sobą i obaj widzieli adres wolny. Po zwolnieniu bariery
 * idą naraz: pierwszy `UPDATE` wygrywa, drugi czeka na jego zatwierdzenie
 * i dostaje 23505. Dwa wywołania po kolei tego nie odtworzą — drugie
 * wyszłoby już na `$zajety`.
 *
 * Wariantu „różnią się tylko wielkością liter" tu NIE MA, bo w wyścigu
 * nie da się go ustawić: `pending_email_changes_new_email_lower_check`
 * przyjmuje wyłącznie małe litery, więc oba procesy zapisują identyczny
 * ciąg. Rozpoznanie `users_email_lower_unique` sprawdza
 * `tests/Feature/PotwierdzenieAdresuKonfliktIndeksuTest.php`.
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Że potwierdzanie adresu jest wolne od wyścigów w ogóle. Mierzy jeden
 * przeplot: ten ze zgłoszenia.
 */
#[Group('dwa-polaczenia')]
final class RownoleglePotwierdzeniaAdresuTest extends TestDwochPolaczen
{
    public function test_jeden_wygrywa_a_drugi_dostaje_komunikat_zamiast_500(): void
    {
        $wspolny = 'wspolny@'.bin2hex(random_bytes(4)).'.example.test';
        $a = $this->uczestnik($wspolny);
        $b = $this->uczestnik($wspolny);

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(1435, 1)', []);

        $procesA = $this->wTle('potwierdz-wspolny-adres', $a['argumenty']);
        $procesB = $this->wTle('potwierdz-wspolny-adres', $b['argumenty']);

        // KONTROLA DODATNIA PRZEPLOTU: obaj stoją za barierą, czyli obaj
        // przeszli już sprawdzenie „czy adres wolny" i widzieli go wolnym.
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wyniki = [$procesA->wynik(), $procesB->wynik()];

        foreach ($wyniki as $wynik) {
            $this->assertBezZakleszczenia($wynik, 'potwierdzenie wspólnego adresu');
        }

        $wygrani = array_keys(array_filter($wyniki, static fn (array $w): bool => $w['ok']));
        $this->assertCount(1, $wygrani, 'Dokładnie jedno potwierdzenie ma się udać: '.json_encode($wyniki, JSON_UNESCAPED_UNICODE));

        $zwyciezca = [$a, $b][$wygrani[0]];
        $przegrany = [$a, $b][1 - $wygrani[0]];
        $porazka = $wyniki[1 - $wygrani[0]];

        // Sedno: kontrolowany komunikat, a nie wyjątek bazy (czyli 500).
        $this->assertSame(BladDlaCzlowieka::class, $porazka['wyjatek'],
            'Przegrany dostał błąd techniczny zamiast komunikatu: '.$porazka['komunikat']);
        $this->assertStringContainsString('Na ten adres jest już założone inne konto', $porazka['komunikat']);

        $this->assertSame($wspolny, $zwyciezca['konto']->fresh()->email);

        // Przegrana transakcja wycofana w całości.
        $konto = $przegrany['konto'];
        $swiezy = $konto->fresh();
        $this->assertSame($konto->email, $swiezy->email, 'Przegrany stracił swój stary adres.');
        $this->assertSame($przegrany['remember'], $swiezy->remember_token, 'Przegranemu wymieniono token „zapamiętaj mnie".');
        $this->assertTrue(DB::table('sessions')->where('id', $przegrany['obca_sesja'])->exists(), 'Przegranemu skasowano sesje.');
        $this->assertSame(1, LoginLinkToken::query()->where('user_id', $konto->getKey())->count(), 'Przegranemu skasowano link logowania.');
        $this->assertTrue(PendingEmailChange::query()->whereKey($przegrany['argumenty']['zmiana'])->exists(),
            'Przegranemu skasowano oczekujące żądanie.');
        $this->assertSame(0, DB::table('audit_log')
            ->where('subject_id', $konto->getKey())
            ->where('action', 'account.email_changed')
            ->count(), 'Przegrany dostał fałszywy wpis account.email_changed.');

        // KONTROLA DODATNIA ASERCJI WYŻEJ: u zwycięzcy te same rzeczy
        // naprawdę się zmieniły, więc sprawdzamy tabele, które akcja rusza.
        $this->assertFalse(DB::table('sessions')->where('id', $zwyciezca['obca_sesja'])->exists());
        $this->assertSame(0, LoginLinkToken::query()->where('user_id', $zwyciezca['konto']->getKey())->count());
        $this->assertSame(1, DB::table('audit_log')
            ->where('subject_id', $zwyciezca['konto']->getKey())
            ->where('action', 'account.email_changed')
            ->count());
    }

    /**
     * Konto z oczekującą zmianą na `$nowyAdres`, obcą sesją, linkiem
     * logowania i tokenem „zapamiętaj mnie" — wszystkim, co przegrana
     * transakcja musi zostawić nietknięte.
     *
     * @return array{konto: User, remember: string, obca_sesja: string, argumenty: array<string, string>}
     */
    private function uczestnik(string $nowyAdres): array
    {
        $konto = $this->konto();
        $remember = Str::random(60);
        $konto->forceFill(['remember_token' => $remember])->save();

        $zmiana = new PendingEmailChange;
        $zmiana->user_id = $konto->getKey();
        $zmiana->new_email = $nowyAdres;
        $zmiana->created_at = now();
        $zmiana->expires_at = now()->addHour();
        $zmiana->save();

        $biezaca = Str::random(40);
        $obca = Str::random(40);
        foreach ([$biezaca, $obca] as $id) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $konto->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => '',
                'last_activity' => time(),
            ]);
        }

        $link = new LoginLinkToken;
        $link->user_id = $konto->getKey();
        $link->token_hash = LoginLinkToken::skrot(Str::random(40));
        $link->created_at = now();
        $link->expires_at = now()->addMinutes(30);
        $link->save();

        return [
            'konto' => $konto,
            'remember' => $remember,
            'obca_sesja' => $obca,
            'argumenty' => [
                'konto' => (string) $konto->getKey(),
                'zmiana' => (string) $zmiana->getKey(),
                'sesja' => $biezaca,
            ],
        ];
    }
}
