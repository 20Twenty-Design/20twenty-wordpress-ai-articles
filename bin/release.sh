#!/usr/bin/env bash
# Usage: bin/release.sh 1.2.0
# Bumps the version everywhere, commits and creates the tag v1.2.0. Push with: git push origin main --follow-tags
set -euo pipefail

VERSION="${1:-}"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Usage: bin/release.sh X.Y.Z" >&2
	exit 1
fi

cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain)" ]; then
	echo "Working tree is not clean; commit or stash first." >&2
	exit 1
fi
if git rev-parse "v$VERSION" >/dev/null 2>&1; then
	echo "Tag v$VERSION already exists." >&2
	exit 1
fi

export VERSION
perl -pi -e 's/^( \* Version:\s+).*/${1}$ENV{VERSION}/' twenty-ai-articles.php
perl -pi -e "s/define\( 'TAI_VERSION', '[^']*' \)/define( 'TAI_VERSION', '\$ENV{VERSION}' )/" twenty-ai-articles.php
perl -pi -e 's/^Stable tag:.*/Stable tag: $ENV{VERSION}/' readme.txt
if ! grep -q "^= $VERSION =" readme.txt; then
	perl -0pi -e 's/^== Changelog ==\n/== Changelog ==\n\n= $ENV{VERSION} =\n* See GitHub release notes.\n/m' readme.txt
fi

for f in twenty-ai-articles.php includes/*.php; do php -l "$f" >/dev/null; done

git add -A
git commit -m "Release $VERSION"
git tag -a "v$VERSION" -m "Version $VERSION"
echo "Tagged v$VERSION. Publish with: git push origin main --follow-tags"
