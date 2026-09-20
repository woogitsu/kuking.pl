<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pwa\InstallPromptContext;
use App\Models\User;
use Tests\TestCase;

class KontekstInstalacjiPwaTest extends TestCase
{
    public function test_kontekst_nie_przechodzi_miedzy_kontami_sesjami_ani_po_wygasnieciu(): void
    {
        $this->freezeTime();
        $basia = new User;
        $basia->id = 'konto-basi';
        $marek = new User;
        $marek->id = 'konto-marka';
        $context = new InstallPromptContext;
        $token = $context->issue($basia, 'sesja-pierwsza');

        $this->assertTrue($context->valid($token, $basia, 'sesja-pierwsza'));
        $this->assertFalse($context->valid($token, $marek, 'sesja-pierwsza'));
        $this->assertFalse($context->valid($token, $basia, 'sesja-po-logowaniu'));
        $this->assertFalse($context->valid('uszkodzony-token', $basia, 'sesja-pierwsza'));

        $this->travel(59)->minutes();
        $this->assertTrue($context->valid($token, $basia, 'sesja-pierwsza'));
        $this->travel(1)->minutes();
        $this->assertFalse($context->valid($token, $basia, 'sesja-pierwsza'));
    }
}
