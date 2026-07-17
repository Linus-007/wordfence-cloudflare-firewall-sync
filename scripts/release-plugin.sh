#!/usr/bin/env bash

set -Eeuo pipefail

REPO_ROOT="${HOME}/src/security-review/wordfence-cloudflare-firewall-sync"
RELEASE_CONFIG="${HOME}/.config/greyrock-release/svn.env"

EXPECTED_FORK="git@github.com:Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.git"
EXPECTED_SVN_URL="https://plugins.svn.wordpress.org/grey-rock-block-synchroniser-for-wordfence-and-cloudflare"
GITHUB_REPOSITORY="Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare"

PLUGIN_SLUG="grey-rock-block-synchroniser-for-wordfence-and-cloudflare"
PLUGIN_ENTRY="src/${PLUGIN_SLUG}.php"
RELEASE_ZIP="dist/${PLUGIN_SLUG}.zip"

MODE="${1:-}"
REQUESTED_VERSION="${2:-}"

TEMP_DIR=""
SVN_PREPARED=0
SVN_COMMITTED=0
VERSION=""

usage() {
	cat <<'EOF'
Usage:
  ./scripts/release-plugin.sh self-test
  ./scripts/release-plugin.sh validate
  ./scripts/release-plugin.sh publish X.Y.Z

Modes:
  self-test  Run every nonpublishing release gate while this script is uncommitted.
  validate   Run every nonpublishing release gate from a clean committed repository.
  publish    Run all gates, create the GitHub tag/release, and publish WordPress SVN.
EOF
}

stop_test_services() {
	sudo systemctl stop \
		docker.socket \
		docker.service \
		containerd.service >/dev/null 2>&1 || true
}

remove_unversioned_svn_files() {
	python3 - "$WPORG_SVN_WORKING_COPY" "$VERSION" <<'PYTHON'
from pathlib import Path
import shutil
import subprocess
import sys
import xml.etree.ElementTree as ET

working_copy = Path(sys.argv[1]).resolve()
version = sys.argv[2]

result = subprocess.run(
    ["svn", "status", "--xml", str(working_copy)],
    check=True,
    capture_output=True,
    text=True,
)

root = ET.fromstring(result.stdout)

for entry in root.findall(".//entry"):
    status = entry.find("wc-status")

    if status is None or status.get("item") != "unversioned":
        continue

    path = Path(entry.get("path", ""))

    if not path.is_absolute():
        path = (Path.cwd() / path).resolve()
    else:
        path = path.resolve()

    try:
        relative = path.relative_to(working_copy)
    except ValueError:
        continue

    allowed = (
        relative.parts[:1] == ("trunk",)
        or relative.parts[:2] == ("tags", version)
    )

    if not allowed:
        continue

    if path.is_dir():
        shutil.rmtree(path)
    elif path.exists() or path.is_symlink():
        path.unlink()
PYTHON
}

restore_svn_working_copy() {
	if [[ "$SVN_PREPARED" -ne 1 || "$SVN_COMMITTED" -eq 1 ]]; then
		return
	fi

	echo "Restoring the WordPress.org SVN working copy after failure." >&2

	svn revert --recursive \
		"$WPORG_SVN_WORKING_COPY/trunk" \
		"$WPORG_SVN_WORKING_COPY/tags" >/dev/null 2>&1 || true

	remove_unversioned_svn_files || true
}

