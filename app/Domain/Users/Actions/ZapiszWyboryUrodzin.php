<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Zgody\PrzestawZgodeNaZyczeniaMailem;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wybory przy dacie urodzin: życzenia na stronie (etap b), zgoda na mail
 * (etap c) i przypomnienie obserwującym (etap d) — jednym zapisem, pod jedną
 * blokadą.
 *
 * STAN Z CHWILI OTWARCIA FORMULARZA (jak w ustawieniach prywatności, #879).
 * Formularz niesie zgodę, więc otwarty wczoraj nie może dziś po cichu zapisać
 * człowieka z powrotem na mail, z którego wypisał się odnośnikiem w liście.
 * Przy rozjeździe nie zapisujemy NICZEGO.
 */
final class ZapiszWyboryUrodzin
{
    public function __construct(private readonly PrzestawZgodeNaZyczeniaMailem $zgoda) {}

    public function handle(User $user, bool $zyczenia, bool $mail, bool $mailPrzyOtwarciu, bool $obserwujacym = false): void
    {
        DB::transaction(function () use ($user, $zyczenia, $mail, $mailPrzyOtwarciu, $obserwujacym): void {
            $current = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ((bool) $current->wants_birthday_email !== $mailPrzyOtwarciu) {
                throw ValidationException::withMessages([
                    'wants_birthday_email' => 'Zgoda na e-mail z życzeniami zmieniła się od otwarcia formularza. Otwórz aktualne ustawienia i wybierz ponownie.',
                ]);
            }

            $current->forceFill([
                'birthday_wishes_enabled' => $zyczenia,
                // Etap d: przypomnienie obserwującym — tylko po jawnym włączeniu.
                'birthday_visible_to_followers' => $obserwujacym,
            ])->save();
            $this->zgoda->handle($current, $mail, WpisZgody::ZRODLO_USTAWIENIA);
            $user->setRawAttributes($current->getAttributes(), true);
        });
    }
}
