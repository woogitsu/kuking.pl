<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Push\TrescPush;
use App\Jobs\WyslijPowiadomieniePush;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Granica rozmiaru Web Push (issue #2021, decyzja właściciela z 28.09.2026:
 * grupa bez arbitralnego limitu liczby powiadomień, więc rozmiar musi być
 * ograniczony STRUKTURALNIE, nie liczbą wierszy).
 *
 * Treść pushu niesie jedno zdanie (imię ≤ `dlugosc_nazwy` znaków, tytuł
 * przepisu przycięty do 80) i liczbę. Usługi push przyjmują ok. 4 KB
 * (RFC 8291: 4078 B tekstu jawnego). Test bierze najgorszy przypadek —
 * same emoji (4 B w UTF-8, 12 B po ucieczce `\uXXXX`), maksymalna grupa
 * — i sprawdza, że mieści się z zapasem, a liczba w grupie jej nie zmienia.
 *
 * @bez-kontroli-dodatniej test liczy bajty wyliczone z kodu, kontrola dodatnia jest w asercjach o niepustym tekście
 */
final class PushGranicaPayloaduTest extends TestCase
{
    private const LIMIT_RFC8291 = 4078;

    private const ZAPAS = 1024;

    public function test_najgorsza_tresc_miesci_sie_w_limicie_z_zapasem(): void
    {
        $imie = str_repeat('🍲', (int) config('kuking.profil.dlugosc_nazwy'));
        $tytul = str_repeat('🍜', 1000);

        foreach ([1, 3001, 10_000_000] as $ile) {
            $tresc = TrescPush::zGrupy($this->powiadomienie($imie, $tytul), $ile);

            foreach ([JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 0] as $flagi) {
                $bajty = strlen((string) json_encode($tresc, $flagi));
                $this->assertGreaterThan(200, $bajty, 'Kontrola dodatnia: treść nie jest pusta.');
                $this->assertLessThanOrEqual(self::LIMIT_RFC8291 - self::ZAPAS, $bajty, "Grupa {$ile}, flagi {$flagi}: {$bajty} B.");
            }
        }

        $this->assertStringContainsString('9999999 innych powiadomień', TrescPush::zGrupy($this->powiadomienie($imie, $tytul), 10_000_000)['body']);
    }

    public function test_dlugi_tytul_jest_przyciety_do_80_znakow(): void
    {
        $zdanie = TrescPush::zdanie($this->powiadomienie('Ala', str_repeat('🍲', 5000)));

        $this->assertStringContainsString('🍲🍲🍲', $zdanie, 'Kontrola dodatnia: tytuł jest w zdaniu.');
        $this->assertLessThan(500, mb_strlen($zdanie), 'Tytuł 5000 znaków nie wchodzi w całości.');
        $this->assertStringContainsString('...”', $zdanie, 'Przycięcie jest widoczne.');
    }

    public function test_serializowany_retry_jest_maly_przy_maksymalnej_liczbie_urzadzen(): void
    {
        $uuid = static fn (): string => (string) Str::uuid();
        $urzadzenia = array_map($uuid, range(1, (int) config('kuking.notifications.zewnetrzne.push_maks_urzadzen')));

        $retry = new WyslijPowiadomieniePush($uuid(), [], null, $urzadzenia, 3, $uuid());
        $bajty = strlen(serialize($retry));

        $this->assertGreaterThan(300, $bajty, 'Kontrola dodatnia: pominięte urządzenia są w payloadzie.');
        $this->assertLessThan(2048, $bajty, "Retry ma stały rozmiar, tu {$bajty} B.");
    }

    private function powiadomienie(string $imie, string $tytul): Notification
    {
        $aktor = new User;
        $aktor->setRelation('profile', new Profile(['display_name' => $imie]));
        $n = new Notification(['type' => Notification::TYPE_COOKED, 'data' => ['recipe_title' => $tytul]]);
        $n->setRelation('actor', $aktor);

        return $n;
    }
}
