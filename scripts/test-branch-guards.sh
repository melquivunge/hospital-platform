#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
test_dir=$(mktemp -d)
trap 'rm -rf "$test_dir"' EXIT
cd "$test_dir"
git init -q -b main
if sh "$root/.githooks/pre-commit" 2>/dev/null; then
 echo 'Falha: commit em main permitido'; exit 1
fi
git symbolic-ref HEAD refs/heads/feat/test-guard
sh "$root/.githooks/pre-commit"
if printf '%s\n' 'refs/heads/feat/test a refs/heads/main b' | sh "$root/.githooks/pre-push" 2>/dev/null; then
 echo 'Falha: push na main permitido'; exit 1
fi
printf '%s\n' 'refs/heads/feat/test a refs/heads/feat/test b' | sh "$root/.githooks/pre-push"
echo 'Guards: main bloqueada; branch de tarefa permitida.'
