<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pusty stan listy powiadomień (#2060).
 *
 * @bez-kontroli-dodatniej Skan app/ na miejsca powstawania typów sprawdzono ręcznie przy #2060 (atrapowy typ, usunięte miejsce powstania i usunięty fragment tekstu oblewają test); wpis w scripts/kontrole-negatywne-alfa08.py zostaje koordynatorowi, bo ta fala nie pozwala edytować tego skryptu.
 */
class PustyStanPowiadomienTest extends TestCase
{
    use RefreshDatabase;

    public function test_pusta_lista_wyjasnia_pelniejszy_zakres_powiadomien(): void
    {
        $this->actingAs($this->user('ala'))
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Nie ma jeszcze żadnych powiadomień')
            ->assertSee('Tu zobaczysz powiadomienia o Twoich przepisach i wpisach, nowych obserwujących, urodzinach obserwowanych osób, zaproszeniach do wspólnego zeszytu oraz ważnych sprawach Twojego konta.')
            ->assertDontSee('Tu pojawi się informacja, kiedy ktoś ugotuje z Twojego przepisu albo napisze komentarz.');
    }

    public function test_opis_pustej_listy_nie_zastepuje_prawdziwego_powiadomienia(): void
    {
        $ala = $this->user('ala');
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        app(NotifyUser::class)->handle(
            recipient: $ala,
            type: Notification::TYPE_FOLLOW,
            actor: $basia,
            data: ['username' => 'basia'],
        );

        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Basia')
            ->assertDontSee('Nie ma jeszcze żadnych powiadomień')
            ->assertDontSee('Tu zobaczysz powiadomienia o Twoich przepisach i wpisach, nowych obserwujących, urodzinach obserwowanych osób, zaproszeniach do wspólnego zeszytu oraz ważnych sprawach Twojego konta.');
    }

    /**
     * Opis pustej listy wymienia KATEGORIE, nie pojedyncze zdarzenia — więc
     * test liczy typy z kodu (`Notification::TYPE_*`), a nie z palca.
     *
     * Pilnuje dwóch rzeczy naraz:
     *  1. każdy typ, który istnieje w modelu, ma w `app/` miejsce, gdzie
     *     powiadomienie tego typu naprawdę powstaje — opis nie może obiecywać
     *     kategorii, której żadne zdarzenie nie zasila;
     *  2. każdy typ jest świadomie przypisany: do fragmentu opisu, który
     *     widać na pustej liście, albo do jawnej listy wyjątków z powodem.
     *     Nowy typ bez decyzji o pustym stanie oblewa ten test.
     */
    public function test_opis_pustej_listy_pokrywa_typy_powiadomien_z_kodu(): void
    {
        $przepisyIWpisy = 'Twoich przepisach i wpisach';
        $obserwujacy = 'nowych obserwujących';
        $urodziny = 'urodzinach obserwowanych osób';
        $zeszyt = 'zaproszeniach do wspólnego zeszytu';
        $konto = 'ważnych sprawach Twojego konta';

        $wymienione = [
            'TYPE_COOKED' => $przepisyIWpisy,
            'TYPE_COMMENT' => $przepisyIWpisy,
            'TYPE_REPLY' => $przepisyIWpisy,
            // „Dziękuję” pod komentarzem (#2355) — rozmowa pod treściami; to samo zdanie co komentarz i odpowiedź.
            'TYPE_COMMENT_THANKED' => $przepisyIWpisy,
            'TYPE_SAVED' => $przepisyIWpisy,
            'TYPE_FORKED' => $przepisyIWpisy,
            // Prośba o zgodę na wskazówkę przy przepisie (#2352, D-333).
            'TYPE_HINT_PROPOSED' => $przepisyIWpisy,
            // Zgoda kucharza na wskazówkę przy przepisie (#2352).
            'TYPE_HINT_ACCEPTED' => $przepisyIWpisy,
            'TYPE_SMAKOWICIE' => $przepisyIWpisy,
            'TYPE_FOLLOW' => $obserwujacy,
            'TYPE_BIRTHDAY' => $urodziny,
            // Wspólny zeszyt (#1743, D-302): zaproszenie i jego przyjęcie.
            'TYPE_COLLECTION_INVITED' => $zeszyt,
            'TYPE_COLLECTION_JOINED' => $zeszyt,
            'TYPE_WELCOME' => $konto,
            'TYPE_MODERATION' => $konto,
            'TYPE_REPORT_RECEIVED' => $konto,
            'TYPE_REPORT_DECIDED' => $konto,
        ];

        $wyjatki = [
            // Tylko dla gospodarza/administratora — nie obiecujemy ich każdemu.
            'TYPE_FIRST_POST' => 'tylko gospodarz',
            'TYPE_APPEAL_FILED' => 'tylko administrator',
        ];

        $typyZKodu = array_keys(array_filter(
            (new \ReflectionClass(Notification::class))->getConstants(),
            fn (mixed $wartosc, string $nazwa): bool => str_starts_with($nazwa, 'TYPE_'),
            ARRAY_FILTER_USE_BOTH,
        ));
        sort($typyZKodu);

        $przypisane = array_keys($wymienione + $wyjatki);
        sort($przypisane);

        $this->assertSame(
            $typyZKodu,
            $przypisane,
            'Każdy Notification::TYPE_* musi być przypisany do fragmentu opisu pustej listy albo do jawnego wyjątku.',
        );

        $kodAplikacji = '';
        $pliki = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($pliki as $plik) {
            if ($plik->getExtension() === 'php' && $plik->getRealPath() !== realpath(app_path('Models/Notification.php'))) {
                $kodAplikacji .= file_get_contents($plik->getRealPath())."\n";
            }
        }

        foreach ($typyZKodu as $typ) {
            $this->assertSame(
                1,
                preg_match('/(?:\btype:|\'type\'\s*=>|->handle\(\$\w+,)[^;,]*?Notification::'.$typ.'\b/', $kodAplikacji),
                "Notification::{$typ} nie powstaje nigdzie w app/ — opis pustej listy nie może go obiecywać.",
            );
        }

        $strona = $this->actingAs($this->user('ala'))
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Nie ma jeszcze żadnych powiadomień');

        foreach (array_unique($wymienione) as $fragment) {
            $strona->assertSee($fragment);
        }
    }
}
