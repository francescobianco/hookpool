.PHONY: start stop migrate push shell logs

start:
	docker compose up -d --build
	@echo "HookPool disponibile su http://localhost:$${PORT:-8080}"

stop:
	docker compose down

migrate:
	docker compose exec app php -f migrate.php

push:
	@git config credential.helper 'cache --timeout=3600'
	git add .
	git commit -m "update" || true
	git push origin main

# Usage:
#   make ftp-deploy file=prod.lftp
ftp-deploy: push
	@sh -c '\
	SCRIPT="$(file)"; \
	if [ -z "$$SCRIPT" ]; then \
	  echo "❌ file not set. Usage: make ftp-deploy file=<script.lftp>"; exit 1; \
	fi; \
	if [ ! -f "$$SCRIPT" ]; then \
	  echo "❌ script file not found: $$SCRIPT"; exit 1; \
	fi; \
	SCRIPT=$$(realpath "$$SCRIPT"); \
	echo "✅ Using script: $$SCRIPT"; \
	DEPLOY_SRC=$$(pwd)/.deploy; \
	rm -rf "$$DEPLOY_SRC"; mkdir -p "$$DEPLOY_SRC"; \
	echo "📦 Exporting repository to $$DEPLOY_SRC ..."; \
	git archive --format=tar HEAD | tar -x -C "$$DEPLOY_SRC"; \
	cd "$$DEPLOY_SRC"; \
	echo "🚀 Running lftp with script $$SCRIPT"; \
	lftp -f "$$SCRIPT"; \
	'


shell:
	docker compose exec app bash

logs:
	docker compose logs -f app
