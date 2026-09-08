/**
 * Pole formularza — zbiera jedną informację i mówi przed wpisaniem, czego
 * oczekujemy. Logowanie, rejestracja, komentarz, szukanie, dodaj zdjęcie,
 * dodaj przepis, czytelność.
 * @dsAdherence Etykieta zawsze nad polem, nigdy sam placeholder. Nigdy gwiazdki. Nigdy height na polu. Błąd trzyma schemat: co się stało, dlaczego, co zrobić.
 */
export interface FieldProps {
  /** Identyfikator pola. Wiąże etykietę, podpowiedź i błąd. */
  id: string;
  /** Treść etykiety. Stoi NAD polem. */
  etykieta: React.ReactNode;
  typ?: "text" | "textarea" | "select" | "number" | "email" | "password" | "search" | "tel" | "url";
  /**
   * Szerokość dobrana do treści (D-109). Prostokąt jest obietnicą długości
   * odpowiedzi: rok 8ch, liczba 10ch, krotkie 22ch, srednie 40ch, pelne 100%.
   */
  szerokosc?: "rok" | "liczba" | "krotkie" | "srednie" | "pelne";
  /** Dopisuje słowo „wymagane”. Oznaczamy wymagane, nie nieobowiązkowe. */
  wymagane?: boolean;
  /** Zdanie nad polem: „Tak, jak mówisz o nim w domu.” */
  podpowiedz?: React.ReactNode;
  /** Pomoc pod polem, stała i nieznikająca. */
  help?: React.ReactNode;
  /** Komunikat błędu: co się stało, dlaczego, co zrobić. Włącza has-error i aria-invalid. */
  blad?: React.ReactNode;
  /** textarea 12rem zamiast 7rem — dla historii przepisu. Wysokość pola jest zaproszeniem do pisania. */
  dlugie?: boolean;
  /** Pozycje listy rozwijanej przy typ="select". */
  opcje?: Array<{ value: string; label: string }>;
  disabled?: boolean;
  /** Zdanie, dlaczego pole jest wyłączone. WYMAGANE przy disabled. */
  powodWylaczenia?: React.ReactNode;
  className?: string;
  /** Własna kontrolka zamiast domyślnej — np. grupa przycisków radiowych. */
  children?: React.ReactNode;
  name?: string;
  value?: string;
  defaultValue?: string;
  placeholder?: string;
  onChange?: (e: React.ChangeEvent) => void;
}

/** Dwa pola w jednym wierszu. Zawija się przy 320 px. */
export interface FieldRowProps {
  className?: string;
  children: React.ReactNode;
}

export declare function Field(props: FieldProps): JSX.Element;
export declare function FieldRow(props: FieldRowProps): JSX.Element;
