.PHONY: start stop migrate push shell logs

start:
	docker compose up -d --build
	@echo "HookPool disponibile su http://localhost:$${PORT:-8080}"

stop:
	docker compose down

migrate:
	docker compose exec app php -f migrate.php

push:
	git add .
	git commit -m "update"
	git push origin main

ftp-deploy:
	@if [ -z "$(file)" ]; then echo "Uso: make ftp-deploy file=prod.lftp"; exit 1; fi
	git push origin main
	rm -rf .deploy && mkdir .deploy
	git archive HEAD | tar -x -C .deploy/
	lftp -f $(file)
	rm -rf .deploy

shell:
	docker compose exec app bash

logs:
	docker compose logs -f app
