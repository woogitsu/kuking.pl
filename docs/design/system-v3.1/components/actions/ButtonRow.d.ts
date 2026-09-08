/**
 * Rząd przycisków obok siebie. Zawija się przy 320 px zamiast wystawać.
 * @dsAdherence Kolejność w kodzie jest kolejnością fokusu: główny, wtórny, cichy, destrukcyjny na końcu.
 */
export interface ButtonRowProps {
  className?: string;
  children: React.ReactNode;
}

export declare function ButtonRow(props: ButtonRowProps): JSX.Element;
