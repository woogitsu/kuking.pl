// Szybki podgląd jest ulepszeniem zwykłego formularza POST z CSRF.
let cleanup = () => {};
function initialize() {
    cleanup();
    const widget = document.querySelector('[data-szybki-wyglad]');
    if (!widget) return;
    const form = widget.querySelector('form');
    const scale = form.elements.text_scale;
    const theme = form.elements.theme;
    const status = widget.querySelector('[data-wyglad-status]');
    const summary = widget.querySelector('summary');
    const hint = document.querySelector('[data-wyglad-podpowiedz]');
    const events = new AbortController();
    const listen = (target, name, fn) => target.addEventListener(name, fn, {signal: events.signal});
    let saved = {text_scale: scale.value, theme: theme.value};
    let completion = Promise.resolve();
    let pending = null;
    let running = false;
    let revision = 0;
    const apply = (choice) => {
        document.documentElement.dataset.textScale = String(choice.text_scale);
        document.documentElement.dataset.theme = choice.theme;
        const footer = document.querySelector('.site-footer-motyw');
        if (footer) {
            footer.elements.theme.value = choice.theme === 'dark' ? 'light' : 'dark';
            footer.querySelector('button').textContent = choice.theme === 'dark' ? 'Włącz jasny wygląd' : 'Włącz ciemny wygląd';
            const description = footer.querySelector('.visually-hidden');
            if (description) description.textContent = 'Wygląd strony: ' + (choice.theme === 'dark' ? 'ciemny.' : 'jasny.');
        }
    };
    const forgetHint = () => {
        hint.hidden = true;
        try { localStorage.setItem('kuking-wyglad-poznany', '1'); } catch { /* Brak pamięci nie blokuje ustawień. */ }
    };
    const geometry = () => {
        const nav = document.querySelector('.bottom-nav');
        const bottom = nav && getComputedStyle(nav).position === 'fixed' ? Math.max(0, innerHeight - nav.getBoundingClientRect().top) : 0;
        document.documentElement.style.setProperty('--wyglad-dol', bottom + 'px');
    };
    const save = async () => {
        if (running || !pending) return;
        const item = pending;
        pending = null;
        running = true;
        const data = new FormData(form);
        data.set('theme', item.choice.theme);
        data.set('text_scale', item.choice.text_scale);
        const focused = document.activeElement;
        const controls = [...form.querySelectorAll('button:not([data-wyglad-zamknij]), select')];
        controls.forEach(control => { control.disabled = true; });
        try {
            const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', keepalive: true, signal: AbortSignal.timeout(10000), headers: {Accept: 'application/json'}});
            if (!response.ok) throw new Error('Zapis odrzucony');
            const result = await response.json();
            saved = {theme: result.theme, text_scale: String(result.text_scale)};
            if (widget.isConnected && item.revision === revision) status.textContent = 'Wygląd zapisany.';
        } catch {
            if (widget.isConnected && item.revision === revision) {
                scale.value = saved.text_scale;
                theme.value = saved.theme;
                apply(saved);
                status.textContent = 'Nie udało się zapisać wyglądu. Przywróciliśmy ostatnie ustawienie. Spróbuj ponownie.';
            }
        } finally {
            running = false;
            controls.forEach(control => { control.disabled = false; });
            if (widget.open && document.activeElement === document.body && form.contains(focused)) focused.focus({preventScroll: true});
        }
    };
    const change = () => {
        if (running) return;
        const choice = {theme: theme.value, text_scale: scale.value};
        revision++;
        apply(choice);
        geometry();
        forgetHint();
        status.textContent = 'Zapisujemy wygląd…';
        pending = {choice, revision};
        completion = save();
    };
    // Przejście linkiem czeka na rozpoczęty zapis, zamiast gubić wybór.
    const navigate = event => {
        const link = event.target.closest('a[href]');
        if (!running || !link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download') || !link.href.startsWith(location.origin)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        completion.then(() => { location.href = link.href; });
    };
    document.addEventListener('click', navigate, {capture: true, signal: events.signal});
    listen(form, 'change', change);
    listen(form, 'submit', event => {
        event.preventDefault();
        if (event.submitter?.name === 'reset_appearance') { scale.value = '100'; theme.value = 'light'; }
        change();
    });
    widget.querySelectorAll('[data-skala-krok]').forEach(button => {
        button.hidden = false;
        listen(button, 'click', () => {
            const next = Math.max(0, Math.min(scale.options.length - 1, scale.selectedIndex + Number(button.dataset.skalaKrok)));
            if (next !== scale.selectedIndex) { scale.selectedIndex = next; change(); }
        });
    });
    const close = () => { widget.open = false; summary.focus(); };
    const closeButton = widget.querySelector('[data-wyglad-zamknij]');
    closeButton.hidden = false;
    listen(closeButton, 'click', close);
    listen(widget, 'keydown', event => { if (event.key === 'Escape') { event.preventDefault(); close(); } });
    listen(widget, 'toggle', () => { if (widget.open) { forgetHint(); geometry(); } });
    listen(document, 'pointerdown', event => { if (!widget.contains(event.target)) widget.open = false; });
    listen(document, 'focusin', event => {
        if (widget.contains(event.target) || hint.contains(event.target)) return;
        widget.open = false;
        hint.hidden = true;
        requestAnimationFrame(() => {
            const focused = event.target.getBoundingClientRect();
            const floating = summary.getBoundingClientRect();
            if (focused.right > floating.left && focused.left < floating.right && focused.bottom > floating.top && focused.top < floating.bottom) {
                window.scrollBy(0, focused.bottom - floating.top + 16);
            }
        });
    });
    listen(hint.querySelector('button'), 'click', forgetHint);
    try { hint.hidden = localStorage.getItem('kuking-wyglad-poznany') === '1'; } catch { hint.hidden = true; }
    const observer = new ResizeObserver(geometry);
    const nav = document.querySelector('.bottom-nav');
    if (nav) observer.observe(nav);
    listen(window, 'resize', geometry);
    geometry();
    cleanup = () => { events.abort(); observer.disconnect(); hint.hidden = true; };
}
initialize();
document.addEventListener('livewire:navigating', () => cleanup());
document.addEventListener('livewire:navigated', initialize);
