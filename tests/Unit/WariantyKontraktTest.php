<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Media\WariantyKontrakt;
use App\Domain\Media\WariantyMetadanychNiepelne;
use App\Models\Media;
use PHPUnit\Framework\TestCase;

/**
 * Walidator kontraktu `metadata.variants` w izolacji (issue #1905) — bez
 * bazy ani storage, żeby te przypadki brzegowe dało się sprawdzić szybko
 * i osobno od dwóch komend, które z niego korzystają.
 */
class WariantyKontraktTest extends TestCase
{
    private function media(string $status, mixed $variants): Media
    {
        $media = new Media;
        $media->forceFill(['status' => $status, 'metadata' => ['variants' => $variants]]);

        return $media;
    }

    public function test_poprawne_warianty_wracaja_jako_mapa_nazwa_klucz(): void
    {
        $media = $this->media(Media::STATUS_READY, [
            'thumb' => ['key' => 'media/a_thumb.webp'],
            'feed' => ['key' => 'media/a_feed.webp'],
        ]);

        $this->assertSame([
            'thumb' => 'media/a_thumb.webp',
            'feed' => 'media/a_feed.webp',
        ], WariantyKontrakt::wyciagnij($media));
    }

    public function test_pusta_tablica_przy_ready_rzuca(): void
    {
        $this->expectException(WariantyMetadanychNiepelne::class);

        WariantyKontrakt::wyciagnij($this->media(Media::STATUS_READY, []));
    }

    public function test_null_przy_ready_rzuca(): void
    {
        $this->expectException(WariantyMetadanychNiepelne::class);

        WariantyKontrakt::wyciagnij($this->media(Media::STATUS_READY, null));
    }

    public function test_brak_klucza_metadata_variants_w_ogole_przy_ready_rzuca(): void
    {
        $media = new Media;
        $media->forceFill(['status' => Media::STATUS_READY, 'metadata' => []]);

        $this->expectException(WariantyMetadanychNiepelne::class);

        WariantyKontrakt::wyciagnij($media);
    }

    public function test_wpis_bez_klucza_key_przy_ready_rzuca(): void
    {
        $this->expectException(WariantyMetadanychNiepelne::class);

        WariantyKontrakt::wyciagnij($this->media(Media::STATUS_READY, [
            'feed' => ['width' => 640],
        ]));
    }

    public function test_pusty_klucz_key_przy_ready_rzuca(): void
    {
        $this->expectException(WariantyMetadanychNiepelne::class);

        WariantyKontrakt::wyciagnij($this->media(Media::STATUS_READY, [
            'feed' => ['key' => ''],
        ]));
    }

    public function test_wpis_ktory_nie_jest_tablica_przy_ready_rzuca(): void
    {
        $this->expectException(WariantyMetadanychNiepelne::class);

        WariantyKontrakt::wyciagnij($this->media(Media::STATUS_READY, [
            'feed' => 'media/a_feed.webp',
        ]));
    }

    /** Kontrola dodatnia dla "listy zamiast mapy": klucze numeryczne nie są nazwami wariantów. */
    public function test_lista_zamiast_mapy_przy_ready_rzuca(): void
    {
        $this->expectException(WariantyMetadanychNiepelne::class);

        WariantyKontrakt::wyciagnij($this->media(Media::STATUS_READY, [
            ['key' => 'media/a_feed.webp'],
        ]));
    }

    /**
     * Statusy inne niż `ready` z definicji mogą nie mieć wariantów jeszcze —
     * to NIE jest błąd danych, tylko pusty wynik.
     */
    public function test_pusta_tablica_przy_pending_nie_rzuca_i_daje_pusta_mape(): void
    {
        $this->assertSame([], WariantyKontrakt::wyciagnij($this->media(Media::STATUS_PENDING, [])));
    }

    public function test_null_przy_pending_nie_rzuca_i_daje_pusta_mape(): void
    {
        $this->assertSame([], WariantyKontrakt::wyciagnij($this->media(Media::STATUS_PENDING, null)));
    }

    /** Wpis uszkodzony przy statusie innym niż `ready` jest po prostu pomijany, jak w kodzie sprzed #1905. */
    public function test_wpis_uszkodzony_przy_pending_jest_pomijany_bez_wyjatku(): void
    {
        $wynik = WariantyKontrakt::wyciagnij($this->media(Media::STATUS_PENDING, [
            'feed' => ['width' => 640],
            'thumb' => ['key' => 'media/a_thumb.webp'],
        ]));

        $this->assertSame(['thumb' => 'media/a_thumb.webp'], $wynik);
    }
}
