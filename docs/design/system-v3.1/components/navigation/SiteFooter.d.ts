/**
 * Stopka — odnośniki prawne, zdanie o serwisie i jedyne miejsce, w którym
 * przełącza się motyw. Wszystkie piętnaście ekranów.
 * @dsAdherence Przełącznik motywu jest formularzem, nie skryptem. Nigdy ikonka słońca i księżyca. Nie wkładaj do stopki nawigacji serwisu.
 */
export interface LinkStopki {
  href: string;
  label: string;
}

export interface SiteFooterProps {
  /** Domyślnie: Zasady, Prywatność, Regulamin, Pomoc. */
  linki?: LinkStopki[];
  /** Wybrany motyw — przycisk dostaje aria-pressed i inną wagę. Bez tej właściwości stopka trzyma wybór sama i przestawia data-theme na <html>. */
  motyw?: "jasny" | "ciemny";
  onMotyw?: (motyw: "jasny" | "ciemny") => void;
  /** Zdanie o serwisie. Domyślnie „Prowadzimy to na własną rękę.” */
  zdanie?: React.ReactNode;
  /** stopka-www na stronie publicznej (bez zapasu na dolny pasek). */
  className?: string;
}

export declare function SiteFooter(props: SiteFooterProps): JSX.Element;
