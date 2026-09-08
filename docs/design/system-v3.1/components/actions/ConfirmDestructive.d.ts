/**
 * Potwierdzenie akcji destrukcyjnej — ostatni moment na zawrócenie przed
 * rzeczą, której nie da się cofnąć samodzielnie. Profil, przepis, tablica,
 * porzucenie szkicu.
 * @dsAdherence Nigdy confirm() ani modal sterowany skryptem. „Zostaw” zawsze jest i nigdy nie jest samym krzyżykiem. Nie łagodź nazwy akcji.
 */
export interface ConfirmDestructiveProps {
  /** Napis na przycisku rozwijającym, np. „Usuń wpis”. */
  etykieta: string;
  /** Pełne zdanie mówiące, co zniknie — przy przepisie także to, że znikną cudze wykonania i komentarze. */
  pytanie: string;
  /** Napis na przycisku potwierdzenia. Domyślnie „Tak, usuń”. */
  potwierdzenie?: string;
  /** Napis na wyjściu. Domyślnie „Zostaw”. */
  anuluj?: string;
  /** Adres formularza POST. */
  action?: string;
  /** Adres powrotu dla „Zostaw”. */
  hrefAnuluj?: string;
  onPotwierdz?: (e: React.MouseEvent) => void;
  /** Rozwinięte na start — do podglądu stanu w galerii. */
  otwarte?: boolean;
  /** Własna treść pytania zamiast domyślnej pary przycisków. */
  children?: React.ReactNode;
}

export declare function ConfirmDestructive(props: ConfirmDestructiveProps): JSX.Element;
