/**
 * Przycisk. Uruchamia akcję i mówi wprost, która akcja na tym ekranie jest
 * ważniejsza od pozostałych. Stoi na wszystkich piętnastu ekranach.
 * @dsAdherence Dokładnie jeden btn-primary na ekran. Nigdy ikona bez napisu. Nigdy disabled bez zdania „co zrobić”. Minimum 48 px wysokości.
 */
export interface ButtonProps {
  /**
   * Waga przycisku.
   * - primary: jedna akcja, po którą ekran istnieje — DOKŁADNIE JEDEN na ekran
   * - secondary: akcja realna, ale nie główna („Zapisz szkic”, „Obserwuj”)
   * - quiet: akcja poboczna („Anuluj”, „Zapisz”, licznik komentarzy)
   * - danger: akcja, której nie da się cofnąć — jeden, w osobnej sekcji
   */
  waga?: "primary" | "secondary" | "quiet" | "danger";
  /** 56 px — akcja główna na telefonie. */
  duzy?: boolean;
  /** Na całą szerokość kolumny. */
  pelny?: boolean;
  /** Podany adres zamienia przycisk na odnośnik. Do przejścia pod adres, nie do akcji. */
  href?: string;
  type?: "button" | "submit" | "reset";
  disabled?: boolean;
  /** Wyłączony, ale zostaje w kolejności fokusu — żeby dało się usłyszeć wyjaśnienie. */
  ariaDisabled?: boolean;
  /** Zdanie „co zrobić, żeby go odblokować”. WYMAGANE przy disabled. */
  wyjasnienie?: string;
  /** Nazwa ikony obok napisu. Ikona nigdy nie występuje sama. */
  ikona?: string;
  className?: string;
  children: React.ReactNode;
  onClick?: (e: React.MouseEvent) => void;
  "aria-pressed"?: boolean | "true" | "false";
}

export declare function Button(props: ButtonProps): JSX.Element;
