// Dwie niezależne kolumny bez przenoszenia kart w DOM (#560).
// Bez ResizeObserver zostaje czytelna siatka; żadna akcja nie zależy od układu.
const layouts = new Map();

function initialize() {
    if (!window.ResizeObserver) return;
    document.querySelectorAll('.landing-wpisy-dwie').forEach((container) => {
        if (layouts.has(container)) return;
        const cards = [...container.children];
        let frame = 0;
        const arrange = () => {
            frame = 0;
            const columns = Number(getComputedStyle(container).getPropertyValue('--landing-wpisy-kolumny'));
            if (columns !== 2) {
                delete container.dataset.zwarteKolumny;
                cards.forEach((card) => {
                    card.style.removeProperty('grid-row');
                    card.style.removeProperty('grid-column');
                });
                return;
            }
            container.dataset.zwarteKolumny = '';
            const next = [1, 1];
            cards.forEach((card, index) => {
                const column = index % 2;
                const gap = parseFloat(getComputedStyle(card).marginBottom) || 0;
                const span = Math.ceil(card.getBoundingClientRect().height + gap);
                card.style.gridColumn = String(column + 1);
                card.style.gridRow = `${next[column]} / span ${Math.max(1, span)}`;
                next[column] += Math.max(1, span);
            });
        };
        const schedule = () => {
            if (!frame) frame = requestAnimationFrame(arrange);
        };
        const observer = new ResizeObserver(schedule);
        observer.observe(container);
        cards.forEach((card) => observer.observe(card));
        layouts.set(container, () => {
            observer.disconnect();
            cancelAnimationFrame(frame);
        });
        schedule();
    });
}

initialize();
document.addEventListener('livewire:navigated', initialize);
document.addEventListener('livewire:navigating', () => {
    layouts.forEach((cleanup) => cleanup());
    layouts.clear();
});
