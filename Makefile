PLUGIN_SLUG := wordfence-cloudflare-firewall-sync
WORDPRESS_ORG_SLUG := greyrock-wordfence-cloudflare-synchroniser
PLUGIN_ENTRY := src/index.php
RELEASE_DIR := dist
RELEASE_ZIP := $(RELEASE_DIR)/greyrock-wordfence-cloudflare-synchroniser.zip
WORDPRESS_ORG_ZIP := $(RELEASE_DIR)/greyrock-wordfence-cloudflare-synchroniser-wordpress-org.zip
BUILD_SCRIPT := scripts/build-release.py
GIT_REMOTE := fork
PHP ?= php
PYTHON ?= python3

.PHONY: help validate version-check build wordpress-org release tag-release clean pot

help:
	@printf '%s\n' \
	  'Available targets:' \
	  '  make validate' \
	  '  make build' \
	  '  make wordpress-org' \
	  '  make release VERSION=1.1.7' \
	  '  make tag-release VERSION=1.1.7' \
	  '  make clean' \
	  '  make pot'

validate:
	@echo "Validating PHP syntax..."
	@find src -type f -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l
	@echo "Validating release builder..."
	@$(PYTHON) -m py_compile "$(BUILD_SCRIPT)"
	@echo "Checking plugin metadata..."
	@grep -q '^ \* License: GPLv2 or later$$' "$(PLUGIN_ENTRY)"
	@grep -q '^ \* Text Domain: greyrock-wordfence-cloudflare-synchroniser$$' "$(PLUGIN_ENTRY)"
	@grep -q '^Contributors: greyscalezone$$' readme.txt
	@grep -q '^== External services ==$$' readme.txt
	@grep -q '^== Privacy ==$$' readme.txt
	@echo "Checking repository whitespace..."
	@git diff --check
	@echo "Validation passed."

version-check:
	@if [ -z "$(VERSION)" ]; then \
	  echo "VERSION is required. Example: make release VERSION=1.1.7"; \
	  exit 1; \
	fi
	@if ! printf '%s\n' "$(VERSION)" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$$'; then \
	  echo "Invalid VERSION: $(VERSION)"; \
	  exit 1; \
	fi
	@PLUGIN_VERSION="$$(sed -nE 's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$$/\1/p' "$(PLUGIN_ENTRY)" | head -n 1)"; \
	STABLE_TAG="$$(sed -nE 's/^Stable tag:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$$/\1/p' readme.txt | head -n 1)"; \
	if [ "$${PLUGIN_VERSION}" != "$(VERSION)" ]; then \
	  echo "Plugin version mismatch: $${PLUGIN_VERSION} != $(VERSION)"; \
	  exit 1; \
	fi; \
	if [ "$${STABLE_TAG}" != "$(VERSION)" ]; then \
	  echo "Stable tag mismatch: $${STABLE_TAG} != $(VERSION)"; \
	  exit 1; \
	fi

build: validate clean
	@echo "Building GitHub-compatible release..."
	@$(PYTHON) "$(BUILD_SCRIPT)" \
	  --source src \
	  --readme readme.txt \
	  --plugin-slug "$(PLUGIN_SLUG)" \
	  --output "$(RELEASE_ZIP)"

wordpress-org: validate
	@mkdir -p "$(RELEASE_DIR)"
	@echo "Building WordPress.org submission package..."
	@$(PYTHON) "$(BUILD_SCRIPT)" \
	  --source src \
	  --readme readme.txt \
	  --plugin-slug "$(WORDPRESS_ORG_SLUG)" \
	  --output "$(WORDPRESS_ORG_ZIP)"

release: version-check build wordpress-org
	@echo "GitHub release ZIP: $(RELEASE_ZIP)"
	@echo "WordPress.org ZIP: $(WORDPRESS_ORG_ZIP)"

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

clean:
	@rm -rf "$(RELEASE_DIR)"
	@find scripts -type d -name '__pycache__' -prune -exec rm -rf {} +
	@echo "Removed generated release files."

pot:
	@wp i18n make-pot \
	  src \
	  src/languages/greyrock-wordfence-cloudflare-synchroniser.pot \
	  --domain=greyrock-wordfence-cloudflare-synchroniser \
	  --allow-root
