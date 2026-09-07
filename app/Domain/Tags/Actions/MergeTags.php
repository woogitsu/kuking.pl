<?php

declare(strict_types=1);

namespace App\Domain\Tags\Actions;

use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagPromotion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Scalenie dwóch tagów (SPEC §1.8) — nazwana operacja, o której mówią
 * komentarze `App\Models\Tag` („zmiana stanu tagu jest zawsze jawną,
 * nazwaną operacją — `MergeTags`, nie `Tag::update()` wprost") oraz migracji
 * `create_tags_tables` przy indeksie `tag_aliases.tag_id` („potrzebny m.in.
 * przez `MergeTags` przy przepinaniu aliasów"). Do dzisiaj tej klasy nie
 * było, a kolumny `status`/`merged_into_tag_id` nie miały ŻADNEJ drogi
 * zapisu poza `forceFill` w testach — czyli dokładnie ten sam kształt błędu,
 * który zadanie #21 opisuje dla minutnika kroku: kompletny mechanizm
 * czytania (przekierowanie strony tagu, wykluczenie z podpowiedzi,
 * `ResolveTagsForPost::tagKanoniczny()`) za kolumną, której nikt nie umiał
 * ustawić.
 *
 * ŹRÓDŁO ZOSTAJE W BAZIE. SPEC §1.8 wprost: „nie kasować źródłowego tagu
 * twardo". Wiersz zostaje ze swoim slugiem, więc adres `/tag/stara-nazwa`
 * nadal działa i przekierowuje (`TagController::show`), a nazwa źródła
 * staje się aliasem celu, więc podpowiedź nadal ją znajduje
 * (`TagSuggester`, gałąź 2 — dokładny alias).
 *
 * CZEGO TA KLASA NIE ROBI: nie zapisuje `audit_log`. Zapis „kto i kiedy"
 * należy do wywołującego, bo scalenie z panelu ma autora (moderator),
 * a scalenie z seedera nie ma go wcale — ten sam podział co przy
 * `tag_promotions` (D-021: „kto i kiedy zmienił listę zapisuje `audit_log`,
 * bez osobnej kolumny `promoted_by`").
 *
 * KOLEJNOŚĆ KROKÓW W TRANSAKCJI JEST ISTOTNA: aliasy źródła przepinamy
 * PRZED dopisaniem nazwy źródła jako aliasu, bo inaczej `UNIQUE`
 * na `normalized_alias` mógłby odrzucić dopisek z powodu wiersza, który
 * i tak miał zmienić właściciela.
 */
final class MergeTags
{
    /**
     * @return Tag tag kanoniczny (cel), już po scaleniu
     *
     * @throws RuntimeException gdy scalenie nie ma sensu albo tworzyłoby cykl
     */
    public function handle(Tag $zrodlo, Tag $cel): Tag
    {
        // Cel sam może być scalony — wtedy scalamy do JEGO kanonicznego,
        // inaczej powstałby łańcuch, a `Tag::tagKanoniczny()` świadomie robi
        // dokładnie JEDEN skok (patrz komentarz tamtej metody).
        $cel = $cel->tagKanoniczny();

        if ($zrodlo->getKey() === $cel->getKey()) {
            throw new RuntimeException('Nie da się scalić tagu z samym sobą.');
        }

        if ($cel->merged_into_tag_id === $zrodlo->getKey()) {
            throw new RuntimeException('Scalenie w tę stronę utworzyłoby cykl.');
        }

        return DB::transaction(function () use ($zrodlo, $cel): Tag {
            $this->przepnijWpisy($zrodlo, $cel);
            $this->przepnijObserwacje($zrodlo, $cel);
            $this->przepnijAliasy($zrodlo, $cel);
            $this->dopiszNazweZrodlaJakoAlias($zrodlo, $cel);
            $this->przepnijPromocje($zrodlo, $cel);

            // `forceFill`, bo `status` i `merged_into_tag_id` są ŚWIADOMIE
            // poza `$fillable` (patrz komentarz klasy `Tag`) — ta klasa jest
            // jedynym miejscem, które ma prawo je ustawić.
            $zrodlo->forceFill([
                'status' => Tag::STATUS_MERGED,
                'merged_into_tag_id' => $cel->getKey(),
            ])->save();

            return $cel;
        });
    }

    /**
     * Wpisy oznaczone źródłem przechodzą na cel — z zachowaniem `position`,
     * bo to kolejność, w jakiej AUTOR dodawał tagi, a scalenie nie jest
     * jego decyzją.
     *
     * Wpis, który miał OBA tagi, dostałby dwa razy ten sam `tag_id`
     * (`PRIMARY KEY(post_id, tag_id)` by tego nie przyjął), więc dla takich
     * wpisów wiersz źródła po prostu znika — liczba tagów wpisu maleje
     * o jeden, co jest poprawne: dwa razy „to samo" nie jest dwoma tagami.
     */
    private function przepnijWpisy(Tag $zrodlo, Tag $cel): void
    {
        $juzMajaCel = DB::table('post_tags')
            ->where('tag_id', $cel->getKey())
            ->pluck('post_id');

        DB::table('post_tags')
            ->where('tag_id', $zrodlo->getKey())
            ->whereIn('post_id', $juzMajaCel)
            ->delete();

        DB::table('post_tags')
            ->where('tag_id', $zrodlo->getKey())
            ->update(['tag_id' => $cel->getKey()]);
    }

    /** Ta sama reguła co dla wpisów: kto obserwował oba, obserwuje cel raz. */
    private function przepnijObserwacje(Tag $zrodlo, Tag $cel): void
    {
        $juzObserwujaCel = DB::table('tag_follows')
            ->where('tag_id', $cel->getKey())
            ->pluck('user_id');

        DB::table('tag_follows')
            ->where('tag_id', $zrodlo->getKey())
            ->whereIn('user_id', $juzObserwujaCel)
            ->delete();

        DB::table('tag_follows')
            ->where('tag_id', $zrodlo->getKey())
            ->update(['tag_id' => $cel->getKey()]);
    }

    /**
     * Aliasy źródła prowadzą teraz do celu. WYJĄTEK: alias, który jest
     * dokładnie nazwą celu — taki wiersz przestaje mieć sens (alias
     * prowadzący do tagu o tej samej nazwie), więc go kasujemy, zamiast
     * przepinać i trzymać w bazie zapętloną parę.
     */
    private function przepnijAliasy(Tag $zrodlo, Tag $cel): void
    {
        TagAlias::query()
            ->where('tag_id', $zrodlo->getKey())
            ->where('normalized_alias', $cel->normalized_name)
            ->delete();

        TagAlias::query()
            ->where('tag_id', $zrodlo->getKey())
            ->update(['tag_id' => $cel->getKey()]);
    }

    /**
     * Nazwa źródła zostaje aliasem celu — to jest cały sens zdania z SPEC
     * §1.8 „zachować jako alias": ktoś, kto zna starą nazwę i wpisze ją
     * w formularzu, ma trafić na tag kanoniczny, a nie utworzyć nowy tag.
     *
     * `normalized_alias` jest `UNIQUE` w całej tabeli, więc gdy taki alias
     * już istnieje (np. drugie uruchomienie seedera albo alias dopisany
     * ręcznie w panelu), nie dublujemy go.
     */
    private function dopiszNazweZrodlaJakoAlias(Tag $zrodlo, Tag $cel): void
    {
        $istnieje = TagAlias::query()
            ->where('normalized_alias', $zrodlo->normalized_name)
            ->exists();

        if ($istnieje) {
            return;
        }

        TagAlias::create([
            'tag_id' => $cel->getKey(),
            'alias' => $zrodlo->name,
            'normalized_alias' => $zrodlo->normalized_name,
            'source' => TagAlias::SOURCE_ADMIN,
        ]);
    }

    /**
     * Promocja („tag promowany — lista gospodarza") jest wyborem gospodarza
     * o MIEJSCU na liście, nie właściwością tagu. Gdy promowane było tylko
     * źródło, promocja przechodzi na cel z tą samą pozycją i notatką —
     * inaczej scalenie po cichu zdejmowałoby coś z listy gospodarza.
     * Gdy promowane były OBA, zostaje promocja celu (jego pozycja jest tą,
     * którą gospodarz ustawił dla tagu, który zostaje).
     */
    private function przepnijPromocje(Tag $zrodlo, Tag $cel): void
    {
        $promocjaZrodla = TagPromotion::query()->where('tag_id', $zrodlo->getKey())->first();

        if ($promocjaZrodla === null) {
            return;
        }

        $celJuzPromowany = TagPromotion::query()->where('tag_id', $cel->getKey())->exists();

        if ($celJuzPromowany) {
            $promocjaZrodla->delete();

            return;
        }

        TagPromotion::query()->create([
            'tag_id' => $cel->getKey(),
            'position' => $promocjaZrodla->position,
            'note' => $promocjaZrodla->note,
        ]);

        $promocjaZrodla->delete();
    }
}
