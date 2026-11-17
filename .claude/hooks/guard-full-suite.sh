#!/bin/bash
# PreToolUse hook (matcher: Bash). Blocks bare full-suite test runs.
# Allowed: targeted runs (any arguments), or a full run prefixed FULL_SUITE_OK=1,
# which the integrator uses only during final integration.
# Edit FULL_SUITE to match this repo's full-suite command(s).
FULL_SUITE='^(composer (test|check)|(\./)?vendor/bin/phpunit|phpunit)[[:space:]]*$'

cmd=$(jq -r '.tool_input.command // ""')
# Check each command in a chain like "cd app && pytest".
while IFS= read -r part; do
  part="${part#"${part%%[![:space:]]*}"}"
  if [[ "$part" =~ $FULL_SUITE ]]; then
    echo "Blocked: the full test suite is reserved for final integration (see CLAUDE.md testing policy). Run targeted tests instead. During final integration, the integrator prefixes the command with FULL_SUITE_OK=1." >&2
    exit 2
  fi
done < <(printf '%s\n' "$cmd" | sed -E 's/(&&|\|\||;)/\n/g')
exit 0
