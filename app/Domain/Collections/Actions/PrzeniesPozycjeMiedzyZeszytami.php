<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Collections\KolejnoscPrzepisow;
use App\Domain\Collections\KonfliktPrzeniesienia;
use App\Domain\Collections\WynikPrzeniesienia;
use App\Domain\Collections\ZamekZapisuDoZeszytu;
use App\Domain\Users\ZamekKonta;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * „Przenieś do innego zeszytu" (#2430, V2, decyzja właściciela z 2.10.2026).
 *
 * Przenosi RELACJĘ zapisu (wiersz `collection_items`) między dwoma własnymi,
 * prywatnymi zeszytami tej samej osoby: ten sam wiersz zmienia tylko
 * `collection_id`. Notatka, pierwotne `created_at` i `added_by_id` zostają
 * bez ruchu, bo nic ich nie dotyka — nie ma kopii przepisu, zmiany autora,
 * wersji ani historii „Ugotowałem", nie ma powiadomienia ani zmiany liczby
 * osób zapisujących (`NotifyRecipeSaved` nie jest wołane).
 *
 * ATOMOWOŚĆ I ZAMKI. Jedna transakcja; kolejność jak w `ZamekZapisuDoZeszytu`:
 * konta (rosnąco po id: osoba i autor treści) → treść → zeszyty (rosnąco po id).
 * Pod zamkami wszystko jest sprawdzane JESZCZE RAZ na świeżym stanie: oba
 * zeszyty należą do osoby i przechodzą Policy `przenies` (prywatny, bez
 * zaproszonych osób), pozycja nadal leży w źródle, a treść jest nadal widoczna
 * dla osoby. Błąd na dowolnym kroku cofa całość — źródło i notatka zostają.
 *
 * KONFLIKT. Gdy cel już zawiera tę pozycję, rzucamy {@see KonfliktPrzeniesienia}:
 * żadna notatka nie jest nadpisana ani połączona. Ponowione wysłanie po udanym
 * przeniesieniu (pozycji nie ma w źródle, jest w celu) to sukces bez zmian,
 * bez duplikatu.
 *
 * Przepis ląduje na końcu ułożonego celu (jak przy zapisie, #2544); w celu
 * nieułożonym zostaje w kolejności „od najnowszego zapisu" (po `created_at`).
 * Dziury w numeracji źródła są dozwolone. W dzienniku audytu są tylko
 * identyfikatory, bez nazw zeszytów i notatek.
 *
 * COFNIĘCIE to to samo przeniesienie w drugą stronę: bierze notatkę z chwili
 * cofania (późniejsze edycje nie są nadpisane), a konflikt ma ten sam ekran.
 */
final class PrzeniesPozycjeMiedzyZeszytami
{
    public const PRZEPIS = 'przepis';

    public const WPIS = 'wpis';

    public const TEN_SAM_ZESZYT = 'To jest ten sam zeszyt. Wybierz inny zeszyt, do którego chcesz przenieść tę pozycję.';

    public const ZESZYT_NIE_DO_PRZENOSZENIA = 'Do tego zeszytu nie można teraz przenosić pozycji — jest publiczny albo wspólny. '
        .'Przenosić można tylko między własnymi zeszytami prywatnymi, bez zaproszonych osób.';

    /**
     * @throws KonfliktPrzeniesienia cel już zawiera tę pozycję
     * @throws BladDlaCzlowieka zeszyt zniknął, nie jest już prywatny/własny albo treść jest niedostępna
     * @throws ModelNotFoundException pozycji nie ma ani w źródle, ani w celu
     */
    public function handle(User $user, string $zrodloId, string $celId, string $typ, string $pozycjaId): WynikPrzeniesienia
    {
        if (ZamekKonta::trzymanyWTymProcesie()) {
            throw new LogicException('Przeniesienie wywołaj poza zamkiem pojedynczego konta.');
        }

        $kolumna = match ($typ) {
            self::PRZEPIS => 'recipe_id',
            self::WPIS => 'post_id',
            default => throw new ModelNotFoundException,
        };

        if ($zrodloId === $celId) {
            throw new BladDlaCzlowieka(self::TEN_SAM_ZESZYT);
        }

        $model = $typ === self::PRZEPIS ? new Recipe : new Post;
        $autorId = $model->newQuery()->whereKey($pozycjaId)->value('author_id');

        if ($autorId === null) {
            throw new BladDlaCzlowieka(ZamekZapisuDoZeszytu::NIEDOSTEPNE);
        }

        $konta = array_values(array_unique([(string) $user->getKey(), (string) $autorId]));
        sort($konta, SORT_STRING);

        return DB::transaction(function () use ($user, $zrodloId, $celId, $typ, $pozycjaId, $kolumna, $model, $autorId, $konta): WynikPrzeniesienia {
            $swiezi = [];
            foreach ($konta as $id) {
                $swiezi[$id] = User::query()->whereKey($id)->lock('FOR NO KEY UPDATE')->first()
                    ?? throw new BladDlaCzlowieka(ZamekZapisuDoZeszytu::NIEDOSTEPNE);
            }
            $aktor = $swiezi[(string) $user->getKey()];

            $tresc = $model->newQuery()->whereKey($pozycjaId)->lock('FOR NO KEY UPDATE')->first();

            if ($tresc === null || $tresc->author_id !== $autorId) {
                throw new BladDlaCzlowieka(ZamekZapisuDoZeszytu::NIEDOSTEPNE);
            }

            $tresc->setRelation('author', $swiezi[(string) $autorId]);

            $idy = [$zrodloId, $celId];
            sort($idy, SORT_STRING);

            $zeszyty = [];
            foreach ($idy as $id) {
                $zeszyty[$id] = Collection::query()->whereKey($id)
                    ->where('owner_id', $aktor->getKey())->lock('FOR NO KEY UPDATE')->first()
                    ?? throw new BladDlaCzlowieka(ZamekZapisuDoZeszytu::BRAK_ZESZYTU);
            }

            $zrodlo = $zeszyty[$zrodloId];
            $cel = $zeszyty[$celId];

            foreach ([$zrodlo, $cel] as $zeszyt) {
                if (Gate::forUser($aktor)->denies('przenies', $zeszyt)) {
                    throw new BladDlaCzlowieka(self::ZESZYT_NIE_DO_PRZENOSZENIA);
                }
            }

            // Ponowienie też jest odczytem: zapis w zeszycie nie daje prawa
            // do późniejszego prywatnego tytułu autora (#2809).
            $widoczna = Gate::forUser($aktor)->allows('view', $tresc);
            $tytul = $widoczna ? ($tresc instanceof Recipe ? $tresc->title : 'Wpis') : null;

            $wZrodle = DB::table('collection_items')
                ->where('collection_id', $zrodlo->getKey())->where($kolumna, $pozycjaId)
                ->lockForUpdate()->first(['note']);
            $wCelu = DB::table('collection_items')
                ->where('collection_id', $cel->getKey())->where($kolumna, $pozycjaId)
                ->lockForUpdate()->first(['note']);

            if ($wZrodle === null) {
                // Ponowione wysłanie po udanym przeniesieniu: pozycja już jest w celu.
                if ($wCelu !== null) {
                    return new WynikPrzeniesienia($zrodlo, $cel, $tytul, false);
                }

                throw new ModelNotFoundException;
            }

            if ($wCelu !== null) {
                throw new KonfliktPrzeniesienia(
                    $cel->name,
                    $wZrodle->note === null ? null : (string) $wZrodle->note,
                    $wCelu->note === null ? null : (string) $wCelu->note,
                );
            }

            // Treść musi być NADAL widoczna dla osoby (ponowna kontrola
            // uprawnień na świeżym stanie), inaczej przenoszenie byłoby drogą
            // do obchodzenia ukrycia.
            if (! $widoczna) {
                throw new BladDlaCzlowieka(ZamekZapisuDoZeszytu::NIEDOSTEPNE);
            }

            DB::table('collection_items')
                ->where('collection_id', $zrodlo->getKey())->where($kolumna, $pozycjaId)
                ->update([
                    'collection_id' => $cel->getKey(),
                    'position' => $typ === self::PRZEPIS ? KolejnoscPrzepisow::nastepnaPozycja($cel) : null,
                ]);

            AuditLogEntry::record(
                action: 'collection_item.moved',
                actor: $aktor,
                subject: $cel,
                metadata: ['from_collection_id' => $zrodlo->getKey(), 'type' => $typ, 'item_id' => $pozycjaId],
            );

            return new WynikPrzeniesienia($zrodlo, $cel, $tytul, true);
        });
    }
}
