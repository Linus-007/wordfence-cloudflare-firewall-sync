PLUGIN_SLUG := wordfence-cloudflare-firewall-sync
PLUGIN_ENTRY := src/index.php
RELEASE_DIR := dist
RELEASE_ZIP := $(RELEASE_DIR)/greyrock-wordfence-cloudflare-synchroniser.zip
BUILD_SCRIPT := scripts/build-release.py
GIT_REMOTE := fork
PHP ?= php
PYTHON ?= python3

.PHONY: help validate version-check build release tag-release clean pot

help:
	@printf '%s\n' \
	  'Available targets:' \
	  '  make validate' \
	  '  make build' \
	  '  make release VERSION=1.1.1' \
	  '  make tag-release VERSION=1.1.1' \
	  '  make clean' \
	  '  make pot'

validate:
	@echo "Validating PHP syntax..."
	@find src -type f -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l
	@echo "Validating release builder..."
	@$(PYTHON) -m py_compile "$(BUILD_SCRIPT)"
	@echo "Checking repository whitespace..."
	@git diff --check
	@echo "Validation passed."

version-check:
	@if [ -z "$(VERSION)" ]; then \
	  echo "VERSION is required. Example: make release VERSION=1.1.1"; \
	  exit 1; \
	fi
	@if ! printf '%s\n' "$(VERSION)" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$$'; then \
	  echo "Invalid VERSION: $(VERSION)"; \
	  echo "Use semantic version format such as 1.1.1."; \
	  exit 1; \
	fi
	@PLUGIN_VERSION="$$(sed -nE 's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$$/\1/p' "$(PLUGIN_ENTRY)" | head -n 1)"; \
	if [ -z "$${PLUGIN_VERSION}" ]; then \
	  echo "Could not read the plugin version from $(PLUGIN_ENTRY)."; \
	  exit 1; \
	fi; \
	if [ "$${PLUGIN_VERSION}" != "$(VERSION)" ]; then \
	  echo "Version mismatch:"; \
	  echo "  Requested:     $(VERSION)"; \
	  echo "  Plugin header: $${PLUGIN_VERSION}"; \
	  exit 1; \
	fi

build: validate clean
	@echo "Building $(RELEASE_ZIP)..."
	@$(PYTHON) "$(BUILD_SCRIPT)" \
	  --source src \
	  --output "$(RELEASE_ZIP)"

release: version-check build
	@echo "Release ZIP is ready: $(RELEASE_ZIP)"

tag-release: version-check validate
	@if ! git diff --quiet || ! git diff --cached --quiet; then \
	  echo "The working tree or staging area contains uncommitted changes."; \
	  exit 1; \
	fi
	@if git rev-parse "v$(VERSION)" >/dev/null 2>&1; then \
	  echo "Tag v$(VERSION) already exists."; \
	  exit 1; \
	fi
	@git tag -a "v$(VERSION)" -m "Release v$(VERSION)"
	@git push "$(GIT_REMOTE)" "v$(VERSION)"
	@echo "Tag v$(VERSION) pushed to $(GIT_REMOTE)."
	@echo "GitHub Actions will validate, package, and publish the release."

clean:
	@rm -rf "$(RELEASE_DIR)"
	@find scripts -type d -name '__pycache__' -prune -exec rm -rf {} +
	@echo "Removed generated release files."

pot:
	@wp i18n make-pot \
	  src \
	  src/languages/wordpress-cloudflare-sync.pot \
	  --domain=wordpress-cloudflare-sync \
	  --allow-root
