#!/bin/sh
# Builds build/knowledge_site.zip for Azure (code only: no .env, no uploads, no docs, no git).
set -e
cd "$(dirname "$0")/.."
mkdir -p build
rm -f build/knowledge_site.zip
zip -r -q build/knowledge_site.zip . \
    -x ".git/*" ".env" "build/*" "docs/*" "tests/*" "storage/*/*" "*.zip" ".github/*"
zip -q build/knowledge_site.zip storage/uploads/.gitkeep storage/photos/.gitkeep storage/videos/.gitkeep
echo "Created build/knowledge_site.zip"
