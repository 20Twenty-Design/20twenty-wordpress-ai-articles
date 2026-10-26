#!/usr/bin/env bash
# Builds build/twenty-ai-articles.zip (installable via Plugins → Add New → Upload Plugin).
set -euo pipefail
cd "$(dirname "$0")/.."

rm -rf build
mkdir -p build/twenty-ai-articles
rsync -a --exclude-from=.distignore ./ build/twenty-ai-articles/
(cd build && zip -rq twenty-ai-articles.zip twenty-ai-articles)
rm -rf build/twenty-ai-articles

VERSION=$(grep -m1 -E '^\s*\*\s*Version:' twenty-ai-articles.php | awk '{print $NF}')
echo "Built build/twenty-ai-articles.zip (version $VERSION)"
