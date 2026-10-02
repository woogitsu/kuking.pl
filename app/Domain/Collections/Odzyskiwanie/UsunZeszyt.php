<?php

declare(strict_types=1);

namespace App\Domain\Collections\Odzyskiwanie;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * „Usuń ten zeszyt" z możliwością odzyskania (#2567, decyzja właściciela
 * z 2.10.2026, D-333).
 *
 * CO SIĘ DZIEJE
 * W jednej transakcji, pod blokadą (kolejność `users` -> `collections`, jak
 * przy zapisie do zeszytu): jeżeli zeszyt kwalifikuje się do odzyskania,
 * powstaje jego KOPIA (`deleted_collections`: nazwa, opis, data założenia,
 * pozycje z dopiskami i datami zapisania), a dopiero potem zeszyt jest
 * usuwany tak jak dotąd. Zeszyt, który się nie kwalifikuje, jest usuwany
 * dokładnie jak przed tą zmianą, a wynik mówi, DLACZEGO nie da się go
 * odzyskać — komunikat nie obiecuje nic ponad prawdę.
 *
 * CO SIĘ KWALIFIKUJE (pilot z issue: wąsko i bezpiecznie)
 *  - konto właściciela jest AKTYWNE (odzyskanie to pisanie);
 *  - zeszyt jest PRYWATNY, niedomyślny, bez członków i bez oczekujących
 *    zaproszeń — publiczny albo wspólny zeszyt po usunięciu znika na stałe,
 *    jak dotąd, żeby odzyskanie nigdy nie przywróciło widoczności ani
 *    uprawnień osób trzecich;
 *  - nie dotyczy go żadna sprawa moderacyjna;
 *  - mieści się w limitach (`collections.odzyskanie_max_pozycji` pozycji,
 *    `collections.odzyskanie_max_zeszytow` kopii na osobę w oknie).
 *
 * CZEGO KOPIA NIE NIESIE: treści cudzych przepisów ani wpisów, tylko ich
 * identyfikatory. Odzyskanie nigdy nie wskrzesza usuniętego przepisu.
 */
final class UsunZeszyt
{
    public function handle(User $wlasciciel, Collection $zeszyt): WynikUsunieciaZeszytu
    {
        return DB::transaction(function () use ($wlasciciel, $zeszyt): WynikUsunieciaZeszytu {
            $konto = User::query()->whereKey($wlasciciel->getKey())->lock('FOR NO KEY UPDATE')->first();

            $swiezy = Collection::query()
                ->whereKey($zeszyt->getKey())
                ->where('owner_id', $wlasciciel->getKey())
                ->lock('FOR UPDATE')
                ->first();

            // Druga karta albo podwójne kliknięcie: zeszyt już zniknął.
            if ($konto === null || $swiezy === null) {
                return WynikUsunieciaZeszytu::juzUsuniety((string) $zeszyt->name);
            }

            $powod = $this->powodBrakuOdzyskania($konto, $swiezy);

            if ($powod === null) {
                $this->zachowajKopie($konto, $swiezy);
            }

            $swiezy->delete();

            return $powod === null
                ? WynikUsunieciaZeszytu::zKopia((string) $swiezy->name)
                : WynikUsunieciaZeszytu::bezKopii((string) $swiezy->name, $powod);
        });
    }

    /**
     * Zdanie dla człowieka, dlaczego tego zeszytu nie da się odzyskać; null,
     * gdy da się. Sprawy moderacyjnej nie nazywamy.
     */
    private function powodBrakuOdzyskania(User $konto, Collection $zeszyt): ?string
    {
        if (! $konto->isActive()) {
            return 'Przy obecnym stanie konta odzyskiwanie zeszytów jest niedostępne.';
        }

        if ($zeszyt->visibility !== 'private' || $zeszyt->is_default
            || $zeszyt->members()->exists()
            || $zeszyt->invitations()->where('status', 'pending')->exists()) {
            return 'Zeszyt był publiczny albo wspólny, a takich zeszytów nie da się odzyskać.';
        }

        if (OdzyskajUsunietyZeszyt::wSprawieModeracyjnej((string) $zeszyt->getKey())) {
            return 'Tego zeszytu nie da się odzyskać.';
        }

        $maxPozycji = (int) config('kuking.collections.odzyskanie_max_pozycji');
        if (DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->count() > $maxPozycji) {
            return 'Zeszyt ma ponad '.$maxPozycji.' zapisów, więcej niż mieści odzyskiwanie.';
        }

        $kopie = DB::table('deleted_collections')
            ->where('owner_id', $konto->getKey())
            ->where('deleted_at', '>', now()->subDays(OdzyskajUsunietyZeszyt::dniOkna()))
            ->count();

        if ($kopie >= (int) config('kuking.collections.odzyskanie_max_zeszytow')) {
            return 'Masz już tyle usuniętych zeszytów do odzyskania, ile mieści odzyskiwanie.';
        }

        return null;
    }

    private function zachowajKopie(User $konto, Collection $zeszyt): void
    {
        $pozycje = DB::table('collection_items')
            ->where('collection_id', $zeszyt->getKey())
            ->orderBy('created_at')
            ->orderByRaw('coalesce(recipe_id, post_id)')
            ->get(['recipe_id', 'post_id', 'note', 'created_at'])
            ->map(fn (object $p): array => [
                'recipe_id' => $p->recipe_id,
                'post_id' => $p->post_id,
                'note' => $p->note,
                // Surowy napis z bazy, z mikrosekundami i strefą: odtwarzamy
                // dokładnie tę chwilę zapisania, bez zaokrąglania do sekund.
                'created_at' => (string) $p->created_at,
            ])
            ->values()
            ->all();

        DB::table('deleted_collections')->insert([
            'owner_id' => $konto->getKey(),
            'collection_id' => $zeszyt->getKey(),
            'name' => $zeszyt->name,
            'description' => $zeszyt->description,
            'collection_created_at' => $zeszyt->getRawOriginal('created_at'),
            'items' => json_encode($pozycje, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'items_count' => count($pozycje),
            'deleted_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
