"""Kontrole bramki #2025 bez tokenu i bez wywołania produkcji."""

from __future__ import annotations

import copy
import io
import json
import runpy
import unittest
from unittest.mock import patch
from pathlib import Path

gate = runpy.run_path(str(Path(__file__).with_name("railway-ci-gated-deploy.py")))


class RailwayCiGateTest(unittest.TestCase):
    repo = "woogitsu/kuking.pl"
    sha = "a" * 40

    def fixture(self):
        run = {
            "id": 17, "workflow_id": 11, "name": "CI",
            "path": ".github/workflows/ci.yml", "event": "push",
            "head_branch": "main", "head_sha": self.sha,
            "repository": {"full_name": self.repo},
            "head_repository": {"full_name": self.repo},
            "status": "completed", "conclusion": "success", "run_attempt": 1,
        }
        event = {"workflow_run": {"id": 17, "head_sha": self.sha, "run_attempt": 1}}
        api = {
            f"/repos/{self.repo}/actions/workflows/ci.yml": {"id": 11},
            f"/repos/{self.repo}/actions/runs/17": run,
            f"/repos/{self.repo}/actions/runs/17/jobs?per_page=100&filter=latest": {
                "total_count": 1,
                "jobs": [{"name": "Testy (PostgreSQL 18)", "conclusion": "success"}],
            },
            f"/repos/{self.repo}/branches/main": {"commit": {"sha": self.sha}},
        }
        return event, api

    def test_only_exact_green_push_ci_on_current_main_passes(self):
        event, api = self.fixture()
        self.assertEqual(self.sha, gate["verify_ci"](event, self.repo, api.__getitem__))

        for path, change in (
            (f"/repos/{self.repo}/actions/runs/17", {"conclusion": "cancelled"}),
            (f"/repos/{self.repo}/actions/runs/17", {"event": "pull_request"}),
            (f"/repos/{self.repo}/actions/runs/17", {"workflow_id": 99}),
            (f"/repos/{self.repo}/branches/main", {"commit": {"sha": "b" * 40}}),
            (f"/repos/{self.repo}/actions/runs/17/jobs?per_page=100&filter=latest",
             {"total_count": 1, "jobs": [{"name": "Testy (PostgreSQL 18)", "conclusion": "failure"}]}),
        ):
            broken = copy.deepcopy(api)
            broken[path].update(change)
            with self.subTest(change=change), self.assertRaises(RuntimeError):
                gate["verify_ci"](event, self.repo, broken.__getitem__)

    def test_service_config_requires_web_first_and_complete_split(self):
        web = {"name": "kuking.pl", "id": "11111111-1111-4111-8111-111111111111"}
        worker = {"name": "worker", "id": "22222222-2222-4222-8222-222222222222"}
        scheduler = {"name": "scheduler", "id": "33333333-3333-4333-8333-333333333333"}
        self.assertEqual([web], gate["validate_services"](json.dumps([web])))
        self.assertEqual(3, len(gate["validate_services"](json.dumps([web, worker, scheduler]))))
        for wrong in ([worker, web, scheduler], [web, worker], [web, web]):
            with self.subTest(wrong=wrong), self.assertRaises(RuntimeError):
                gate["validate_services"](json.dumps(wrong))

    def test_run_attempt_requires_explicit_positive_number(self):
        self.assertEqual(1, gate["validate_run_attempt"]("1"))
        self.assertEqual(2, gate["validate_run_attempt"]("2"))
        for wrong in ("", "0", "-1", "1.0", "first"):
            with self.subTest(wrong=wrong), self.assertRaises(RuntimeError):
                gate["validate_run_attempt"](wrong)

    def test_wrong_project_token_stops_before_service_deploy(self):
        service = {"name": "kuking.pl", "id": "11111111-1111-4111-8111-111111111111"}
        calls = []

        def railway(_token, query, _variables):
            calls.append(query)
            return {"projectToken": {"projectId": "wrong", "environmentId": "production"}}

        with self.assertRaises(RuntimeError):
            gate["verify_railway"]("token", "project", "production", [service], railway)
        self.assertEqual(1, len(calls), "Błędny token nie może dojść do odczytu usług.")

    def test_graphql_errors_in_http_200_are_failures(self):
        response = io.BytesIO(json.dumps({"data": None, "errors": [{"message": "Not Authorized"}]}).encode())
        with patch.object(gate["graphql"].__globals__["request"], "urlopen", return_value=response):
            with self.assertRaises(RuntimeError):
                gate["graphql"]("token", "query { projectToken { projectId } }")

    def test_deploy_waits_for_web_before_worker_and_never_runs_after_failure(self):
        web = {"name": "kuking.pl", "id": "11111111-1111-4111-8111-111111111111"}
        worker = {"name": "worker", "id": "22222222-2222-4222-8222-222222222222"}
        actions = []
        deployment_ids = ["aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"]

        def railway(_token, query, variables):
            if "deployments(" in query:
                return {"deployments": {"edges": [], "pageInfo": {"hasNextPage": False}}}
            if "serviceInstanceDeployV2" in query:
                actions.append(("deploy", variables["serviceId"], variables["commitSha"]))
                return {"serviceInstanceDeployV2": deployment_ids[0 if variables["serviceId"] == web["id"] else 1]}
            actions.append(("poll", variables["id"]))
            return {"deployment": {"id": variables["id"], "status": "SUCCESS"}}

        gate["deploy"]("token", self.sha, "environment", [web, worker], self.repo,
                       lambda _path: {"commit": {"sha": self.sha}}, railway, 1,
                       lambda _seconds: None)
        self.assertEqual([("deploy", web["id"], self.sha), ("poll", deployment_ids[0]),
                          ("deploy", worker["id"], self.sha), ("poll", deployment_ids[1])], actions)

        actions.clear()

        def failed(_token, query, variables):
            if "deployments(" in query:
                return {"deployments": {"edges": [], "pageInfo": {"hasNextPage": False}}}
            actions.append(query)
            if "serviceInstanceDeployV2" in query:
                return {"serviceInstanceDeployV2": deployment_ids[0]}
            return {"deployment": {"id": variables["id"], "status": "FAILED"}}

        with self.assertRaises(RuntimeError):
            gate["deploy"]("token", self.sha, "environment", [web, worker], self.repo,
                           lambda _path: {"commit": {"sha": self.sha}}, failed, 1,
                           lambda _seconds: None)
        self.assertEqual(2, len(actions), "Awaria web musi zatrzymać worker.")

        actions.clear()
        with self.assertRaises(RuntimeError):
            gate["deploy"]("token", self.sha, "environment", [web], self.repo,
                           lambda _path: {"commit": {"sha": "b" * 40}}, railway, 1,
                           lambda _seconds: None)
        self.assertEqual([], actions, "Przesunięty main musi zatrzymać mutację Railway.")

    def test_lost_mutation_response_and_rerun_never_repeat_deploy(self):
        web = {"name": "kuking.pl", "id": "11111111-1111-4111-8111-111111111111"}
        calls = []

        def lost_response(_token, query, variables):
            if "deployments(" in query:
                return {"deployments": {"edges": [], "pageInfo": {"hasNextPage": False}}}
            calls.append((query, variables))
            raise OSError("connection reset after Railway accepted the request")

        with self.assertRaisesRegex(RuntimeError, "wynik mutacji jest niejednoznaczny"):
            gate["deploy"]("token", self.sha, "environment", [web], self.repo,
                           lambda _path: {"commit": {"sha": self.sha}}, lost_response, 1,
                           lambda _seconds: None)
        self.assertEqual(1, len(calls))
        self.assertIn("serviceInstanceDeployV2", calls[0][0])

        calls.clear()
        with self.assertRaisesRegex(RuntimeError, "nie wywołuję mutacji Railway"):
            gate["deploy"]("token", self.sha, "environment", [web], self.repo,
                           lambda _path: {"commit": {"sha": self.sha}}, lost_response, 2,
                           lambda _seconds: None)
        self.assertEqual([], calls, "Rerun nie może wywołać mutacji ani odpytywania Railway.")

    def test_rerun_stops_before_mutating_later_roles_even_after_success(self):
        web = {"name": "kuking.pl", "id": "11111111-1111-4111-8111-111111111111"}
        worker = {"name": "worker", "id": "22222222-2222-4222-8222-222222222222"}
        calls = []

        def railway(_token, query, _variables):
            calls.append(query)
            return {"serviceInstanceDeployV2": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"}

        with self.assertRaisesRegex(RuntimeError, "nie wywołuję mutacji Railway"):
            gate["deploy"]("token", self.sha, "environment", [web, worker], self.repo,
                           lambda _path: {"commit": {"sha": self.sha}}, railway, 2,
                           lambda _seconds: None)
        self.assertEqual([], calls)


class RerunPathTest(unittest.TestCase):
    """Tabela: rerun CI -> wdrożenie tego SHA, bez dubli i bez starszego SHA (#611)."""

    repo = "woogitsu/kuking.pl"
    web = {"name": "kuking.pl", "id": "11111111-1111-4111-8111-111111111111"}

    def world(self, head):
        return {"head": head, "runs": {}, "deployments": [], "mutations": [], "n": 0}

    def github(self, world):
        repo = self.repo

        def get(path):
            if path == f"/repos/{repo}/actions/workflows/ci.yml":
                return {"id": 11}
            if path == f"/repos/{repo}/branches/main":
                return {"commit": {"sha": world["head"]}}
            if path.endswith("/jobs?per_page=100&filter=latest"):
                run = world["runs"][int(path.split("/runs/")[1].split("/")[0])]
                ok = "success" if run["conclusion"] == "success" else "failure"
                return {"total_count": 1, "jobs": [{"name": "Testy (PostgreSQL 18)", "conclusion": ok}]}
            return world["runs"][int(path.rsplit("/", 1)[1])]
        return get

    def railway(self, world):
        def call(_token, query, variables):
            if "deployments(" in query:
                edges = [{"node": d} for d in world["deployments"]]
                return {"deployments": {"edges": edges, "pageInfo": {"hasNextPage": False}}}
            if "serviceInstanceDeployV2" in query:
                world["n"] += 1
                identifier = f"{world['n']:08d}-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
                world["mutations"].append(variables["commitSha"])
                world["deployments"].append({"id": identifier, "status": "SUCCESS",
                                             "meta": {"commitHash": variables["commitSha"]}})
                return {"serviceInstanceDeployV2": identifier}
            return {"deployment": {"id": variables["id"], "status": "SUCCESS"}}
        return call

    def receive(self, world, sha, attempt, conclusion, latest=None):
        """Zdarzenie workflow_run.completed dla próby `attempt`; zwraca "deploy"/"skip"/"red"."""
        world["runs"][17] = {
            "id": 17, "workflow_id": 11, "name": "CI", "path": ".github/workflows/ci.yml",
            "event": "push", "head_branch": "main", "head_sha": sha,
            "repository": {"full_name": self.repo}, "head_repository": {"full_name": self.repo},
            "status": "completed", "conclusion": conclusion if latest is None else "success",
            "run_attempt": latest or attempt,
        }
        event = {"workflow_run": {"id": 17, "head_sha": sha, "run_attempt": attempt}}
        github = self.github(world)
        before = len(world["mutations"])
        try:
            verified = gate["verify_ci"](event, self.repo, github)
            gate["deploy"]("token", verified, "env", [self.web], self.repo, github,
                           self.railway(world), 1, lambda _s: None)
        except gate["Skip"]:
            return "skip"
        except RuntimeError:
            return "red"
        return "deploy" if len(world["mutations"]) > before else "skip"

    def test_red_first_attempt_then_green_rerun_deploys_that_sha(self):
        world, sha = self.world("a" * 40), "a" * 40
        self.assertEqual("red", self.receive(world, sha, 1, "failure"))
        self.assertEqual([], world["mutations"])
        self.assertEqual("deploy", self.receive(world, sha, 2, "success"))
        self.assertEqual([sha], world["mutations"])

    def test_two_green_attempts_produce_one_deployment(self):
        world, sha = self.world("a" * 40), "a" * 40
        self.assertEqual("deploy", self.receive(world, sha, 1, "success"))
        self.assertEqual("skip", self.receive(world, sha, 2, "success"))
        self.assertEqual([sha], world["mutations"])

    def test_event_of_superseded_attempt_is_skipped(self):
        world, sha = self.world("a" * 40), "a" * 40
        self.assertEqual("skip", self.receive(world, sha, 1, "success", latest=2))
        self.assertEqual([], world["mutations"])

    def test_older_sha_after_newer_is_skipped(self):
        world, old, new = self.world("b" * 40), "a" * 40, "b" * 40
        self.assertEqual("deploy", self.receive(world, new, 1, "success"))
        self.assertEqual("skip", self.receive(world, old, 1, "success"))
        self.assertEqual([new], world["mutations"])

    def test_deployment_in_progress_for_same_sha_is_not_duplicated(self):
        world, sha = self.world("a" * 40), "a" * 40
        world["deployments"].append({"id": "x", "status": "BUILDING", "meta": {"commitHash": sha}})
        self.assertEqual("red", self.receive(world, sha, 2, "success"))
        self.assertEqual([], world["mutations"])

    def test_failed_earlier_deployment_of_same_sha_is_retried(self):
        world, sha = self.world("a" * 40), "a" * 40
        world["deployments"].append({"id": "x", "status": "REMOVED", "meta": {"commitHash": sha}})
        self.assertEqual("deploy", self.receive(world, sha, 2, "success"))

    def test_statuses_of_railway_schema_are_classified(self):
        """Wartości DeploymentStatus ze schematu Railway (railwayapp/cli, src/gql/schema.json)."""
        sha = "a" * 40
        expected = {"SUCCESS": "success", "INITIALIZING": "active", "QUEUED": "active",
                    "WAITING": "active", "BUILDING": "active", "DEPLOYING": "active",
                    "NEEDS_APPROVAL": "active", "FAILED": "none", "CRASHED": "none",
                    "SKIPPED": "none", "REMOVED": "none", "REMOVING": "active"}
        for status, state in expected.items():
            call = lambda _t, _q, _v, status=status: {"deployments": {"edges": [
                {"node": {"id": "x", "status": status, "meta": {"commitHash": sha}}}],
                "pageInfo": {"hasNextPage": False}}}
            self.assertEqual(state, gate["deployment_state"]("t", sha, "env", self.web, call), status)
        for status in ("REMOVING", "REMOVED"):
            self.assertIn(status, gate["TERMINAL_FAILURE"])

    def test_removing_deployment_of_same_sha_blocks_new_deploy(self):
        """#2234: REMOVING to operacja w toku, nie brak wdrożenia."""
        world, sha = self.world("a" * 40), "a" * 40
        world["deployments"].append({"id": "x", "status": "REMOVING", "meta": {"commitHash": sha}})
        self.assertEqual("red", self.receive(world, sha, 1, "success"))
        self.assertEqual([], world["mutations"])

    def paged(self, pages):
        """Atrapa Railway: lista wdrożeń w stronach; zapisuje kursory żądań."""
        requests = []

        def call(_token, query, variables):
            self.assertIn("deployments(", query)
            self.assertIn("pageInfo { hasNextPage endCursor }", query)
            requests.append(variables.get("after"))
            index = 0 if variables.get("after") is None else int(variables["after"].split("-")[1])
            edges, page_info = pages[index]
            return {"deployments": {"edges": [{"node": n} for n in edges], "pageInfo": page_info}}
        return call, requests

    def test_success_on_later_page_is_found_and_not_redeployed(self):
        """#2234: SHA spoza pierwszej strony nie może dać "none"."""
        sha, other = "a" * 40, "b" * 40
        filler = [{"id": str(i), "status": "SUCCESS", "meta": {"commitHash": other}} for i in range(50)]
        call, requests = self.paged([
            (filler, {"hasNextPage": True, "endCursor": "k-1"}),
            (filler, {"hasNextPage": True, "endCursor": "k-2"}),
            ([{"id": "stary", "status": "SUCCESS", "meta": {"commitHash": sha}}], {"hasNextPage": False}),
        ])
        self.assertEqual("success", gate["deployment_state"]("t", sha, "env", self.web, call))
        self.assertEqual([None, "k-1", "k-2"], requests)

        world = self.world(sha)
        mutations = []

        def railway(t, q, v):
            if "deployments(" in q:
                return call(t, q, v)
            mutations.append(v)
            raise AssertionError("mutacja Railway przy już wdrożonym SHA")

        gate["deploy"]("token", sha, "env", [self.web], self.repo, self.github(world), railway, 1,
                       lambda _s: None)
        self.assertEqual([], mutations)

    def test_incomplete_deployment_history_fails_closed(self):
        """#2234: brak pageInfo, pusty lub powtórzony kursor, zbyt wiele stron -> odmowa."""
        sha = "a" * 40
        broken_pages = {
            "brak pageInfo": ([([], None)], "bez pageInfo"),
            "hasNextPage bez kursora": ([([], {"hasNextPage": True})], "bez nowego kursora"),
            "pusty kursor": ([([], {"hasNextPage": True, "endCursor": ""})], "bez nowego kursora"),
            "kursor w kółko": ([([], {"hasNextPage": True, "endCursor": "k-1"}),
                                ([], {"hasNextPage": True, "endCursor": "k-1"})], "bez nowego kursora"),
        }
        for label, (pages, reason) in broken_pages.items():
            call, _requests = self.paged(pages)
            with self.subTest(label), self.assertRaisesRegex(RuntimeError, reason):
                gate["deployment_state"]("t", sha, "env", self.web, call)

        endless = [([], {"hasNextPage": True, "endCursor": f"k-{i + 1}"})
                   for i in range(gate["DEPLOYMENTS_MAX_PAGES"] + 1)]
        call, requests = self.paged(endless)
        with self.assertRaisesRegex(RuntimeError, "nie przeczytałem całej"):
            gate["deployment_state"]("t", sha, "env", self.web, call)
        self.assertEqual(gate["DEPLOYMENTS_MAX_PAGES"], len(requests))

    def test_checkout_must_be_exact_ci_sha(self):
        """#2233: skrypt z nowszego main nie wdraża starszego SHA."""
        sha = "a" * 40
        gate["verify_checkout"](sha, sha + "\n")
        for head in ("b" * 40, "", "HEAD", sha[:12]):
            with self.subTest(head=head), self.assertRaises(RuntimeError):
                gate["verify_checkout"](sha, head)

    def test_unreadable_deployment_list_fails_closed(self):
        world, sha = self.world("a" * 40), "a" * 40
        railway = self.railway(world)
        broken = lambda t, q, v: {} if "deployments(" in q else railway(t, q, v)
        with self.assertRaises(RuntimeError):
            gate["deploy"]("token", sha, "env", [self.web], self.repo, self.github(world),
                           broken, 1, lambda _s: None)
        self.assertEqual([], world["mutations"])


if __name__ == "__main__":
    unittest.main()
