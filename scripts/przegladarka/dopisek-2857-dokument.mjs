/*
 * Funkcja wykonuje się na pustej stronie Chromium. Dokument z fixture pozostaje
 * odłączony: nie przenosimy jego węzłów do bieżącej strony ani nie uruchamiamy
 * pobocznych skryptów. Dopiero wynik bez elementów script trafia do formularza.
 */
export function przygotujDokument({html, stylesheetHref}) {
    const dokument = new DOMParser().parseFromString(html, 'text/html');
    for (const skrypt of dokument.querySelectorAll('script')) {
        skrypt.remove();
    }
    const arkusz = dokument.createElement('link');
    arkusz.rel = 'stylesheet';
    arkusz.setAttribute('href', stylesheetHref);
    dokument.head.append(arkusz);
    return '<!DOCTYPE html>\n' + dokument.documentElement.outerHTML;
}
