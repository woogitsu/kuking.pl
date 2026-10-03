<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** #2708: instrukcja nie może odwrócić faktycznego zakresu panelu. */
class ZakresCsamWInstrukcjiTest extends TestCase
{
    public function test_instrukcja_odroznia_obslugiwane_media_od_nieobslugiwanych_tresci_i_pilota(): void
    {
        $katalog = dirname(__DIR__, 2);
        $instrukcja = (string) file_get_contents($katalog.'/docs/legal/MODERATION_PLAYBOOK.md');
        $akcja = (string) file_get_contents($katalog.'/app/Domain/Moderation/Actions/ZabezpieczDowodCsam.php');
        $panel = (string) file_get_contents($katalog.'/resources/views/pages/admin/reports.blade.php');

        self::assertStringContainsString("'media' => Media::class", $akcja);
        self::assertStringContainsString('array_key_exists($report->target_type, \\App\\Domain\\Moderation\\Actions\\ZabezpieczDowodCsam::TYPY)', $panel);
        self::assertStringContainsString('osobne zdjęcie i awatar', $instrukcja, 'CSAM_2708_MEDIA_NIE_SA_POZA_ZAKRESEM');
        self::assertStringContainsString('zgłoszeniu typu `media`', $instrukcja);
        self::assertStringNotContainsString('awatar, zeszyt (kolekcja) i samo zdjęcie', $instrukcja);
        self::assertStringContainsString('`cooked_event`', $instrukcja);
        self::assertStringContainsString('`collection`', $instrukcja);
        self::assertStringContainsString('Próba na wdrożonym R2/CDN nadal nie została wykonana', $instrukcja);
    }

    public function test_tabela_nie_opisuje_juz_starej_instrukcji_zglaszania(): void
    {
        $katalog = dirname(__DIR__, 2);
        $instrukcja = (string) file_get_contents($katalog.'/docs/legal/MODERATION_PLAYBOOK.md');
        $panel = (string) file_get_contents($katalog.'/resources/views/pages/admin/csam/_instrukcja.blade.php');

        self::assertStringContainsString('Zawiadom bezpośrednio Policję albo prokuraturę, bez zbędnej zwłoki', $panel);
        self::assertStringContainsString('Dyżurnet.pl</a>', $panel);
        self::assertStringContainsString('każe zawiadomić bezpośrednio Policję albo prokuraturę bez zbędnej zwłoki', $instrukcja, 'CSAM_2708_TABELA_ZGODNA_Z_EKRANEM');
        self::assertStringNotContainsString('nadal podaje stary porządek', $instrukcja);
    }
}
