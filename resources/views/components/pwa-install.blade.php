@props(['eligible' => false, 'context' => null])

@if($eligible && $context)
    <section class="pwa-install card" data-pwa-install
             data-pwa-url="{{ route('pwa.decision') }}"
             data-pwa-context="{{ $context }}"
             aria-labelledby="pwa-install-title" hidden>
        @csrf
        <h2 id="pwa-install-title">Ikona na Twoim urządzeniu</h2>
        <p>Zainstaluj <x-kuking-word />, żeby otwierać serwis z ikony aplikacji.</p>
        <p>Po zamknięciu nie pokażemy tej propozycji ponownie.</p>
        <div class="pwa-install-actions">
            <button type="button" class="btn btn-primary" data-pwa-accept>Zainstaluj aplikację</button>
            <button type="button" class="btn btn-secondary" data-pwa-dismiss>Nie teraz</button>
        </div>
        <p class="pwa-install-status" data-pwa-status role="status"></p>
    </section>
@endif
