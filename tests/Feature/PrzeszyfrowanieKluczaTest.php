<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PrzeszyfrujKlucz;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rotacja `APP_KEY` (issue #1043): `kuking:przeszyfruj-klucz` przepisuje
 * sekret 2FA i kody zapasowe z poprzedniego klucza na bieżący, tak żeby po
 * okresie przejściowym stary klucz dało się usunąć z `APP_PREVIOUS_KEYS`
 * bez odcinania nikogo od konta.
 *
 * Klucze są losowane w teście, na czas testu — żaden nie trafia do repo.
 */
class PrzeszyfrowanieKluczaTest extends TestCase
{
    use RefreshDatabase;

    private string $staryKlucz;

    private string $nowyKlucz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staryKlucz = self::losowyKlucz();
        $this->nowyKlucz = self::losowyKlucz();
    }

    public function test_po_przepisaniu_dane_czyta_sam_nowy_klucz(): void
    {
        $this->uzywajKluczy($this->staryKlucz);
        $basia = $this->kontoZ2fa('basia');

        // Rotacja: nowy klucz bieżący, stary w APP_PREVIOUS_KEYS.
        $this->uzywajKluczy($this->nowyKlucz, [$this->staryKlucz]);

        $this->artisan('kuking:przeszyfruj-klucz')
            ->expectsOutputToContain('Przepisano 2 wartości na bieżący klucz.')
            ->assertSuccessful();

        $surowe = $this->surowe($basia);

        foreach (PrzeszyfrujKlucz::KOLUMNY as $kolumna) {
            self::szyfrator($this->nowyKlucz)->decryptString($surowe[$kolumna]);

            try {
                self::szyfrator($this->staryKlucz)->decryptString($surowe[$kolumna]);
                $this->fail("{$kolumna} nadal daje się odczytać starym kluczem.");
            } catch (DecryptException) {
                // Oczekiwane — stary klucz już nic tu nie otwiera.
            }
        }

        // Koniec okresu przejściowego: stary klucz usunięty. Model czyta
        // wszystko samym nowym.
        $this->uzywajKluczy($this->nowyKlucz);

        $swieza = User::query()->findOrFail($basia->getKey());
        $this->assertSame('SEKRET-TOTP-BASI', $swieza->two_factor_secret);
        $this->assertSame(['hash-1', 'hash-2'], $swieza->two_factor_backup_codes);
        $this->assertTrue($swieza->hasTwoFactorConfirmed());
    }

    public function test_drugi_przebieg_niczego_nie_zmienia(): void
    {
        $this->uzywajKluczy($this->staryKlucz);
        $basia = $this->kontoZ2fa('basia');
        $this->uzywajKluczy($this->nowyKlucz, [$this->staryKlucz]);

        $this->artisan('kuking:przeszyfruj-klucz')->assertSuccessful();
        $poPierwszym = $this->surowe($basia);

        $this->artisan('kuking:przeszyfruj-klucz')
            ->expectsOutputToContain('Przepisano 0 wartości')
            ->expectsOutputToContain('Już zaszyfrowane bieżącym kluczem: 2 wartości.')
            ->assertSuccessful();

        $this->assertSame($poPierwszym, $this->surowe($basia));
    }

    public function test_partiami_obejmuje_wszystkie_konta_i_nie_rusza_updated_at(): void
    {
        $this->uzywajKluczy($this->staryKlucz);
        $konta = collect(['ala', 'basia', 'celina'])->map(fn (string $kto): User => $this->kontoZ2fa($kto));
        $bez2fa = $this->user('darek');
        $przed = $konta->mapWithKeys(fn (User $u): array => [$u->getKey() => $this->surowe($u)['updated_at']]);

        $this->uzywajKluczy($this->nowyKlucz, [$this->staryKlucz]);

        $this->artisan('kuking:przeszyfruj-klucz', ['--partia' => 1])
            ->expectsOutputToContain('Przepisano 6 wartości')
            ->assertSuccessful();

        $this->uzywajKluczy($this->nowyKlucz);

        foreach ($konta as $konto) {
            $this->assertSame('SEKRET-TOTP-BASI', User::query()->findOrFail($konto->getKey())->two_factor_secret);
            $this->assertSame($przed[$konto->getKey()], $this->surowe($konto)['updated_at']);
        }

        $this->assertNull($this->surowe($bez2fa)['two_factor_secret']);
    }

    public function test_na_sucho_liczy_ale_nie_zapisuje(): void
    {
        $this->uzywajKluczy($this->staryKlucz);
        $basia = $this->kontoZ2fa('basia');
        $przed = $this->surowe($basia);
        $this->uzywajKluczy($this->nowyKlucz, [$this->staryKlucz]);

        $this->artisan('kuking:przeszyfruj-klucz', ['--na-sucho' => true])
            ->expectsOutputToContain('Do przepisania: 2 wartości. Nic nie zapisano.')
            ->assertSuccessful();

        $this->assertSame($przed, $this->surowe($basia));
    }

    public function test_wartosc_nieczytelna_zadnym_kluczem_zostaje_i_komenda_konczy_sie_bledem(): void
    {
        // Zaszyfrowane kluczem, którego NIE MA ani w APP_KEY, ani w
        // APP_PREVIOUS_KEYS — czyli ktoś usunął stary klucz za wcześnie.
        $this->uzywajKluczy(self::losowyKlucz());
        $basia = $this->kontoZ2fa('basia');
        $przed = $this->surowe($basia);

        $this->uzywajKluczy($this->nowyKlucz, [$this->staryKlucz]);

        $this->artisan('kuking:przeszyfruj-klucz')
            ->expectsOutputToContain('Nieczytelne żadnym kluczem: 2 wartości')
            ->assertFailed();

        $this->assertSame($przed, $this->surowe($basia));
    }

    /**
     * Lista kolumn w komendzie jest ręczna. Kolumna z castem `encrypted*`
     * dodana w modelu, a pominięta w komendzie, po usunięciu starego klucza
     * stałaby się nieczytelna — ten test pęka wcześniej.
     */
    public function test_komenda_zna_kazda_zaszyfrowana_kolumne_w_modelach(): void
    {
        $zaszyfrowane = [];

        foreach (glob(app_path('Models/*.php')) as $plik) {
            $klasa = 'App\\Models\\'.basename($plik, '.php');

            if (! is_subclass_of($klasa, Model::class) || (new \ReflectionClass($klasa))->isAbstract()) {
                continue;
            }

            foreach ((new $klasa)->getCasts() as $kolumna => $cast) {
                if (is_string($cast) && str_starts_with($cast, 'encrypted')) {
                    $zaszyfrowane[] = $klasa.'::'.$kolumna;
                }
            }
        }

        $oczekiwane = array_map(fn (string $k): string => User::class.'::'.$k, PrzeszyfrujKlucz::KOLUMNY);

        sort($zaszyfrowane);
        sort($oczekiwane);

        $this->assertSame($oczekiwane, $zaszyfrowane);
    }

    private function kontoZ2fa(string $kto): User
    {
        $user = $this->user($kto);
        $user->forceFill([
            'two_factor_secret' => 'SEKRET-TOTP-BASI',
            'two_factor_backup_codes' => ['hash-1', 'hash-2'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function surowe(User $user): array
    {
        return (array) DB::table('users')
            ->where('id', $user->getKey())
            ->first(['two_factor_secret', 'two_factor_backup_codes', 'updated_at']);
    }

    /**
     * Ustawia klucze tak, jak zrobiłyby to zmienne `APP_KEY` i
     * `APP_PREVIOUS_KEYS`, i każe kontenerowi zbudować szyfrator od nowa.
     *
     * @param  list<string>  $poprzednie
     */
    private function uzywajKluczy(string $biezacy, array $poprzednie = []): void
    {
        config(['app.key' => $biezacy, 'app.previous_keys' => $poprzednie]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private static function losowyKlucz(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));
    }

    private static function szyfrator(string $klucz): Encrypter
    {
        return new Encrypter(base64_decode(substr($klucz, 7)), 'AES-256-CBC');
    }
}
