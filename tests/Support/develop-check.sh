#!/usr/bin/env bash
# Run the ivpl/xprmt PHPUnit suite against the application code of upstream develop.
#
# ivpl/xprmt = upstream develop + test harness + fork-only fixes, so a green suite there does not prove develop
# itself. This builds a sibling worktree whose application/ is exactly upstream/develop (plus, optionally, the
# testing-only PayPal/Stripe HTTP seam) and runs the same tests against it.
#
#   tests/Support/develop-check.sh [--with-seam] [--no-run] [-- <phpunit args>]
#
# Environment (defaults match the ivpldock stack used for this project):
#   BASE_REPO   path of the ivpl/xprmt checkout            (default: current repo root)
#   TARGET      worktree directory                          (default: $BASE_REPO/../ivpl-develop-check)
#   TESTS_REF   ref providing tests/, bootstrap/, public/   (default: ivpl/xprmt)
#   DEVELOP_REF ref providing application/                  (default: upstream/develop)
#   SEAM_COMMIT commit whose PaypalLib.php/Stripe.php carry the seam only (default: 8e4f2c6f)
#   CONTAINER   docker workspace container                  (default: ivpldock-workspace-1)
#   CONTAINER_ROOT path where ../ is mounted in the container (default: /var/www/projects/invoiceplane)
set -euo pipefail

with_seam=0; run=1
while [[ $# -gt 0 ]]; do
  case "$1" in
    --with-seam) with_seam=1 ;;
    --no-run) run=0 ;;
    --) shift; break ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
  shift
done

BASE_REPO=${BASE_REPO:-$(git rev-parse --show-toplevel)}
TARGET=${TARGET:-$BASE_REPO/../ivpl-develop-check}
TESTS_REF=${TESTS_REF:-ivpl/xprmt}
DEVELOP_REF=${DEVELOP_REF:-upstream/develop}
SEAM_COMMIT=${SEAM_COMMIT:-8e4f2c6f}
CONTAINER=${CONTAINER:-ivpldock-workspace-1}
CONTAINER_ROOT=${CONTAINER_ROOT:-/var/www/projects/invoiceplane}

git -C "$BASE_REPO" fetch -q upstream develop

# 1. worktree at the tests ref (tests/, bootstrap/, public/, resources/, harness config)
if [[ ! -d $TARGET ]]; then
  git -C "$BASE_REPO" worktree add --detach "$TARGET" "$TESTS_REF"
fi

# 2. application/ exactly as in develop
git -C "$TARGET" checkout "$DEVELOP_REF" -- application

# 3. optional testing-only seam (no behaviour change outside ENVIRONMENT=testing / CI_TESTING)
if [[ $with_seam -eq 1 ]]; then
  git -C "$BASE_REPO" show "$SEAM_COMMIT:application/libraries/gateways/PaypalLib.php" > "$TARGET/application/libraries/gateways/PaypalLib.php"
  git -C "$BASE_REPO" show "$SEAM_COMMIT:application/modules/guest/controllers/gateways/Stripe.php" > "$TARGET/application/modules/guest/controllers/gateways/Stripe.php"
fi

# 4. untracked, ignored runtime files. vendor MUST be a real copy: with a symlink composer resolves the original
#    checkout and the request subprocesses run the fork's code, which makes the whole run meaningless.
cp "$BASE_REPO/ipconfig.php" "$TARGET/ipconfig.php"
if [[ ! -d $TARGET/vendor || -L $TARGET/vendor ]]; then
  rm -f "$TARGET/vendor"
  cp -r "$BASE_REPO/vendor" "$TARGET/vendor"
fi

[[ $run -eq 1 ]] || exit 0

# 5. run through the project's docker stack (same command as the normal suite, other directory)
dir=$CONTAINER_ROOT/$(basename "$TARGET")
docker exec -e XDEBUG_MODE=off --user=ivpldock "$CONTAINER" bash -lc \
  "cd $dir && env -u DB_HOSTNAME -u DB_PORT -u DB_DATABASE -u DB_USERNAME -u DB_PASSWORD vendor/bin/phpunit $*"
