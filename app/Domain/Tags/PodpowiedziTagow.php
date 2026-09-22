<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\User;
use App\Support\LimityTagow;

final class PodpowiedziTagow
{
    public function __construct(private readonly TagSuggester $suggester) {}

    /** @return array{tags: list<array{id: string, name: string, slug: string, public_posts_count: int}>, exact_match: bool, can_create: bool} */
    public function dla(string $query, User $viewer): array
    {
        $normalized = Tag::znormalizujNazwe($query);
        // Slug nie jest nazwą: może zawierać sufiks kolizji i mieć 40 znaków.
        // Szukamy go przed fuzzy matchingiem, który operuje na nazwach.
        $slugMatch = Tag::query()->where('status', Tag::STATUS_ACTIVE)
            ->where('slug', $query)->first();
        $tags = $this->suggester->sugeruj($query)
            ->filter(fn (Tag $tag): bool => $tag->status === Tag::STATUS_ACTIVE)
            ->values();
        if ($slugMatch !== null) {
            $tags->prepend($slugMatch);
        }
        $tags = (new Tag)->newCollection(
            $tags->unique('id')->take(LimityTagow::maksPodpowiedzi())->values()->all(),
        );

        // Jeden agregat dla całej odpowiedzi; nie ujawniamy posts_count
        // z wyszukiwarki, bo samo published() obejmuje również prywatne wpisy.
        $tags->loadCount(['posts as public_posts_count' => fn ($posts) => $posts
            ->publiclyVisible()
            ->tylkoOdAktywnychAutorow()
            ->widoczneDla($viewer)
            ->zWidocznymPrzepisem(null)
            ->zWidocznymPrzepisem($viewer)
            ->where(fn ($q) => $q->whereNull('posts.recipe_id')
                ->orWhereHas('recipe.author', fn ($author) => $author->dostepnyJakoAutor()))]);

        // Także ukryta nazwa/alias zajmuje nazwę. Nie ujawniamy jej stanu
        // ani identyfikatora i nie zachęcamy do obejścia moderacji duplikatem.
        $occupied = Tag::query()->where('normalized_name', $normalized)->exists()
            || TagAlias::query()->where('normalized_alias', $normalized)->exists()
            || Tag::query()->where('slug', $query)->exists();

        $exact = $slugMatch !== null
            || $tags->contains(fn (Tag $tag): bool => $tag->normalized_name === $normalized)
            || TagAlias::query()->where('normalized_alias', $normalized)
                ->whereIn('tag_id', $tags->modelKeys())->exists();

        return [
            'tags' => $tags->map(fn (Tag $tag): array => [
                'id' => (string) $tag->getKey(),
                'name' => $tag->name,
                'slug' => $tag->slug,
                'public_posts_count' => (int) $tag->getAttribute('public_posts_count'),
            ])->all(),
            'exact_match' => $exact,
            'can_create' => ! $occupied
                && LimityTagow::dlugoscOk($normalized)
                && LimityTagow::pasujeDoWzorca($normalized)
                && ! FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($normalized),
        ];
    }
}
