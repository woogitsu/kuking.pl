<x-layout title="Gotowe" :noindex="true">
    <p class="wizard-steps">
        <span class="wizard-steps-current">Krok 3 z 3</span>
        <span class="wizard-steps-track" aria-hidden="true">
            <span class="wizard-steps-dot" data-done="true"></span>
            <span class="wizard-steps-dot" data-done="true"></span>
            <span class="wizard-steps-dot" data-done="true"></span>
        </span>
    </p>

    <h1>Wszystko gotowe, {{ $name }}</h1>

    <p class="text-lead">
        {{-- Zdanie dokładnie z `docs/brand/COPY_STYLE.md` §6 („koniec
             onboardingu"). Stało tu „ugotowałaś" — jedyna forma żeńska
             w interfejsie poza e-mailem eksportu, i akurat w miejscu, w którym
             dokument ma gotowy tekst z formą utrwaloną w claimie głównym. --}}
        Konto jest założone. Możesz od razu pokazać, co dziś ugotowałeś —
        albo najpierw się rozejrzeć. Jedno i drugie jest w porządku.
    </p>

    {{-- Dwa równorzędne wyjścia. Nie wymuszamy publikacji (docs/UX_50_PLUS.md). --}}
    <div class="form-actions">
        <a class="btn btn-primary" href="{{ route('posts.create') }}">Dodaj pierwsze zdjęcie</a>
        <a class="btn btn-secondary" href="{{ route('home') }}">Na razie tylko pooglądam</a>
    </div>

    <section class="card mt-8">
        <h2>Trzy rzeczy, które warto wiedzieć</h2>
        <ul class="pl-6">
            <li><strong>Tekst da się powiększyć.</strong> W <a href="{{ route('settings.accessibility') }}">Ustawieniach</a> możesz ustawić większy tekst — na stałe, na każdym urządzeniu.</li>
            <li><strong>Możesz decydować, kto widzi Twoje wpisy.</strong> Przy każdym wpisie wybierasz: wszyscy, tylko obserwujący albo tylko Ty.</li>
            <li><strong>Twoje dane są Twoje.</strong> W każdej chwili możesz je pobrać na swój komputer albo usunąć konto.</li>
        </ul>
    </section>
</x-layout>
