<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\ModerationAction;
use App\Models\User;

/**
 * Kontrakt: „cofam decyzję” po uznanym odwołaniu od ukrycia WERSJI przepisu
 * (#2270, decyzja właściciela z 30.09.2026).
 *
 * Wersja nie ma `status`, więc `RestoreContent` jej nie przywróci — wraca
 * przez `hidden_at`, którym rządzi moduł `Recipes`
 * (`App\Domain\Recipes\Historia\DecyzjaOWersjiPrzepisu`). `ResolveAppeal`
 * woła ten kontrakt, a nie tamtą klasę: `Recipes` zależy już od `Moderation`
 * (przez `Posts`), więc bezpośredni import zamknąłby cykl modułów (#971).
 * Wiązanie w `AppServiceProvider` — ten sam wzorzec co
 * `App\Support\OdswiezanieLicznikowKolejek`.
 */
interface CofniecieUkryciaWersji
{
    /** Nazwa typu w `moderation_actions.target_type` (`ModeratedContent::TYPY`). */
    public const TYP = 'recipe_version';

    /**
     * Wołane pod zamkiem aktora, który trzyma `ResolveAppeal::handle()`.
     *
     * @param  User  $swiezy  aktor odczytany pod blokadą
     * @return ?string zdanie dla autora, gdy wersja NIE stała się publiczna, bo
     *                 uznane odwołanie dotyczyło przejęcia jego ukrycia i wersja
     *                 wróciła do ukrycia autora (decyzja z 30.09.2026); inaczej `null`
     *
     * @throws WlasnejTresciNiePrzywracasz gdy rozpatrujący jest autorem przepisu
     * @throws BladDlaCzlowieka gdy nie ma czego przywracać
     */
    public function poOdwolaniu(User $swiezy, ModerationAction $decyzja, string $uzasadnienie, ?string $ip = null): ?string;
}
