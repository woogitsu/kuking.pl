<?php

declare(strict_types=1);

namespace App\Domain\Collections\Odzyskiwanie;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\DeletedCollection;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Właściciel odzyskuje własny, omyłkowo usunięty PRYWATNY zeszyt, dopóki trwa
 * okno odzyskiwania (issue #2567, decyzja właściciela z 2.10.2026, D-333).
 *
 * CO TO JEST
 * „Usuń ten zeszyt" (`UsunZeszyt`) zostawia kopię odzyskania w
 * `deleted_collections` na `kuking.usuniete_tresci.retention_days` dni — to
 * samo okno co przepisy, wpisy i komentarze (ADR retencji §5.7). Ta klasa daje
 * właścicielowi drogę do odkręcenia pomyłki w TYM oknie. Niczego nie wydłuża.
 *
 * DO CZEGO WRACA
 * Do PRYWATNEGO zeszytu, bez członków i bez zaproszeń, pod dawnym
 * identyfikatorem, z nazwą, opisem, datą założenia i — dla każdego zapisu —
 * własnym dopiskiem i oryginalną datą zapisania. Nic nie jest publikowane,
 * nikt nie dostaje powiadomienia („zapisał Twój przepis" nie powstaje drugi
 * raz), zeszyt nie trafia do kanału ani do cudzych list. Skrót w „Moje" nie
 * wraca sam: to osobny wybór.
 *
 * CO WRACA, A CO NIE
 * Zapis wraca, gdy jego przepis albo wpis nadal istnieje i nie jest usunięty.
 * Skasowany przepis nie jest wskrzeszany; liczba zapisów, które nie wróciły,
 * wraca w wyniku, a ekran mówi to wprost. Kopia niesie tylko identyfikatory,
 * więc nic z tekstu, tytułu ani zdjęcia niedostępnej treści nie może wyjść.
 * Czy zapisaną rzecz wolno oglądać, rozstrzyga — jak zawsze — widoczność przy
 * wyświetlaniu zeszytu; odzyskanie nie otwiera dostępu do niczego.
 *
 * KTÓRĄ KOPIĘ MOŻNA ODZYSKAĆ (wszystkie warunki naraz, pod blokadą)
 *  - konto jest AKTYWNE (zawieszone, zbanowane, w trakcie usuwania i wymazane
 *    nie odzyskują — to jest pisanie);
 *  - kopia należy do tego konta i nie minął jej termin;
 *  - żaden wiersz `reports` ani `moderation_actions` nie wskazuje zeszytu
 *    (moderacja nie ma tu furtki i nie jest obchodzona);
 *  - nazwa nie jest zajęta przez inny zeszyt tej osoby (unikalność
 *    `collections_owner_name_lower_unique`). Wtedy NIC nie ginie: kopia
 *    zostaje, a komunikat mówi, co zrobić (zmienić nazwę tamtego zeszytu).
 *
 * BLOKADA I WYŚCIGI (D-079: `users` przed resztą)
 * Wiersz konta `FOR NO KEY UPDATE`, potem wiersz kopii `FOR UPDATE`. Pod
 * blokadą stan jest sprawdzany od nowa. Dwa równoległe wysłania dają jeden
 * rezultat: drugie zastaje zeszyt już odzyskany i mówi to wprost. Sprzątanie
 * (`PrzedawnioneUsunieteZeszyty`) czyta ten sam wiersz pod blokadą; to, które
 * przegra, zastaje wiersz zmieniony i nic nie robi — odzyskanie nigdy nie
 * odtwarza zeszytu z kopii, która właśnie wygasła, a sprzątanie nie kasuje
 * kopii, z której zeszyt właśnie wrócił. Wymazanie konta, które wygra,
 * zostawia konto nieaktywne (odmowa) i kasuje kopię.
 */
final class OdzyskajUsunietyZeszyt
{
    private const NIE_DA_SIE = 'Tego zeszytu nie da się już odzyskać tym przyciskiem. '
        .'Mógł minąć termin albo zeszyt wymaga rozpatrzenia przez nas. '
        .'Jeśli chcesz o niego zapytać, napisz do nas przez „Napisz do nas”.';

    /** Ile dni od usunięcia trwa okno — ta sama liczba co w sprzątaniu. */
    public static function dniOkna(): int
    {
        return max(1, (int) config('kuking.usuniete_tresci.retention_days'));
    }

    /** Ostatnia chwila, do której zeszyt da się odzyskać (potem czeka na nocne sprzątanie). */
    public static function termin(DeletedCollection $kopia): CarbonInterface
    {
        return $kopia->deleted_at->addDays(self::dniOkna());
    }

    /**
     * Czy zeszyt jest celem sprawy moderacyjnej. Ta sama definicja przy
     * usuwaniu (czy w ogóle robić kopię) i przy odzyskaniu.
     */
    public static function wSprawieModeracyjnej(string $zeszytId): bool
    {
        foreach (['reports', 'moderation_actions'] as $tabela) {
            if (DB::table($tabela)->where('target_type', 'collection')->where('target_id', $zeszytId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lista dla ekranu „Usunięte zeszyty": tylko to, co da się teraz odzyskać.
     * Zeszytów objętych sprawą moderacyjną nie pokazujemy ani nie nazywamy.
     *
     * @return EloquentCollection<int, DeletedCollection>
     */
    public function dlaEkranu(User $wlasciciel, int $limit = 50): EloquentCollection
    {
        if (! $wlasciciel->isActive()) {
            return new EloquentCollection;
        }

        return DeletedCollection::query()
            ->where('owner_id', $wlasciciel->getKey())
            ->where('deleted_at', '>', now()->subDays(self::dniOkna()))
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reject(fn (DeletedCollection $kopia): bool => self::wSprawieModeracyjnej($kopia->collection_id))
            ->values();
    }

    /**
     * @param  string  $zeszytId  dawny identyfikator zeszytu (klucz kopii)
     *
     * @throws BladDlaCzlowieka gdy zeszytu nie wolno albo nie da się odzyskać
     */
    public function handle(User $wlasciciel, string $zeszytId): WynikOdzyskaniaZeszytu
    {
        return DB::transaction(function () use ($wlasciciel, $zeszytId): WynikOdzyskaniaZeszytu {
            // Konto pod blokadą: wymazanie albo ban zatwierdzone w międzyczasie
            // jest widoczne (ta sama kolejność co zapis do zeszytu).
            $konto = User::query()->whereKey($wlasciciel->getKey())->lock('FOR NO KEY UPDATE')->first();

            if ($konto === null || ! $konto->isActive()) {
                throw new BladDlaCzlowieka(
                    'Stan Twojego konta zmienił się, więc nie odzyskamy teraz zeszytu. '
                    .'Jeśli to pomyłka, napisz do nas przez „Napisz do nas”.',
                );
            }

            $kopia = DeletedCollection::query()
                ->where('collection_id', $zeszytId)
                ->where('owner_id', $konto->getKey())
                ->lockForUpdate()
                ->first();

            if ($kopia === null) {
                // Drugie wysłanie tego samego formularza: pierwsze już odzyskało.
                $istnieje = Collection::query()->whereKey($zeszytId)->where('owner_id', $konto->getKey())->first();

                if ($istnieje !== null) {
                    return new WynikOdzyskaniaZeszytu($istnieje, juzOdzyskany: true, zapisyNieWrocily: 0, zapisyWrocily: 0);
                }

                throw new BladDlaCzlowieka(self::NIE_DA_SIE);
            }

            if (self::termin($kopia)->lessThanOrEqualTo(now()) || self::wSprawieModeracyjnej($kopia->collection_id)) {
                throw new BladDlaCzlowieka(self::NIE_DA_SIE);
            }

            $zajeta = Collection::query()
                ->where('owner_id', $konto->getKey())
                ->whereRaw('lower(name) = ?', [mb_strtolower($kopia->name)])
                ->exists();

            if ($zajeta) {
                throw $this->nazwaZajeta($kopia);
            }

            try {
                // Własny savepoint: odrzucony INSERT psuje na PostgreSQL całą
                // bieżącą transakcję, a my chcemy odpowiedzieć zdaniem po polsku.
                [$wrocilo, $nieWrocilo] = DB::transaction(fn (): array => $this->odtworz($konto, $kopia));
            } catch (UniqueConstraintViolationException) {
                throw $this->nazwaZajeta($kopia);
            }

            DB::table('deleted_collections')->where('id', $kopia->getKey())->delete();

            return new WynikOdzyskaniaZeszytu(
                Collection::query()->findOrFail($zeszytId),
                juzOdzyskany: false,
                zapisyNieWrocily: $nieWrocilo,
                zapisyWrocily: $wrocilo,
            );
        });
    }

    /**
     * @return array{0: int, 1: int} ile zapisów wróciło, ile nie
     */
    private function odtworz(User $konto, DeletedCollection $kopia): array
    {
        DB::table('collections')->insert([
            'id' => $kopia->collection_id,
            'owner_id' => $konto->getKey(),
            'name' => $kopia->name,
            'description' => $kopia->description,
            'visibility' => 'private',
            'is_default' => false,
            // Surowy napis z bazy: data założenia bez zaokrąglenia do sekund.
            'created_at' => $kopia->getRawOriginal('collection_created_at'),
            'updated_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);

        /** @var list<array{recipe_id: string|null, post_id: string|null, note: string|null, created_at: string, position?: int|null}> $pozycje */
        $pozycje = $kopia->items;

        // Cel nadal istnieje i nie jest usunięty. `FOR KEY SHARE` w stałej
        // kolejności: trwałe usunięcie przepisu czeka na nasze zatwierdzenie
        // (a potem kaskada zabierze też nasz zapis), zamiast wywrócić wstawienie
        // błędem klucza obcego.
        $zyjace = [
            'recipe_id' => $this->zyjace('recipes', array_column($pozycje, 'recipe_id')),
            'post_id' => $this->zyjace('posts', array_column($pozycje, 'post_id')),
        ];

        $wiersze = [];
        foreach ($pozycje as $pozycja) {
            foreach (['recipe_id', 'post_id'] as $kolumna) {
                $cel = $pozycja[$kolumna];
                if ($cel !== null && isset($zyjace[$kolumna][$cel])) {
                    $wiersze[] = [
                        'collection_id' => $kopia->collection_id,
                        'recipe_id' => $kolumna === 'recipe_id' ? $cel : null,
                        'post_id' => $kolumna === 'post_id' ? $cel : null,
                        'note' => $pozycja['note'],
                        // Stare kopie nie mają tego klucza; nie zgadujemy układu.
                        'position' => $kolumna === 'recipe_id' ? ($pozycja['position'] ?? null) : null,
                        'created_at' => $pozycja['created_at'],
                        // Zeszyt jest prywatny i niewspółdzielony: dodał go właściciel.
                        'added_by_id' => $konto->getKey(),
                    ];
                }
            }
        }

        foreach (array_chunk($wiersze, 250) as $porcja) {
            DB::table('collection_items')->insert($porcja);
        }

        return [count($wiersze), count($pozycje) - count($wiersze)];
    }

    /**
     * @param  list<string|null>  $id
     * @return array<string, true>
     */
    private function zyjace(string $tabela, array $id): array
    {
        $id = array_values(array_filter($id, static fn (?string $v): bool => $v !== null));

        if ($id === []) {
            return [];
        }

        return array_fill_keys(
            DB::table($tabela)->whereIn('id', $id)->whereNull('deleted_at')
                ->orderBy('id')->lock('FOR KEY SHARE')->pluck('id')->map(fn ($v): string => (string) $v)->all(),
            true,
        );
    }

    private function nazwaZajeta(DeletedCollection $kopia): BladDlaCzlowieka
    {
        return new BladDlaCzlowieka(
            'Masz już zeszyt o nazwie „'.$kopia->name.'”, więc ten nie może wrócić pod tą samą nazwą. '
            .'Zmień nazwę tamtego zeszytu i spróbuj jeszcze raz — ten usunięty zeszyt czeka do wskazanego terminu.',
        );
    }
}
