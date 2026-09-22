// Jedna lista narzędzi. Bez JS pozostaje widoczna; na telefonie nie spycha
// kolejki pod cały spis. Próg jest ten sam co w marka-panel.css.
const menus = new Map();

function initialize() {
    document.querySelectorAll('[data-marka-panel] .side-nav[data-tryb-panelu]').forEach(nav => {
        if (menus.has(nav) || typeof window.matchMedia !== 'function') return;
        const button = nav.querySelector('[data-panel-menu-przelacznik]');
        const content = nav.querySelector('[data-panel-menu-tresc]');
        const back = nav.querySelector('.side-nav-powrot');
        if (!button || !content || !back) return;

        const desktop = window.matchMedia('(min-width: 64rem)');
        let expanded = false;
        const render = () => {
            content.hidden = !desktop.matches && !expanded;
            button.setAttribute('aria-expanded', String(!content.hidden));
            button.hidden = desktop.matches;
        };
        const resize = () => {
            // Nie chowamy skupionego linku, gdy okno przechodzi na mobile.
            expanded = content.contains(document.activeElement);
            if (desktop.matches && document.activeElement === button) back.focus();
            render();
        };
        const toggle = () => {
            if (desktop.matches) return;
            expanded = !expanded;
            render();
        };
        const escape = event => {
            if (event.key !== 'Escape' || desktop.matches || !expanded) return;
            event.preventDefault();
            button.focus();
            expanded = false;
            render();
        };
        button.addEventListener('click', toggle);
        nav.addEventListener('keydown', escape);
        desktop.addEventListener('change', resize);
        menus.set(nav, () => {
            button.removeEventListener('click', toggle);
            nav.removeEventListener('keydown', escape);
            desktop.removeEventListener('change', resize);
            content.hidden = false;
            button.hidden = true;
            button.setAttribute('aria-expanded', 'true');
        });
        resize();
    });
}

initialize();
document.addEventListener('livewire:navigated', initialize);
document.addEventListener('livewire:navigating', () => {
    menus.forEach(cleanup => cleanup());
    menus.clear();
});
