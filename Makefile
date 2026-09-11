.PHONY: test release check-version

export VERSION

# Usage:
#   make test                       run Pint checks and Pest
#   make release VERSION=vX.Y.Z     test and create a GitHub release

test:
	composer lint:check
	composer test

release: check-version
	@[ "$$(git branch --show-current)" = "main" ] || { echo "Releases are cut from main."; exit 1; }
	@[ -z "$$(git status --porcelain)" ] || { echo "Working tree is dirty; commit your changes first."; exit 1; }
	git fetch origin main --tags
	@[ "$$(git rev-parse HEAD)" = "$$(git rev-parse FETCH_HEAD)" ] || { echo "Local main must match origin/main before releasing."; exit 1; }
	@! git show-ref --verify --quiet "refs/tags/$$VERSION" || { echo "Tag $$VERSION already exists."; exit 1; }
	composer install --no-interaction
	$(MAKE) test
	gh release create "$$VERSION" --target "$$(git rev-parse HEAD)" --title "$$VERSION" --generate-notes

check-version:
	@printf '%s\n' "$$VERSION" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$$' || { echo "VERSION is required in the form vX.Y.Z, e.g. make release VERSION=v0.1.0"; exit 1; }
