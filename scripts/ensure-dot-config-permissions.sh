#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
config_file="$repo_root/org/dot/config.php"

if [ ! -f "$config_file" ]; then
  echo "Missing $config_file. Provision the server-only SAFER configuration before deploying." >&2
  exit 1
fi

# Preserve the owner and credentials. Apache only needs group read access.
if [ "$(stat -c '%G:%a' "$config_file")" != 'www-data:640' ]; then
  if ! chgrp www-data "$config_file" || ! chmod 640 "$config_file"; then
    echo "Unable to set DOT configuration permissions. Run as a server administrator:" >&2
    printf 'chgrp www-data %q\nchmod 640 %q\n' "$config_file" "$config_file" >&2
    exit 1
  fi
fi

echo 'DOT configuration permissions verified: www-data group, mode 640.'
