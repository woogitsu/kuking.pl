"""Regresje odczytu wyjścia procesu w kontrolach negatywnych."""

import contextlib
import io
import sys
import unittest

from kontrola_wyjscia_testu import run_test


def process_with_output(payload, exit_code):
    return [
        sys.executable,
        "-c",
        "import sys; sys.stdout.buffer.write(bytes.fromhex(sys.argv[1])); "
        "sys.exit(int(sys.argv[2]))",
        payload.hex(),
        str(exit_code),
    ]


class RunTestOutputTest(unittest.TestCase):
    def test_malformed_utf8_does_not_hide_real_failed_assertion(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            run_test("broken-encoding", False, process_with_output(b"\xff FAILED\n", 1))
        self.assertIn("\ufffd FAILED", output.getvalue())

    def test_malformed_utf8_alone_is_not_proof_of_failed_assertion(self):
        with contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(RuntimeError, "Brak dowodu niezaliczonej asercji"):
                run_test("process-error", False, process_with_output(b"\xff process error\n", 1))

    def test_expected_success_still_rejects_nonzero_exit(self):
        with contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(RuntimeError, "Nieoczekiwany wynik testu"):
                run_test("expected-success", True, process_with_output(b"\xff FAILED\n", 1))


if __name__ == "__main__":
    unittest.main()
