# File: Makefile

PLUGIN_SLUG = wordfence-cloudflare-sync
RELEASE_DIR = dist
RELEASE_ZIP = $(RELEASE_DIR)/$(PLUGIN_SLUG).zip

.PHONY: release clean

release: clean
	@echo "📦 Creating plugin zip..."
	@mkdir -p $(RELEASE_DIR)
	@cd src && zip -r ../$(RELEASE_ZIP) . -x '*.DS_Store' '__MACOSX'
	@echo "✅ Done: $(RELEASE_ZIP)"

clean:
	@echo "🧹 Cleaning old release..."
	@rm -rf $(RELEASE_DIR)
	@echo "✅ Done: Old release cleaned."

format:
	php-cs-fixer fix

pot:
	wp i18n make-pot ./wp-content/plugins/wordfence-cloudflare-sync/ ./wp-content/plugins/wordfence-cloudflare-sync/languages/wordfence-cloudflare-sync.pot --domain=wordfence-cloudflare-sync --allow-root
