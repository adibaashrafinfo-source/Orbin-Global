#!/usr/bin/env bash
# Builds release/orbin-pos-release-<version>.zip (DEPLOY_CPANEL.md §5):
#   backend code with vendor/ (no dev dependencies, optimized autoload), the built React app inside public/,
#   .htaccess files, .env.example and install notes. Never contains .env, tests, node_modules or storage contents.
set -euo pipefail

VERSION="${1:-$(date +%Y.%m.%d)}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/release"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
APP="$STAGE/orbin-pos"
mkdir -p "$APP" "$OUT"

echo "==> frontend build"
(cd "$ROOT/frontend" && npm ci --no-audit --no-fund && npm run build)

echo "==> backend copy"
rsync -a \
  --exclude '.env' --exclude '.env.*' --exclude 'vendor' --exclude 'tests' --exclude 'node_modules' \
  --exclude 'storage/logs/*' --exclude 'storage/framework/cache/data/*' --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' --exclude 'storage/framework/testing' --exclude 'storage/app/private/*' \
  --exclude 'bootstrap/cache/*.php' --exclude 'public/uploads/*' --exclude 'public/index.html' --exclude 'public/spa' \
  --exclude '.phpunit.result.cache' --exclude '.phpunit.cache' --exclude 'phpunit.xml' --exclude '.github' \
  --exclude '.styleci.yml' --exclude '.editorconfig' --exclude '.gitattributes' --exclude 'public/.htaccess.cpanel' \
  "$ROOT/backend/" "$APP/"
cp "$ROOT/backend/.env.example" "$APP/.env.example"

echo "==> composer (no-dev, optimized)"
(cd "$APP" && cp "$ROOT/backend/composer.json" "$ROOT/backend/composer.lock" . \
  && composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative --no-interaction --no-scripts)

echo "==> prune vendor (no VCS metadata, tests or docs in the release)"
# Composer installs from source when GitHub zipballs are unreachable; that leaves .git folders behind. Strip them either way.
find "$APP/vendor" -type d \( -name .git -o -name .github -o -name tests -o -name Tests -o -name docs \) -prune -exec rm -rf {} +
find "$APP/vendor" -type f \( -iname 'README*' -o -iname 'CHANGELOG*' -o -iname 'UPGRADE*' -o -iname 'CONTRIBUTING*' -o -name 'phpunit*.xml*' -o -name '.gitattributes' -o -name '.gitignore' -o -name '.editorconfig' -o -name '.php-cs-fixer*' \) -delete

echo "==> frontend into public/"
mkdir -p "$APP/public/uploads" "$APP/storage/logs" "$APP/storage/app/private" "$APP/storage/framework/"{cache/data,sessions,views} "$APP/bootstrap/cache"
cp -R "$ROOT/frontend/dist/spa" "$APP/public/spa"
cp "$ROOT/frontend/dist/index.html" "$APP/public/index.html"

echo "==> .htaccess files"
cp "$ROOT/deploy/htaccess/public.htaccess" "$APP/public/.htaccess"
cp "$ROOT/deploy/htaccess/uploads.htaccess" "$APP/public/uploads/.htaccess"
cp "$ROOT/deploy/htaccess/root.htaccess" "$APP/.htaccess"
touch "$APP/storage/logs/.gitignore" "$APP/storage/app/private/.gitkeep"

echo "==> install notes + version"
cp "$ROOT/DEPLOY_CPANEL.md" "$APP/INSTALL.md"
cp "$ROOT/deploy/cron.example" "$APP/cron.example"
echo "$VERSION" > "$APP/VERSION"

# Safety checks: nothing secret or dev-only may ship.
for forbidden in .env tests node_modules; do
  if find "$APP" -maxdepth 2 -name "$forbidden" | grep -q .; then echo "FORBIDDEN in release: $forbidden"; exit 1; fi
done
if find "$APP" -name .git | grep -q .; then echo "FORBIDDEN in release: .git folders"; exit 1; fi
if find "$APP" -maxdepth 3 -name '*.sql' -o -maxdepth 3 -name '*.log' | grep -q .; then echo "FORBIDDEN in release: sql/log files"; exit 1; fi
find "$APP" -type d -exec chmod 755 {} \; && find "$APP" -type f -exec chmod 644 {} \;
chmod 755 "$APP/artisan"

ZIP="$OUT/orbin-pos-release-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr "$ZIP" orbin-pos)
echo "==> $ZIP ($(du -h "$ZIP" | cut -f1))"
