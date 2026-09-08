/**
 * Podsumowanie błędów na górze formularza, z linkami do pól.
 * Logowanie, rejestracja, dodaj zdjęcie, dodaj przepis.
 * @dsAdherence Nigdy „Formularz zawiera błędy”. Kolejność błędów jest kolejnością pól. Tekst pozycji to ten sam tekst, który stoi przy polu.
 */
export interface BladPola {
  /** Identyfikator pola — linkiem #id skacze się do niego bez skryptu. */
  id: string;
  /** Ten sam tekst, który stoi przy polu. Dwa różne opisy tego samego błędu to dwa błędy. */
  tekst: string;
}

export interface ErrorSummaryProps {
  /** Błędy w kolejności pól w formularzu, nie w kolejności ważności. */
  bledy: BladPola[];
  className?: string;
}

export declare function ErrorSummary(props: ErrorSummaryProps): JSX.Element | null;
