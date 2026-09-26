<x-layout title="Odczyt zdjęcia kartki" :noindex="true">
    {{--
        ZGODA „ODCZYT AI” PRZED PIERWSZYM UŻYCIEM (D-296, wzór z D-240).

        Prostym językiem: kto odczyta, co wyślemy, czego nie wyślemy, jak
        wycofać. Dwa przyciski tej samej wagi wizualnej co do wielkości;
        „Nie” prowadzi do zwykłego dodawania przepisu, bez kary.
    --}}
    <h1>Zdjęcie odczyta komputer firmy OpenAI</h1>

    <div class="panel-formularza stack">
        <p class="mt-0">
            Żeby przepisać przepis z Twojej kartki, wyślemy jej zdjęcie do firmy <strong>OpenAI</strong> w USA.
            Jej komputer odczyta pismo, a my wpiszemy tekst do Twojego prywatnego szkicu.
        </p>
        <ul class="stack-tight">
            <li>Wyślemy <strong>samo zdjęcie kartki</strong> — bez Twojego imienia, adresu e-mail i danych z aparatu (także bez miejsca, w którym zrobiono zdjęcie).</li>
            <li>Jeśli na kartce są czyjeś dane — nazwisko, telefon, adres, informacja o zdrowiu — <strong>zasłoń je przed zrobieniem zdjęcia</strong>.</li>
            <li>Tekst odczytany przez komputer trafi tylko do Twojego szkicu. Nic się nie opublikuje, dopóki nie sprawdzisz tekstu i nie klikniesz „Opublikuj”.</li>
            <li>Zgodę możesz wycofać w <a href="{{ route('settings.privacy') }}">ustawieniach prywatności</a>. Zdjęcia kartek dalej wtedy dodasz — tylko tekst wpiszesz ręcznie.</li>
        </ul>

        <form method="POST" action="{{ route('zgoda.odczyt-ai.udziel') }}" class="form-actions">
            @csrf
            <input type="hidden" name="skad" value="import">
            <button class="btn btn-primary" type="submit">Zgadzam się, odczytujcie moje kartki</button>
            <a class="btn btn-secondary" href="{{ route('recipes.create') }}">Nie, wpiszę przepis ręcznie</a>
        </form>
    </div>
</x-layout>
