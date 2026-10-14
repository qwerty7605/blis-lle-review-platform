#!/bin/bash
# Deploy main to this server as soon as GitHub has a commit it has not run yet.
#
# Cron runs this every minute from ~/auto-deploy.sh (a copy, so a pull never
# rewrites the script while it runs). When main has not moved it only fetches.
# When it has, it waits for any exam in progress to end, then runs ~/deploy.sh
# (backup, pull, rebuild, migrate) and logs to ~/deploy-<commit>.log.
#
# Install or update on the server:
#   cp ~/BLIS-beta/scripts/auto-deploy.sh ~/auto-deploy.sh && chmod +x ~/auto-deploy.sh
#   crontab: * * * * * $HOME/auto-deploy.sh
#
# A commit whose deploy failed is not tried again (each try rebuilds on a small
# machine); the next commit to main is. To retry the same one:
#   rm ~/.failed-commit

set -uo pipefail

LOG="$HOME/auto-deploy.log"
DEPLOYED="$HOME/.deployed-commit"
FAILED="$HOME/.failed-commit"
WAITING="$HOME/.waiting-commit"
COMPOSE="docker compose -f docker-compose.prod.yml"

log() { echo "$(date -u '+%Y-%m-%d %H:%M:%S UTC') $*" >> "$LOG"; }

cd "$HOME/BLIS-beta" || exit 1

# One run at a time; a deploy takes a few minutes and cron does not wait.
exec 9> /tmp/blis-auto-deploy.lock
flock -n 9 || exit 0

git fetch -q origin main 2> /dev/null || { log "Could not reach GitHub"; exit 1; }

want=$(git rev-parse origin/main)
have=$(cat "$DEPLOYED" 2> /dev/null || git rev-parse HEAD)

[ "$want" = "$have" ] && exit 0
[ "$want" = "$(cat "$FAILED" 2> /dev/null)" ] && exit 0

short=${want:0:7}

# Restarting PHP drops requests for about ten seconds, which a student
# submitting would notice. Wait until no attempt is still running.
running=$($COMPOSE exec -T -e XDG_CONFIG_HOME=/tmp php php artisan tinker --execute='
echo DB::table("exam_attempts")->whereNull("submitted_at")->where(function ($q) {
    $q->where("expires_at", ">", now())
      ->orWhere(fn ($q) => $q->whereNull("expires_at")->where("started_at", ">", now()->subHours(6)));
})->count();' < /dev/null 2> /dev/null | tail -n 1)

if [[ "$running" =~ ^[0-9]+$ ]] && [ "$running" -gt 0 ]; then
    if [ "$(cat "$WAITING" 2> /dev/null)" != "$want" ]; then
        log "$short waiting: $running exam attempt(s) in progress"
        echo "$want" > "$WAITING"
    fi
    exit 0
fi

log "$short deploying"
if "$HOME/deploy.sh" > "$HOME/deploy-$short.log" 2>&1 < /dev/null; then
    git rev-parse HEAD > "$DEPLOYED"
    rm -f "$FAILED" "$WAITING"
    log "$(git rev-parse --short HEAD) live"
else
    echo "$want" > "$FAILED"
    rm -f "$WAITING"
    log "$short FAILED, see ~/deploy-$short.log"
fi
