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
            "status": "completed", "conclusion": "success",
        }
        event = {"workflow_run": {"id": 17, "head_sha": self.sha}}
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
            if "serviceInstanceDeployV2" in query:
                actions.append(("deploy", variables["serviceId"], variables["commitSha"]))
                return {"serviceInstanceDeployV2": deployment_ids[0 if variables["serviceId"] == web["id"] else 1]}
            actions.append(("poll", variables["id"]))
            return {"deployment": {"id": variables["id"], "status": "SUCCESS"}}

        gate["deploy"]("token", self.sha, "environment", [web, worker], self.repo,
                       lambda _path: {"commit": {"sha": self.sha}}, railway, lambda _seconds: None)
        self.assertEqual([("deploy", web["id"], self.sha), ("poll", deployment_ids[0]),
                          ("deploy", worker["id"], self.sha), ("poll", deployment_ids[1])], actions)

        actions.clear()

        def failed(_token, query, variables):
            actions.append(query)
            if "serviceInstanceDeployV2" in query:
                return {"serviceInstanceDeployV2": deployment_ids[0]}
            return {"deployment": {"id": variables["id"], "status": "FAILED"}}

        with self.assertRaises(RuntimeError):
            gate["deploy"]("token", self.sha, "environment", [web, worker], self.repo,
                           lambda _path: {"commit": {"sha": self.sha}}, failed, lambda _seconds: None)
        self.assertEqual(2, len(actions), "Awaria web musi zatrzymać worker.")

        actions.clear()
        with self.assertRaises(RuntimeError):
            gate["deploy"]("token", self.sha, "environment", [web], self.repo,
                           lambda _path: {"commit": {"sha": "b" * 40}}, railway, lambda _seconds: None)
        self.assertEqual([], actions, "Przesunięty main musi zatrzymać mutację Railway.")


if __name__ == "__main__":
    unittest.main()
