#!/bin/bash
# StopFailure and SessionEnd hook. Writes a git snapshot so the next session
# can reconcile PROGRESS.md. Add .claude/checkpoint.txt to .gitignore.
cd "$CLAUDE_PROJECT_DIR" || exit 0
{
  echo "checkpoint: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "branch: $(git rev-parse --abbrev-ref HEAD)"
  echo "--- status"; git status --short
  echo "--- recent commits"; git log --oneline -10
  echo "--- branches not merged into HEAD"; git branch -a --no-merged
  echo "--- worktrees"; git worktree list
} > .claude/checkpoint.txt 2>&1
exit 0
