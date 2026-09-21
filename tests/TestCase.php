<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    /**
     * Testy nie zależą od zbudowanych assetów.
     *
     * Bez tego każdy test renderujący layout wymagałby wcześniejszego
     * `npm run build`, bo `@vite` szuka `public/build/manifest.json` —
     * a ten katalog jest w `.gitignore`. W CI job `test` celowo nie buduje
     * front-endu (robi to osobny job `assets`), więc testy padałyby na
     * ViteManifestNotFoundException zamiast sprawdzać cokolwiek z aplikacji.
     *
     * To, że manifest naprawdę powstaje, weryfikuje job `assets`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->wyzerujStanLivewire();
    }

    /**
     * ZERUJE STAN LIVEWIRE'A, KTÓRY PRZECIEKA MIĘDZY TESTAMI W JEDNYM PROCESIE.
     *
     * `Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets`
     * trzyma DWIE STATYKI KLASOWE — `$hasRenderedAComponentThisRequest`
     * (podnoszoną w `dehydrate()`, czyli przy KAŻDYM wyrenderowanym
     * komponencie) i `$forceAssetInjection`. Statyka klasowa żyje tyle, co
     * proces PHP: `Illuminate\Foundation\Testing\TestCase::tearDown()`
     * wyrzuca kontener aplikacji, ale klasy nie dotyka. Livewire zeruje te
     * flagi WYŁĄCZNIE na zdarzeniu `flush-state`, a `flush-state` leci tylko
     * z `Livewire::flushState()` — które w całym vendorze woła u siebie sam
     * `Livewire::test()` (`SupportTesting/InitialRender.php`,
     * `SupportTesting/SubsequentRender.php`). Zwykłe żądanie HTTP w teście
     * NIE woła tego nigdy.
     *
     * Skutek bez tego zerowania: gdy wcześniej w tym samym procesie PHP
     * jakikolwiek test wyrenderował stronę z komponentem Livewire'a (np.
     * `/przepisy/{slug}/szczegoly`, albo test przejeżdżający WSZYSTKIE trasy
     * — `KazdaTrasaZIdentyfikatoremPodPolicyTest`,
     * `AutoryzacjaTrasZWiazaniemModeluTest`), to nasłuch `RequestHandled`
     * dokleja `@livewireScripts` do KAŻDEJ następnej odpowiedzi 200 text/html
     * w tym procesie — także na stronach, które Livewire'a nie używają.
     * `NapiszDoNasTest::test_formularz_dziala_bez_javascriptu` pada wtedy na
     * `livewire.js`, choć w samej aplikacji nie zmieniło się nic.
     *
     * To NIE jest przypadłość wyłącznie `--parallel`: zmierzone szeregowo,
     * `--filter='KazdaTrasaZIdentyfikatoremPodPolicyTest|NapiszDoNasTest'`
     * dawało czerwień, a `--filter='...|NapiszDoNasTest'` z niewinną klasą
     * obok — zieleń. Pełna bateria bywała zielona tylko dlatego, że między te
     * klasy trafiał się test wołający `Livewire::test()`, czyli zerujący
     * flagę przypadkiem. To szczęście, nie zabezpieczenie.
     *
     * DLACZEGO W `setUp()`, A NIE W `tearDown()`: tak zerowanie jest
     * niezależne od tego, czy któraś z ~20 klas nadpisujących `tearDown()`
     * woła `parent::tearDown()` i w którym miejscu (po `parent::tearDown()`
     * kontener już nie istnieje, więc `flushState()` by się wywrócił).
     * `setUp()` biegnie dla każdego testu, zawsze na świeżym kontenerze.
     *
     * KOSZT: jedno `trigger('flush-state')` na test — przejście po liście
     * nasłuchów i wyzerowanie kilkunastu tablic w pamięci. To DOKŁADNIE to
     * samo, co Livewire robi sam po każdym `Livewire::test()`.
     *
     * STRAŻNIK REGRESJI:
     * `tests/Feature/StanLivewireNiePrzeciekaMiedzyTestamiTest.php`.
     */
    private function wyzerujStanLivewire(): void
    {
        Livewire::flushState();
    }

    /**
     * Skrót do tworzenia użytkownika z profilem i czytelną nazwą.
     *
     * Testy Kuking prawie zawsze potrzebują profilu (adresy /@nazwa, widoki),
     * więc tworzenie samego User-a byłoby ciągłym źródłem fałszywych błędów.
     */
    protected function user(?string $username = null, array $attributes = []): User
    {
        // display_name należy do profilu, nie do konta — wyjmujemy je,
        // zanim resztę atrybutów przekażemy fabryce User-a.
        $displayName = $attributes['display_name'] ?? 'Testowa osoba';
        unset($attributes['display_name']);

        $user = User::factory()->create($attributes);

        Profile::query()->where('user_id', $user->getKey())->delete();

        Profile::create([
            'user_id' => $user->getKey(),
            'username' => $username ?? Str::lower(Str::random(12)),
            'display_name' => $displayName,
        ]);

        return $user->refresh();
    }

    /**
     * Moderator z POTWIERDZONYM 2FA — to jest domyślny, zgodny z produkcją
     * stan tego konta (issue #12: 2FA jest obowiązkowe dla `/admin/**`,
     * `EnsureModeratorHasTwoFactor`). Setki testów w tym repo wołają
     * `$this->moderator()`, żeby sprawdzić coś w PANELU MODERACJI, a nie
     * samo 2FA — gdyby ta metoda zwracała moderatora bez włączonego 2FA,
     * każdy z nich padałby na 403 z naszego middleware, zanim w ogóle
     * dotarłby do sprawdzanej logiki.
     *
     * Testy, którym zależy WŁAŚNIE na koncie moderatora BEZ 2FA (np.
     * `DwuetapowaWeryfikacjaTest::test_moderator_bez_2fa_nie_wchodzi_do_panelu`),
     * budują je same, bezpośrednio przez `$this->user(..., ['role' => ...])`
     * — nie przez tę metodę.
     */
    protected function moderator(): User
    {
        $moderator = $this->user(null, ['role' => User::ROLE_MODERATOR]);

        $totp = app(TwoFactorAuthenticator::class);
        $moderator->beginTwoFactorSetup($totp->generateSecret());
        $moderator->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));

        return $moderator->refresh();
    }

    /**
     * Administrator z POTWIERDZONYM 2FA (A-4: rozstrzyganie odwołań od decyzji
     * moderacyjnych wymaga roli `admin`, nie samego `moderator` — patrz
     * `UserPolicy::resolveAppeals()`). Ten sam powód co przy `moderator()`
     * powyżej: bez potwierdzonego 2FA każdy test wołający tę metodę padałby
     * na `EnsureModeratorHasTwoFactor`, zanim dotarłby do sprawdzanej logiki.
     *
     * `User::isModerator()` jest prawdziwe też dla `role === ROLE_ADMIN`,
     * więc konto z tej metody przechodzi RÓWNIEŻ przez każdą bramkę, która
     * do tej pory wymagała `moderator()` (np. `admin.reports.decide`,
     * podgląd kolejki odwołań) — nadaje się jako zamiennik wszędzie tam,
     * gdzie test i tak nie sprawdza różnicy między rolami.
     */
    protected function admin(): User
    {
        $admin = $this->user(null, ['role' => User::ROLE_ADMIN]);

        $totp = app(TwoFactorAuthenticator::class);
        $admin->beginTwoFactorSetup($totp->generateSecret());
        $admin->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));

        return $admin->refresh();
    }
}