cleanup() {
	result="$?"

	trap - EXIT INT TERM
	set +e

	if [[ "$result" -ne 0 ]]; then
		restore_svn_working_copy
	fi

	if [[ -n "$TEMP_DIR" && -d "$TEMP_DIR" ]]; then
		rm -rf "$TEMP_DIR"
	fi

	stop_test_services

	exit "$result"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

require_command() {
	command -v "$1" >/dev/null 2>&1 ||
		fail "Required command is unavailable: $1"
}

plugin_version() {
	sed -nE \
		's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' \
		"$PLUGIN_ENTRY" |
	head -n 1
}

readme_stable_tag() {
	sed -nE \
		's/^[[:space:]]*Stable tag:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' \
		readme.txt |
	head -n 1
}

validate_changed_files_for_self_test() {
	local unexpected=0
	local status_line
	local path

	while IFS= read -r status_line; do
		[[ -z "$status_line" ]] && continue

		path="${status_line:3}"

		if [[ "$path" != "scripts/release-plugin.sh" ]]; then
			echo "Unexpected repository change: $status_line" >&2
			unexpected=1
		fi
	done < <(git status --porcelain=v1 --untracked-files=all)

	[[ "$unexpected" -eq 0 ]] ||
		fail "Self-test permits only scripts/release-plugin.sh to be uncommitted."
}

verify_repository() {
	cd "$REPO_ROOT"

	[[ -d .git ]] || fail "Git repository is unavailable."
	[[ -f "$PLUGIN_ENTRY" ]] || fail "Plugin entry file is unavailable."
	[[ -f readme.txt ]] || fail "WordPress readme is unavailable."
	[[ -f Makefile ]] || fail "Makefile is unavailable."
	[[ -f "$RELEASE_CONFIG" ]] || fail "SVN release configuration is unavailable."

	[[ "$(git branch --show-current)" == "main" ]] ||
		fail "The current Git branch is not main."

	[[ "$(git remote get-url fork)" == "$EXPECTED_FORK" ]] ||
		fail "The fork remote is not the Linus-007 repository."

	if [[ "$MODE" == "self-test" ]]; then
		validate_changed_files_for_self_test
	else
		[[ -z "$(git status --porcelain=v1)" ]] ||
			fail "The Git working tree is not clean."
	fi

	git fetch --no-tags fork \
		'+refs/heads/main:refs/remotes/fork/main'

	[[ "$(git rev-parse HEAD)" == "$(git rev-parse refs/remotes/fork/main)" ]] ||
		fail "Local main does not match Linus-007 fork/main."
}

load_and_verify_svn_configuration() {
	# shellcheck disable=SC1090
	source "$RELEASE_CONFIG"

	for variable_name in \
		WPORG_SVN_USERNAME \
		WPORG_SVN_CONFIG_DIR \
		WPORG_SVN_WORKING_COPY \
		WPORG_SVN_URL
	do
		[[ -n "${!variable_name:-}" ]] ||
			fail "Missing SVN configuration: $variable_name"
	done

	[[ "$WPORG_SVN_URL" == "$EXPECTED_SVN_URL" ]] ||
		fail "SVN configuration targets an unexpected repository."

	[[ -d "$WPORG_SVN_WORKING_COPY/.svn" ]] ||
		fail "The WordPress.org SVN working copy is unavailable."

	[[ -z "$(svn status "$WPORG_SVN_WORKING_COPY")" ]] ||
		fail "The WordPress.org SVN working copy is not clean."

	svn info \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		"$WPORG_SVN_URL" >/dev/null

	for directory in trunk tags assets; do
		[[ -d "$WPORG_SVN_WORKING_COPY/$directory" ]] ||
			fail "SVN directory is missing: $directory"
	done
}

resolve_version() {
	local metadata_version
	local stable_tag

	metadata_version="$(plugin_version)"
	stable_tag="$(readme_stable_tag)"

	[[ "$metadata_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
		fail "Plugin metadata contains an invalid version."

	[[ "$stable_tag" == "$metadata_version" ]] ||
		fail "Plugin version and readme Stable tag do not match."

	if [[ "$MODE" == "publish" ]]; then
		[[ "$REQUESTED_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
			fail "Publish requires a semantic version in X.Y.Z format."

		[[ "$REQUESTED_VERSION" == "$metadata_version" ]] ||
			fail "Requested version does not match plugin metadata."
	fi

	VERSION="$metadata_version"

	printf "Resolved release version: %s\n" "$VERSION"
}

verify_release_does_not_exist() {
	if git show-ref --verify --quiet "refs/tags/v$VERSION"; then
		fail "Local Git tag v$VERSION already exists."
	fi

	if git ls-remote \
		--exit-code \
		fork \
		"refs/tags/v$VERSION" >/dev/null 2>&1
	then
		fail "GitHub tag v$VERSION already exists."
	fi

	if svn info \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		"$WPORG_SVN_URL/tags/$VERSION" >/dev/null 2>&1
	then
		fail "WordPress.org SVN tag $VERSION already exists."
	fi
}

verify_github_main_checks() {
	python3 - "$GITHUB_REPOSITORY" "$(git rev-parse HEAD)" <<'PYTHON'
import json
import sys
import urllib.error
import urllib.request

repository = sys.argv[1]
commit = sys.argv[2]

required = {
    "Application Security",
    "Continuous Integration",
    "WordPress Integration",
}

url = (
    f"https://api.github.com/repos/{repository}/actions/runs"
    f"?head_sha={commit}&per_page=30"
)

request = urllib.request.Request(
    url,
    headers={
        "Accept": "application/vnd.github+json",
        "User-Agent": "grey-rock-release-validation",
        "X-GitHub-Api-Version": "2022-11-28",
    },
)

try:
    with urllib.request.urlopen(request, timeout=30) as response:
        payload = json.load(response)
except (urllib.error.HTTPError, urllib.error.URLError) as error:
    raise SystemExit(f"GitHub workflow lookup failed: {error}")

latest = {}

for run in payload.get("workflow_runs", []):
    name = run.get("name")

    if name not in required:
        continue

    existing = latest.get(name)

    if (
        existing is None
        or run.get("run_number", 0) > existing.get("run_number", 0)
    ):
        latest[name] = run

failed = False

for name in sorted(required):
    run = latest.get(name)

    if run is None:
        print(f"{name}: NOT FOUND")
        failed = True
        continue

    status = run.get("status", "unknown")
    conclusion = run.get("conclusion") or "pending"

    print(f"{name}: {status} / {conclusion}")

    if status != "completed" or conclusion != "success":
        failed = True

if failed:
    raise SystemExit("Required GitHub checks have not all passed.")

print("PASS: Required GitHub checks passed.")
PYTHON
}

run_release_gates() {
	echo
	echo "===== LOCAL APPLICATION SECURITY ====="
	./scripts/security-check.sh

	echo
	echo "===== BUILD RELEASE ZIP ====="
	make release VERSION="$VERSION"

	[[ -f "$RELEASE_ZIP" ]] ||
		fail "Release ZIP was not created."

	echo
	echo "===== DISPOSABLE WORDPRESS INTEGRATION ====="
	./scripts/integration-test.sh

	echo
	echo "===== LIVE CLOUDFLARE INTEGRATION ====="
	sudo ./scripts/cloudflare-integration-test.sh

	echo
	echo "===== REBUILD FINAL RELEASE ZIP ====="
	make release VERSION="$VERSION"

	sha256sum "$RELEASE_ZIP" |
		tee "${RELEASE_ZIP}.sha256"

	if [[ "$MODE" == "self-test" ]]; then
		validate_changed_files_for_self_test
	else
		[[ -z "$(git status --porcelain=v1)" ]] ||
			fail "Tests changed the Git working tree."
	fi

	echo "PASS: All local release gates completed."
}

wait_for_github_release() {
	python3 - \
		"$GITHUB_REPOSITORY" \
		"$(git rev-parse HEAD)" \
		"$VERSION" \
		"$PLUGIN_SLUG" <<'PYTHON'
import json
import sys
import time
import urllib.error
import urllib.request

repository, commit, version, plugin_slug = sys.argv[1:]

headers = {
    "Accept": "application/vnd.github+json",
    "User-Agent": "grey-rock-release-verification",
    "X-GitHub-Api-Version": "2022-11-28",
}

runs_url = (
    f"https://api.github.com/repos/{repository}/actions/runs"
    f"?head_sha={commit}&per_page=50"
)

release_url = (
    f"https://api.github.com/repos/{repository}/releases/tags/v{version}"
)

workflow = None

for attempt in range(1, 41):
    request = urllib.request.Request(runs_url, headers=headers)

    with urllib.request.urlopen(request, timeout=30) as response:
        payload = json.load(response)

    candidates = [
        run
        for run in payload.get("workflow_runs", [])
        if run.get("name") == "Build and Release Plugin"
    ]

    if candidates:
        workflow = max(
            candidates,
            key=lambda run: run.get("run_number", 0),
        )

        status = workflow.get("status")
        conclusion = workflow.get("conclusion")

        print(
            f"GitHub release workflow: "
            f"{status} / {conclusion or 'pending'}"
        )

        if status == "completed":
            if conclusion != "success":
                raise SystemExit(
                    "GitHub release workflow did not succeed."
                )
            break

    if attempt == 40:
        raise SystemExit("GitHub release workflow timed out.")

    time.sleep(15)

release = None

for attempt in range(1, 21):
    request = urllib.request.Request(release_url, headers=headers)

    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            release = json.load(response)
    except urllib.error.HTTPError as error:
        if error.code != 404:
            raise
    else:
        break

    if attempt == 20:
        raise SystemExit("GitHub release was not created in time.")

    time.sleep(10)

asset_names = {
    asset.get("name")
    for asset in release.get("assets", [])
}

required_assets = {
    f"{plugin_slug}.zip",
    f"{plugin_slug}.zip.sha256",
}

missing = required_assets - asset_names

if missing:
    raise SystemExit(
        "GitHub release is missing assets: "
        + ", ".join(sorted(missing))
    )

print(f"PASS: GitHub release v{version} and assets are available.")
print(release.get("html_url", ""))
PYTHON
}

schedule_svn_changes() {
	python3 - \
		"$WPORG_SVN_WORKING_COPY/trunk" \
		"$WPORG_SVN_WORKING_COPY/tags/$VERSION" <<'PYTHON'
from pathlib import Path
import subprocess
import sys
import xml.etree.ElementTree as ET

targets = [Path(value).resolve() for value in sys.argv[1:]]

for target in targets:
    result = subprocess.run(
        ["svn", "status", "--xml", str(target)],
        check=True,
        capture_output=True,
        text=True,
    )

    root = ET.fromstring(result.stdout)

    missing = []
    unversioned = []
    invalid = []

    for entry in root.findall(".//entry"):
        status = entry.find("wc-status")

        if status is None:
            continue

        item = status.get("item")
        path = entry.get("path", "")

        if item == "missing":
            missing.append(path)
        elif item == "unversioned":
            unversioned.append(path)
        elif item in {"conflicted", "obstructed", "incomplete"}:
            invalid.append((path, item))

    if invalid:
        details = ", ".join(
            f"{path} ({item})"
            for path, item in invalid
        )
        raise SystemExit(f"Invalid SVN state: {details}")

    for path in missing:
        subprocess.run(
            ["svn", "rm", "--force", path],
            check=True,
        )

    for path in unversioned:
        subprocess.run(
            ["svn", "add", "--parents", "--force", path],
            check=True,
        )
PYTHON
}

validate_svn_change_scope() {
	python3 - "$WPORG_SVN_WORKING_COPY" "$VERSION" <<'PYTHON'
from pathlib import Path
import subprocess
import sys
import xml.etree.ElementTree as ET

working_copy = Path(sys.argv[1]).resolve()
version = sys.argv[2]

result = subprocess.run(
    ["svn", "status", "--xml", str(working_copy)],
    check=True,
    capture_output=True,
    text=True,
)

root = ET.fromstring(result.stdout)
seen = False

for entry in root.findall(".//entry"):
    status = entry.find("wc-status")

    if status is None or status.get("item") == "normal":
        continue

    seen = True
    path = Path(entry.get("path", ""))

    if not path.is_absolute():
        path = (Path.cwd() / path).resolve()
    else:
        path = path.resolve()

    try:
        relative = path.relative_to(working_copy)
    except ValueError:
        raise SystemExit(f"SVN path is outside the working copy: {path}")

    allowed = (
        relative.parts[:1] == ("trunk",)
        or relative.parts[:2] == ("tags", version)
    )

    if not allowed:
        raise SystemExit(
            f"Unexpected SVN change outside release scope: {relative}"
        )

if not seen:
    raise SystemExit("No SVN release changes were detected.")

print("PASS: SVN changes are limited to trunk and the new version tag.")
PYTHON
}

sync_release_directory() {
	local source_directory="$1"
	local destination_directory="$2"

	python3 - "$source_directory" "$destination_directory" <<'PYTHON'
from pathlib import Path
import shutil
import sys

source = Path(sys.argv[1]).resolve()
destination = Path(sys.argv[2]).resolve()

if not source.is_dir():
    raise SystemExit(f"Release source is unavailable: {source}")

destination.mkdir(parents=True, exist_ok=True)

for child in destination.iterdir():
    if child.name == ".svn":
        continue

    if child.is_dir() and not child.is_symlink():
        shutil.rmtree(child)
    else:
        child.unlink()

for child in source.iterdir():
    target = destination / child.name

    if child.is_dir() and not child.is_symlink():
        shutil.copytree(child, target)
    else:
        shutil.copy2(child, target)
PYTHON
}

publish_wordpress_svn() {
	echo
	echo "===== UPDATE WORDPRESS.ORG SVN ====="

	svn update \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		"$WPORG_SVN_WORKING_COPY"

	[[ -z "$(svn status "$WPORG_SVN_WORKING_COPY")" ]] ||
		fail "SVN working copy became dirty before release preparation."

	if svn info \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		"$WPORG_SVN_URL/tags/$VERSION" >/dev/null 2>&1
	then
		fail "WordPress.org SVN tag $VERSION now exists."
	fi

	TEMP_DIR="$(mktemp -d)"
	local extracted_root="$TEMP_DIR/extracted"
	mkdir -p "$extracted_root"

	python3 - "$RELEASE_ZIP" "$extracted_root" "$PLUGIN_SLUG" <<'PYTHON'
from pathlib import Path
import sys
import zipfile

archive_path = Path(sys.argv[1]).resolve()
output = Path(sys.argv[2]).resolve()
slug = sys.argv[3]

with zipfile.ZipFile(archive_path) as archive:
    archive.extractall(output)

plugin_root = output / slug

if not plugin_root.is_dir():
    raise SystemExit("Release ZIP does not contain the expected plugin root.")

print(plugin_root)
PYTHON

	local release_source="$extracted_root/$PLUGIN_SLUG"
	local svn_trunk="$WPORG_SVN_WORKING_COPY/trunk"
	local svn_tag="$WPORG_SVN_WORKING_COPY/tags/$VERSION"

	[[ ! -e "$svn_tag" ]] ||
		fail "Local SVN tag directory already exists: $svn_tag"

	svn copy "$svn_trunk" "$svn_tag"
	SVN_PREPARED=1

	sync_release_directory "$release_source" "$svn_trunk"
	sync_release_directory "$release_source" "$svn_tag"

	schedule_svn_changes
	validate_svn_change_scope

	for metadata_file in \
		"$svn_trunk/$PLUGIN_SLUG.php" \
		"$svn_tag/$PLUGIN_SLUG.php"
	do
		local detected_version

		detected_version="$(
			sed -nE \
				's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' \
				"$metadata_file" |
			head -n 1
		)"

		[[ "$detected_version" == "$VERSION" ]] ||
			fail "SVN plugin metadata does not match $VERSION."
	done

	for readme_file in \
		"$svn_trunk/readme.txt" \
		"$svn_tag/readme.txt"
	do
		local detected_stable_tag

		detected_stable_tag="$(
			sed -nE \
				's/^[[:space:]]*Stable tag:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' \
				"$readme_file" |
			head -n 1
		)"

		[[ "$detected_stable_tag" == "$VERSION" ]] ||
			fail "SVN readme Stable tag does not match $VERSION."
	done

	echo
	echo "===== WORDPRESS.ORG SVN CHANGE SET ====="
	svn status "$WPORG_SVN_WORKING_COPY"

	echo
	echo "===== COMMIT WORDPRESS.ORG RELEASE ====="

	svn commit \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		--message "Release $VERSION" \
		"$svn_trunk" \
		"$svn_tag"

	SVN_COMMITTED=1

	svn update \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		"$WPORG_SVN_WORKING_COPY"

	[[ -z "$(svn status "$WPORG_SVN_WORKING_COPY")" ]] ||
		fail "SVN working copy is not clean after publication."

	svn info \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		"$WPORG_SVN_URL/tags/$VERSION" >/dev/null

	echo "PASS: WordPress.org SVN release $VERSION was published."
}

publish_release() {
	verify_release_does_not_exist

	echo
	echo "All validation gates passed."
	echo
	printf "Type PUBLISH %s to continue: " "$VERSION"

	read -r confirmation < /dev/tty

	[[ "$confirmation" == "PUBLISH $VERSION" ]] ||
		fail "Publication confirmation did not match."

	echo
	echo "===== CREATE AND PUSH GITHUB TAG ====="

	make tag-release \
		VERSION="$VERSION" \
		GIT_REMOTE=fork

	echo
	echo "===== VERIFY GITHUB RELEASE ====="

	wait_for_github_release

	publish_wordpress_svn
}

case "$MODE" in
	self-test|validate)
		if [[ -n "$REQUESTED_VERSION" ]]; then
			usage
			exit 2
		fi
		;;
	publish)
		if [[ -z "$REQUESTED_VERSION" ]]; then
			usage
			exit 2
		fi
		;;
	*)
		usage
		exit 2
		;;
esac

for command_name in \
	bash \
	curl \
	git \
	make \
	php \
	python3 \
	sha256sum \
	sudo \
	svn
do
	require_command "$command_name"
done

cd "$REPO_ROOT"

verify_repository
load_and_verify_svn_configuration
resolve_version

echo
echo "===== RELEASE IDENTITY ====="
printf "Mode:             %s\n" "$MODE"
printf "Version:          %s\n" "$VERSION"
printf "Git commit:       %s\n" "$(git rev-parse HEAD)"
printf "Git destination:  %s\n" "$(git remote get-url fork)"
printf "SVN destination:  %s\n" "$WPORG_SVN_URL"

echo
echo "===== GITHUB MAIN CHECKS ====="
verify_github_main_checks

if [[ "$MODE" == "publish" ]]; then
	verify_release_does_not_exist
fi

run_release_gates

if [[ "$MODE" == "publish" ]]; then
	publish_release
else
	echo
	echo "RELEASE VALIDATION RESULT: PASS"
	echo "No Git tag, GitHub release or WordPress.org SVN change was made."
fi
