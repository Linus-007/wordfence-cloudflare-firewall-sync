PLUGIN_SLUG := grey-rock-block-synchroniser-for-wordfence-and-cloudflare
PLUGIN_ENTRY := src/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php
RELEASE_DIR := dist
RELEASE_ZIP := $(RELEASE_DIR)/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.zip
BUILD_SCRIPT := scripts/build-release.py
GIT_REMOTE ?= fork
PHP ?= php
PYTHON ?= python3

.PHONY: help validate metadata-check version-check build release tag-release clean pot

help:
	@printf '%s\n' \
		'Available targets:' \
		'  make validate' \
		'  make metadata-check' \
		'  make build' \
		'  make release VERSION=x.y.z' \
		'  make tag-release VERSION=x.y.z' \
		'  make clean' \
		'  make pot'

validate:
	@echo "Validating PHP syntax..."
	@find src -type f -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l
	@echo "Validating release builder..."
	@$(PYTHON) -m py_compile "$(BUILD_SCRIPT)"
	@echo "Checking plugin metadata..."
	@grep -q '^ \* License: GPLv2 or later$$' "$(PLUGIN_ENTRY)"
	@grep -q '^ \* Text Domain: grey-rock-block-synchroniser-for-wordfence-and-cloudflare$$' "$(PLUGIN_ENTRY)"
	@grep -q '^Contributors: greyscalezone$$' readme.txt
	@grep -q '^== External services ==$$' readme.txt
	@grep -q '^== Privacy ==$$' readme.txt
	@echo "Checking for inline script tags..."
	@if grep -RIn '<script' src --include='*.php'; then \
		echo "Inline script tag found."; \
		exit 1; \
	fi
	@echo "Checking repository whitespace..."
	@git diff --check
	@echo "Validation passed."

metadata-check:
	@PLUGIN_VERSION="$$(sed -nE 's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$$/\1/p' "$(PLUGIN_ENTRY)" | head -n 1)"; \
	STABLE_TAG="$$(sed -nE 's/^Stable tag:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$$/\1/p' readme.txt | head -n 1)"; \
	if [ -z "$${PLUGIN_VERSION}" ] || [ -z "$${STABLE_TAG}" ]; then \
		echo "Could not read plugin version or Stable Tag."; \
		exit 1; \
	fi; \
	if [ "$${PLUGIN_VERSION}" != "$${STABLE_TAG}" ]; then \
		echo "Metadata mismatch: plugin=$${PLUGIN_VERSION} stable-tag=$${STABLE_TAG}"; \
		exit 1; \
	fi; \
	echo "Metadata version: $${PLUGIN_VERSION}"

version-check: metadata-check
	@if [ -z "$(VERSION)" ]; then \
		echo "VERSION is required. Example: make release VERSION=x.y.z"; \
		exit 1; \
	fi
	@if ! printf '%s\n' "$(VERSION)" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$$'; then \
		echo "Invalid VERSION: $(VERSION)"; \
		exit 1; \
	fi
	@PLUGIN_VERSION="$$(sed -nE 's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$$/\1/p' "$(PLUGIN_ENTRY)" | head -n 1)"; \
	if [ "$${PLUGIN_VERSION}" != "$(VERSION)" ]; then \
		echo "Release version mismatch: $${PLUGIN_VERSION} != $(VERSION)"; \
		exit 1; \
	fi

build: validate metadata-check clean
	@echo "Building release..."
	@$(PYTHON) "$(BUILD_SCRIPT)" \
		--source src \
		--readme readme.txt \
		--plugin-slug "$(PLUGIN_SLUG)" \
		--output "$(RELEASE_ZIP)"

release: version-check build
	@echo "Release ZIP: $(RELEASE_ZIP)"

tag-release: release
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
		src/languages/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.pot \
		--domain=grey-rock-block-synchroniser-for-wordfence-and-cloudflare \
		--allow-root
