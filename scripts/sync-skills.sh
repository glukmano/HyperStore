#!/usr/bin/env bash
# scripts/sync-skills.sh
# Synchronizes and verifies equivalence between Antigravity (.agents/skills/) and Claude Code (.claude/skills/)

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AGENTS_DIR="${REPO_ROOT}/.agents/skills"
CLAUDE_DIR="${REPO_ROOT}/.claude/skills"

MODE="${1:---check}"

echo "=== Hyper Commerce Platform — Agent Skills Sync Tool ==="
echo "Mode: ${MODE}"
echo "Antigravity path: ${AGENTS_DIR}"
echo "Claude Code path: ${CLAUDE_DIR}"
echo "--------------------------------------------------------"

mkdir -p "${AGENTS_DIR}"
mkdir -p "${CLAUDE_DIR}"

if [ "${MODE}" = "--sync-to-claude" ]; then
    echo "Syncing from .agents/skills/ to .claude/skills/..."
    find "${AGENTS_DIR}" -name "._*" -delete || true
    rsync -av --delete --exclude='._*' "${AGENTS_DIR}/" "${CLAUDE_DIR}/"
    find "${CLAUDE_DIR}" -name "._*" -delete || true
    echo "✓ Synced to .claude/skills/ successfully."
    exit 0
fi

if [ "${MODE}" = "--sync-to-agents" ]; then
    echo "Syncing from .claude/skills/ to .agents/skills/..."
    find "${CLAUDE_DIR}" -name "._*" -delete || true
    rsync -av --delete --exclude='._*' "${CLAUDE_DIR}/" "${AGENTS_DIR}/"
    find "${AGENTS_DIR}" -name "._*" -delete || true
    echo "✓ Synced to .agents/skills/ successfully."
    exit 0
fi

if [ "${MODE}" = "--check" ]; then
    echo "Checking skills equivalence..."
    find "${AGENTS_DIR}" -name "._*" -delete || true
    find "${CLAUDE_DIR}" -name "._*" -delete || true
    DIFF_OUTPUT=$(diff -r -u -x '._*' "${AGENTS_DIR}" "${CLAUDE_DIR}" 2>&1 || true)
    if [ -n "${DIFF_OUTPUT}" ]; then
        echo "✗ Discrepancies detected between .agents/skills/ and .claude/skills/:"
        echo "${DIFF_OUTPUT}"
        exit 1
    else
        SKILLS_COUNT=$(find "${AGENTS_DIR}" -mindepth 1 -maxdepth 1 -type d -not -name ".*" | wc -l | tr -d ' ')
        echo "✓ All ${SKILLS_COUNT} skills are 100% equivalent between Antigravity and Claude Code."
        exit 0
    fi
fi

echo "Usage: $0 [--check | --sync-to-claude | --sync-to-agents]"
exit 1
