{{-- EXAMPLE ONLY: wire the route and server state in the actual application.
     Do not use a separate POST on an unsaved wizard page; see MOTYW-V3.1.md.
     $motyw is the server-validated current value: 'light' or 'dark'. --}}
<form class="stopka-wyglad" method="post" action="{{ route('motyw.zmien') }}">
    @csrf
    <input type="hidden" name="motyw" value="{{ $motyw === 'dark' ? 'light' : 'dark' }}">
    <button type="submit" class="btn btn-quiet motyw-przelacznik"
            aria-label="Ciemny wygląd" aria-pressed="{{ $motyw === 'dark' ? 'true' : 'false' }}">
        <svg class="side-nav-ikona" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true" focusable="false"><path d="M20.5 13A8.5 8.5 0 0 1 11 3.5 8.5 8.5 0 1 0 20.5 13Z"/></svg>
        <span>Ciemny</span>
        <span class="motyw-suwak" aria-hidden="true"></span>
    </button>
</form>
