#!/usr/bin/env python3
"""Wdraża bieżący main dopiero po zakończonym zielonym workflow CI (#2025)."""

from __future__ import annotations

import json
import os
import re
import sys
import time
from urllib import request

RAILWAY_URL = "https://backboard.railway.com/graphql/v2"
SHA = re.compile(r"^[0-9a-f]{40}$")
UUID = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
TERMINAL_FAILURE = {"FAILED", "CRASHED", "SKIPPED", "REMOVED", "REMOVING"}
IN_PROGRESS = {"INITIALIZING", "QUEUED", "WAITING", "BUILDING", "DEPLOYING", "NEEDS_APPROVAL"}


class Skip(RuntimeError):
    """Poprawne pominięcie z powodem: kod wyjścia 0, bez mutacji Railway."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def read_json(url: str, token: str) -> dict:
    req = request.Request(url, headers={
        "Authorization": f"Bearer {token}",
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
    })
    with request.urlopen(req, timeout=20) as response:
        return json.load(response)


def graphql(token: str, query: str, variables: dict | None = None) -> dict:
    payload = json.dumps({"query": query, "variables": variables or {}}).encode()
    req = request.Request(RAILWAY_URL, data=payload, headers={
        "Project-Access-Token": token,
        "Content-Type": "application/json",
    })
    with request.urlopen(req, timeout=30) as response:
        result = json.load(response)
    require(not result.get("errors"), f"Railway GraphQL odrzucił żądanie: {result.get('errors')}")
    require(isinstance(result.get("data"), dict), "Railway nie zwrócił danych GraphQL.")
    return result["data"]


def validate_services(raw: str) -> list[dict[str, str]]:
    services = json.loads(raw)
    require(isinstance(services, list) and 1 <= len(services) <= 3,
            "Podaj uporządkowaną listę 1–3 usług Railway.")
    seen: set[str] = set()
    for service in services:
        require(isinstance(service, dict), "Każda usługa wymaga name oraz id.")
        name, identifier = service.get("name"), service.get("id")
        require(isinstance(name, str) and name in {"kuking.pl", "worker", "scheduler"},
                "Nieznana nazwa usługi produkcyjnej.")
        require(isinstance(identifier, str) and UUID.fullmatch(identifier) is not None,
                "Niepoprawny identyfikator usługi Railway.")
        require(name not in seen, "Powtórzona usługa Railway.")
        seen.add(name)
    require(services[0]["name"] == "kuking.pl", "Serwis WWW z migracjami musi być pierwszy.")
    require(seen == {"kuking.pl"} or seen == {"kuking.pl", "worker", "scheduler"},
            "Konfiguracja musi wskazywać jeden serwis all albo komplet trzech ról.")
    return services


def validate_run_attempt(raw: str) -> int:
    require(raw.isdecimal() and int(raw) > 0, "Brak poprawnego GITHUB_RUN_ATTEMPT.")
    return int(raw)


def verify_ci(event: dict, repo: str, github_get) -> str:
    source = event.get("workflow_run") or {}
    run_id = source.get("id")
    require(isinstance(run_id, int) and run_id > 0, "Brak ID przebiegu CI.")
    workflow = github_get(f"/repos/{repo}/actions/workflows/ci.yml")
    run = github_get(f"/repos/{repo}/actions/runs/{run_id}")
    sha = run.get("head_sha")
    require(run.get("workflow_id") == workflow.get("id") and run.get("name") == "CI",
            "Zdarzenie nie dotyczy właściwego workflow CI.")
    require(run.get("path") == ".github/workflows/ci.yml", "Nieoczekiwana ścieżka workflow CI.")
    require(run.get("event") == "push" and run.get("head_branch") == "main",
            "Wymagany jest push do main.")
    require(run.get("repository", {}).get("full_name") == repo and
            run.get("head_repository", {}).get("full_name") == repo,
            "Przebieg CI pochodzi z innego repozytorium.")
    require(run.get("status") == "completed" and run.get("conclusion") == "success",
            "CI nie zakończyło się sukcesem.")
    require(isinstance(sha, str) and SHA.fullmatch(sha) is not None,
            "Niepoprawny SHA przebiegu CI.")
    require(source.get("head_sha") == sha and source.get("id") == run_id,
            "Zdarzenie i aktualny odczyt CI wskazują różne commity.")
    # workflow_run `completed` przychodzi dla każdej próby tego samego przebiegu
    # (rerun). Zdarzenie starszej próby nie wdraża: o SHA decyduje najnowsza
    # próba i to ona ma własne zdarzenie.
    event_attempt, latest_attempt = source.get("run_attempt"), run.get("run_attempt")
    require(isinstance(event_attempt, int) and isinstance(latest_attempt, int),
            "Brak numeru próby (run_attempt) przebiegu CI.")
    if event_attempt != latest_attempt:
        raise Skip(f"Zdarzenie dotyczy próby {event_attempt} CI, a najnowsza to {latest_attempt}; "
                   "pomijam — wdroży ją zdarzenie najnowszej próby.")

    jobs = github_get(f"/repos/{repo}/actions/runs/{run_id}/jobs?per_page=100&filter=latest")
    require(jobs.get("total_count") == len(jobs.get("jobs", [])),
            "Lista jobów CI jest niepełna; nie wdrażam.")
    aggregate = [job for job in jobs["jobs"] if job.get("name") == "Testy (PostgreSQL 18)"]
    require(len(aggregate) == 1 and aggregate[0].get("conclusion") == "success",
            "Wymagany zbiorczy job PostgreSQL 18 nie jest zielony.")

    branch = github_get(f"/repos/{repo}/branches/main")
    if branch.get("commit", {}).get("sha") != sha:
        raise Skip(f"Main przesunął się od zakończenia CI; starszego SHA {sha} nie wdrażam.")
    return sha


def verify_railway(token: str, project_id: str, environment_id: str,
                   services: list[dict[str, str]], call) -> None:
    info = call(token, "query { projectToken { projectId environmentId } }", {})
    scoped = info.get("projectToken") or {}
    require(scoped.get("projectId") == project_id and scoped.get("environmentId") == environment_id,
            "Token Railway nie należy do wskazanego projektu production.")
    query = """query($id: String!, $environmentId: String!) {
      service(id: $id) { id name projectId }
      serviceInstance(serviceId: $id, environmentId: $environmentId) { id serviceName }
    }"""
    for service in services:
        data = call(token, query, {"id": service["id"], "environmentId": environment_id})
        actual = data.get("service") or {}
        instance = data.get("serviceInstance") or {}
        require(actual.get("id") == service["id"] and actual.get("name") == service["name"] and
                actual.get("projectId") == project_id and instance.get("serviceName") == service["name"],
                f"Usługa {service['name']} nie należy do wskazanego projektu i środowiska.")


def deployment_state(token: str, sha: str, environment_id: str, service: dict[str, str],
                     call) -> str:
    """Stan wdrożeń danego SHA w usłudze: "success", "active" (w toku) albo "none".

    Odczyt jest fail-closed: brak poprawnej odpowiedzi to błąd, nie "none".
    REMOVED/FAILED/CRASHED nie liczą się jako wdrożenie — tak Railway oznacza
    też nieudane próby, które rerun CI ma prawo powtórzyć.
    """
    query = """query($input: DeploymentListInput!) {
      deployments(first: 50, input: $input) { edges { node { id status meta } } }
    }"""
    data = call(token, query, {"input": {"serviceId": service["id"], "environmentId": environment_id}})
    listing = data.get("deployments")
    require(isinstance(listing, dict) and isinstance(listing.get("edges"), list),
            f"Railway {service['name']}: nie odczytano listy wdrożeń; nie wdrażam w ciemno.")
    states = set()
    for edge in listing["edges"]:
        node = (edge or {}).get("node") or {}
        meta = node.get("meta") if isinstance(node.get("meta"), dict) else {}
        if meta.get("commitHash") == sha:
            states.add(node.get("status"))
    if "SUCCESS" in states:
        return "success"
    return "active" if states & IN_PROGRESS else "none"


def deploy(token: str, sha: str, environment_id: str, services: list[dict[str, str]],
           repo: str, github_get, call, run_attempt: int, sleep=time.sleep) -> None:
    mutation = """mutation($serviceId: String!, $environmentId: String!, $commitSha: String!) {
      serviceInstanceDeployV2(serviceId: $serviceId, environmentId: $environmentId, commitSha: $commitSha)
    }"""
    query = "query($id: String!) { deployment(id: $id) { id status } }"
    # Railway nie obiecuje exactly-once dla mutacji. Bez pewnego ID i
    # potwierdzonego SHA w metadanych nie wolno zgadywać, czy poprzednia
    # próba już utworzyła deployment. Rerun wymaga ręcznego uzgodnienia.
    require(run_attempt == 1,
            f"Ponowienie workflow (próba {run_attempt}) dla SHA {sha}: "
            "nie wywołuję mutacji Railway. Uzgodnij deploymenty każdej usługi "
            "i środowiska w Railway przed osobnym wznowieniem.")
    # Po rozpoczęciu sekwencji wszystkie role muszą otrzymać ten sam SHA.
    # Przesunięcie main w jej trakcie uruchomi osobną, późniejszą sekwencję.
    if github_get(f"/repos/{repo}/branches/main").get("commit", {}).get("sha") != sha:
        raise Skip(f"Main przesunął się przed rozpoczęciem wdrożenia; SHA {sha} pomijam.")
    for service in services:
        # Idempotencja: ten sam SHA nie jest wdrażany drugi raz (dwa zielone
        # przebiegi CI, rerun po częściowym wdrożeniu).
        state = deployment_state(token, sha, environment_id, service, call)
        require(state != "active",
                f"Railway {service['name']}: SHA {sha} jest właśnie wdrażany (natywny autodeploy?). "
                "Nie dubluję; sprawdź w Railway i ponów CI po jego zakończeniu.")
        if state == "success":
            print(f"Railway {service['name']}: SHA {sha} już wdrożony — pomijam.", flush=True)
            continue
        try:
            result = call(token, mutation, {"serviceId": service["id"],
                                            "environmentId": environment_id, "commitSha": sha})
        except (OSError, RuntimeError, ValueError) as exc:
            raise RuntimeError(
                f"Railway {service['name']}, SHA {sha}: wynik mutacji jest niejednoznaczny. "
                "Nie ponawiaj workflow; uzgodnij wdrożenie w Railway."
            ) from exc
        deployment_id = result.get("serviceInstanceDeployV2")
        require(isinstance(deployment_id, str) and UUID.fullmatch(deployment_id) is not None,
                f"Railway {service['name']}, SHA {sha}: brak ID wdrożenia. "
                "Nie ponawiaj workflow; uzgodnij wdrożenie w Railway.")
        print(f"Railway {service['name']}: deployment {deployment_id}, SHA {sha}", flush=True)
        for _ in range(60):
            state = (call(token, query, {"id": deployment_id}).get("deployment") or {}).get("status")
            if state == "SUCCESS":
                print(f"Railway {service['name']}: SUCCESS", flush=True)
                break
            require(state not in TERMINAL_FAILURE, f"Railway {service['name']}: {state}.")
            sleep(30)
        else:
            raise RuntimeError(f"Railway {service['name']}: brak terminalnego wyniku po 30 minutach.")


def main() -> None:
    repo = os.environ["GITHUB_REPOSITORY"]
    require(repo == "woogitsu/kuking.pl", "Nieoczekiwane repozytorium.")
    token = os.environ["GH_TOKEN"]
    railway_token = os.environ["RAILWAY_TOKEN_PRODUCTION"]
    project_id = os.environ["RAILWAY_PRODUCTION_PROJECT_ID"]
    environment_id = os.environ["RAILWAY_PRODUCTION_ENVIRONMENT_ID"]
    require(UUID.fullmatch(project_id) is not None and UUID.fullmatch(environment_id) is not None,
            "Brak poprawnych ID projektu lub środowiska production.")
    services = validate_services(os.environ["RAILWAY_PRODUCTION_SERVICES_JSON"])
    with open(os.environ["GITHUB_EVENT_PATH"], encoding="utf-8") as event_file:
        event = json.load(event_file)
    api_url = os.environ.get("GITHUB_API_URL", "https://api.github.com")
    github_get = lambda path: read_json(api_url + path, token)
    sha = verify_ci(event, repo, github_get)
    verify_railway(railway_token, project_id, environment_id, services, graphql)
    run_attempt = validate_run_attempt(os.environ.get("GITHUB_RUN_ATTEMPT", ""))
    deploy(railway_token, sha, environment_id, services, repo, github_get,
           graphql, run_attempt)


if __name__ == "__main__":
    try:
        main()
    except Skip as skip:
        print(f"Bramka Railway: pominięto — {skip}")
    except (KeyError, ValueError, RuntimeError, OSError) as exc:
        print(f"Bramka Railway: {exc}", file=sys.stderr)
        sys.exit(1)
