SHELL = bash

all: vendor locale-build

locale-update:
	$(MAKE) -C locale update

locale-build:
	$(MAKE) -C locale clean build

export COMPOSER_ALLOW_SUPERUSER = true
COMPOSER_INSTALL = composer install \
	--no-interaction \
	--no-progress \
	--no-plugins \
	--no-scripts \
	--optimize-autoloader

vendor:
	$(COMPOSER_INSTALL)

.PHONY: build
build: locale-build
	$(COMPOSER_INSTALL) --no-dev --ignore-platform-reqs
	python3 tools/patch_propel_php84.py
	python3 tools/patch_zend_session_php84.py

# Refresh legacy/application/assets.json MD5 query params (browser cache busting).
.PHONY: update-assets-checksums
update-assets-checksums:
	python3 tools/update_assets_checksums.py
