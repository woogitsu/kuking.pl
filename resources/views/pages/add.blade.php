<x-layout title="Dodaj" :noindex="true">
    <h1>Co chcesz dodać?</h1>
    <p style="margin-bottom:var(--spacing-6);">Nie musisz od razu pisać całego przepisu. Samo zdjęcie też jest w porządku.</p>

    <div class="stack">
        <a class="card" href="{{ route('posts.create') }}" style="display:block; text-decoration:none; color:inherit;">
            <h2 style="margin-top:0;">Zdjęcie i kilka słów</h2>
            <p style="margin-bottom:0;">Najprostsza rzecz. Wybierasz zdjęcie, piszesz jedno zdanie i gotowe. Zajmuje niecałą minutę.</p>
        </a>

        <a class="card" href="{{ route('recipes.create') }}" style="display:block; text-decoration:none; color:inherit;">
            <h2 style="margin-top:0;">Cały przepis</h2>
            <p style="margin-bottom:0;">Składniki i przygotowanie, żeby ktoś inny mógł to u siebie zrobić. Możesz zapisać szkic i wrócić później.</p>
        </a>
    </div>
</x-layout>
