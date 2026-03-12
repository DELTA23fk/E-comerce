# ── Comandos Docker para el proyecto ──────────────────────────────────────────
# Uso: make up | make down | make url | make migrate

.PHONY: up down build shell migrate seed url logs fresh

# Levantar todos los servicios
up:
	docker compose up -d
	@echo ""
	@echo "✓ Servicios levantados"
	@echo "  Laravel:  http://localhost:8000"
	@echo ""
	@echo "  Obteniendo URL pública del tunnel..."
	@sleep 3
	@make url

# Detener servicios
down:
	docker compose down

# Rebuild de imágenes
build:
	docker compose build --no-cache

# Obtener la URL pública del tunnel de cloudflared
# Esta URL es la que debes configurar en el panel de MercadoPago
url:
	@URL=$$(docker compose logs cloudflared 2>&1 | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -1); \
	if [ -n "$$URL" ]; then \
		echo "🌐 URL pública del tunnel: $$URL"; \
		echo ""; \
		echo "  Webhook MP:  $$URL/webhooks/mercadopago"; \
		echo "  Webhook PP:  $$URL/webhooks/paypal"; \
		echo ""; \
		echo "  Actualiza APP_URL en .env:"; \
		echo "  APP_URL=$$URL"; \
	else \
		echo "⏳ Tunnel aún iniciando... intenta en unos segundos: make url"; \
	fi

# Abrir shell en el contenedor app
shell:
	docker compose exec app bash

# Correr migraciones
migrate:
	docker compose exec app php artisan migrate

# Correr migraciones + seeders
seed:
	docker compose exec app php artisan migrate --seed

# Reset completo de BD
fresh:
	docker compose exec app php artisan migrate:fresh --seed

# Ver logs en tiempo real
logs:
	docker compose logs -f app

# Ver logs del tunnel (para obtener la URL pública)
tunnel-logs:
	docker compose logs -f cloudflared

# Instalar dependencias composer
install:
	docker compose exec app composer install

# Limpiar caché de Laravel
cache-clear:
	docker compose exec app php artisan config:clear
	docker compose exec app php artisan cache:clear
	docker compose exec app php artisan route:clear

# Primera instalación completa
setup:
	cp .env.docker .env
	docker compose build
	docker compose up -d
	@sleep 5
	docker compose exec app composer install
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate --seed
	@make url