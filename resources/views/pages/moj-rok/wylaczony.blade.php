{{--
    „Mój rok w kuchni” przy wyłączonych wspomnieniach (#2353). Jeden wyłącznik
    (`users.memories_enabled`) gasi wspomnienia i to podsumowanie; dane zostają
    nietknięte. Ekran niczego nie liczy i niczego nie pokazuje.
--}}
<x-layout title="Mój rok w kuchni" :noindex="true">
    <p class="mb-3"><a href="{{ route('collections.index') }}">Wróć do zeszytu</a></p>

    <h1>Mój rok w kuchni</h1>
    <p class="mb-5">To podsumowanie jest wyłączone razem z przypominaniem dawnych wpisów. Nic nie zostało skasowane — wszystkie Twoje wpisy i „Ugotowałem” są na swoim miejscu.</p>
    <p><a class="btn btn-primary" href="{{ route('settings.privacy') }}">Przejdź do ustawień prywatności</a></p>
</x-layout>
