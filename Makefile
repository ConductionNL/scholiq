# Makefile for nextcloud-scholiq development

# Create a relative symlink in the parent directory so Nextcloud can find the
# app by its ID (scholiq) even though the repo is cloned as nextcloud-scholiq.
# Nextcloud requires the directory name to match the <id> in appinfo/info.xml.
dev-link:
	@if [ -L ../scholiq ]; then \
		echo "Symlink ../scholiq already exists."; \
	else \
		ln -s nextcloud-scholiq ../scholiq && \
		echo "Created symlink: apps-extra/scholiq -> nextcloud-scholiq"; \
	fi

dev-unlink:
	@if [ -L ../scholiq ]; then \
		rm ../scholiq && echo "Removed symlink ../scholiq"; \
	else \
		echo "No symlink found at ../scholiq."; \
	fi

.PHONY: dev-link dev-unlink
