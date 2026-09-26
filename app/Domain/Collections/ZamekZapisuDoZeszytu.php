<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Domain\Users\ZamekKonta;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * JEDNA GRANICA ZAPISU DO ZESZYTU (issue #1022).
 *
 * CO BYŁO ZŁAMANE
 * Kontroler pytał Policy (`authorize('view')`), a akcja domenowa robiła
 * `attach()` osobnym krokiem — bez blokad i bez ponownego pytania. Między
 * jednym a drugim autor mógł przestawić przepis na prywatny, moderator go
 * ukryć, ktoś założyć blokadę, a właściciel skasować wybrany zeszyt w drugiej
 * karcie. Skutek: „Zapisane…” przy pozycji, której już nie wolno oglądać,
 * albo błąd klucza obcego (500) po poprawnym kliknięciu.
 *
 * CO ROBI TA KLASA
 * W jednej transakcji bierze zamki w tej samej kolejności, której używa
 * `LockCommentContext`: konta (rosnąco po id) → obserwowanie → treść → zeszyt.
 * Tryb `FOR NO KEY UPDATE` na kontach i treści koliduje z każdą zmianą
 * statusu, widoczności, blokady (`ZamekPary` bierze `FOR UPDATE`) i miękkim
 * kasowaniem, ale nie z `FOR KEY SHARE`, które biorą klucze obce — więc nie
 * dokłada krawędzi do grafu oczekiwania cudzym INSERT-om.
 *
 * SAMA BLOKADA NIE WYSTARCZY. Pod zamkami czytamy wiersze jeszcze raz
 * i pytamy Policy o ŚWIEŻY stan; dopiero wtedy wołamy `$zapisz`. Skutek
 * wywołania (pivot i powiadomienie z #772) wchodzi do tej samej transakcji.
 */
final class ZamekZapisuDoZeszytu
{
    public const NIEDOSTEPNE = 'Tego nie da się teraz zapisać — autor mógł to ukryć albo usunąć. '
        .'Odśwież stronę, a zobaczysz, co jest dostępne.';

    public const BRAK_ZESZYTU = 'Tego zeszytu już nie ma — mógł zostać usunięty w innym oknie. '
        .'Odśwież stronę i wybierz zeszyt jeszcze raz.';

    /**
     * `$zeszyt === null` znaczy zeszyt domyślny. Zeszyt wskazany, którego już
     * nie ma, to odmowa — NIE cichy zapis do domyślnego.
     *
     * @template T of Post|Recipe
     *
     * @param  T  $tresc
     * @param  Closure(User, T, Collection): Collection  $zapisz
     */
    public function zapisz(User $user, Post|Recipe $tresc, ?Collection $zeszyt, Closure $zapisz): Collection
    {
        if (ZamekKonta::trzymanyWTymProcesie()) {
            throw new LogicException('Zapis do zeszytu wywołaj poza zamkiem pojedynczego konta.');
        }

        $autorId = $tresc->newQuery()->whereKey($tresc->getKey())->value('author_id');

        if ($autorId === null) {
            throw new BladDlaCzlowieka(self::NIEDOSTEPNE);
        }

        $konta = array_values(array_unique([(string) $user->getKey(), (string) $autorId]));
        sort($konta, SORT_STRING);

        return DB::transaction(function () use ($user, $tresc, $zeszyt, $zapisz, $autorId, $konta): Collection {
            $swiezi = [];
            foreach ($konta as $id) {
                $swiezi[$id] = User::query()->whereKey($id)->lock('FOR NO KEY UPDATE')->first()
                    ?? throw new BladDlaCzlowieka(self::NIEDOSTEPNE);
            }
            $aktor = $swiezi[(string) $user->getKey()];

            // Cofnięcie obserwowania nie bierze zamków kont. Istniejący wiersz
            // ma dotrwać do końca decyzji o treści „dla obserwujących”.
            if ($aktor->getKey() !== $autorId) {
                DB::table('follows')->where('follower_id', $aktor->getKey())
                    ->where('followed_id', $autorId)->lock('FOR SHARE')->first();
            }

            // `newQuery()` pomija miękko usunięte — taka treść daje odmowę.
            $swieza = $tresc->newQuery()->whereKey($tresc->getKey())->lock('FOR NO KEY UPDATE')->first();

            if ($swieza === null || $swieza->author_id !== $autorId) {
                throw new BladDlaCzlowieka(self::NIEDOSTEPNE);
            }

            $swieza->setRelation('author', $swiezi[(string) $autorId]);

            // Policy obejmuje status treści, widoczność, stan autora i blokadę
            // w obie strony — teraz na wierszach odczytanych pod zamkami.
            if (! Gate::forUser($aktor)->allows('view', $swieza)) {
                throw new BladDlaCzlowieka(self::NIEDOSTEPNE);
            }

            $cel = $zeszyt === null
                ? $aktor->defaultCollection()
                : Collection::query()->whereKey($zeszyt->getKey())->lock('FOR NO KEY UPDATE')->first();

            if ($cel === null) {
                throw new BladDlaCzlowieka(self::BRAK_ZESZYTU);
            }

            return $zapisz($aktor, $swieza, $cel);
        });
    }
}
