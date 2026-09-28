<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Report;
use PHPUnit\Framework\Assert;

/**
 * Znacznik stanu grupy sygnałów automatu — to, co formularz „To nic
 * takiego — zamknij wszystkie” niesie z ekranu (#1059): liczba otwartych
 * oznaczeń grupy i identyfikator najnowszego z nich (ta sama kolejność co
 * w `SygnalyController`). Dla testów, które nie mierzą samego formularza;
 * `ZbiorczeZamkniecieSygnalowTylkoZEkranuTest` czyta znacznik z HTML-a.
 */
trait StanGrupySygnalow
{
    /** @return array{stan_ile: int, stan_najnowsze: string} */
    private function stanGrupySygnalow(?string $autorId): array
    {
        $otwarte = Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING])
            ->when($autorId === null,
                fn ($q) => $q->whereNull('autor_tresci_id'),
                fn ($q) => $q->where('autor_tresci_id', $autorId),
            );

        $najnowsze = (clone $otwarte)->orderByDesc('created_at')->orderByDesc('id')->value('id');
        Assert::assertNotNull($najnowsze, 'Grupa nie ma otwartych oznaczeń — nie ma czego zamykać.');

        return ['stan_ile' => $otwarte->count(), 'stan_najnowsze' => (string) $najnowsze];
    }
}
