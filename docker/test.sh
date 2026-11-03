#!/bin/sh
# Runs the tests of this checkout in docker/docker-compose.testing.yml, or the command given, in the same container:
#   sh docker/test.sh
#   sh docker/test.sh composer test -- --filter=InvoiceTest
# Linux, macOS and Git Bash on Windows. The stack is removed after every run; the exit code is the one of the tests.
#
# SM_TEST_PROJECT: compose project name, default <checkout folder>-<its parent folder>, one per checkout.
# SM_TOOL_DIR: the sm-tool clone with the shared test services (x-shared-services of the stack), default the one smt
# on the PATH points to.
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"
file=docker/docker-compose.testing.yml
# Git Bash would turn arguments that look like paths into Windows paths before docker sees them.
export MSYS_NO_PATHCONV=1

project=${SM_TEST_PROJECT:-$(basename "$root")-$(basename "$(dirname "$root")")}
project=$(printf '%s' "$project" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-')

# The container writes into the checkout as UID:GID; shells do not export them. Docker Desktop maps owners itself.
# bash keeps UID read-only but already set, dash leaves it unset.
if [ "$(uname -s)" = Linux ]; then
  [ -n "${UID:-}" ] || UID=$(id -u)
  GID=$(id -g)
  export UID GID
fi

value() {
  sed -n "s/^ *$1: *\"\{0,1\}\([^\" ]*\)\"\{0,1\} *\$/\1/p" "$file"
}

image=$(value image | grep '^sm-testing-' | head -n 1)
if [ -n "$image" ] && ! docker image inspect "$image" >/dev/null 2>&1; then
  docker build -f "docker/$(value dockerfile | head -n 1)" --build-arg "VER=$(value VER | head -n 1)" -t "$image" docker
fi

shared=$(sed -n 's/^x-shared-services: *\[\(.*\)\] *$/\1/p' "$file" | tr ',' ' ')
if [ -n "$(printf '%s' "$shared" | tr -d ' ')" ]; then
  if [ -z "${SM_TOOL_DIR:-}" ] && smt=$(command -v smt 2>/dev/null); then
    while [ -L "$smt" ]; do
      link=$(readlink "$smt")
      case $link in /*) smt=$link ;; *) smt=$(dirname "$smt")/$link ;; esac
    done
    SM_TOOL_DIR=$(cd "$(dirname "$smt")" && pwd)
  fi
  if [ ! -f "${SM_TOOL_DIR:-}/docker-compose.testing.yml" ]; then
    echo "test.sh: the stack needs the shared test services of sm-tool ($shared): set SM_TOOL_DIR to your sm-tool clone" >&2
    exit 5
  fi
  # shellcheck disable=SC2086
  docker compose -f "$SM_TOOL_DIR/docker-compose.testing.yml" -p sm-testing up -d --wait $shared
fi

down() {
  docker compose -f "$file" -p "$project" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap 'down; exit 130' INT TERM
down
status=0
docker compose -f "$file" -p "$project" run --rm -T tests "$@" || status=$?
down
exit "$status"
