#!/bin/bash

set -e  # Stop on any error
COMPOSE_FILE="docker-compose.yml"

# Function to clean only custom-built Docker images
cleanup_custom_builds() {
    echo ""
    echo "🧹 Cleaning up: containers, networks, volumes..."
    docker compose -f "$COMPOSE_FILE" down -v --remove-orphans || true

    echo "🧼 Removing only custom-built (local) images..."
    # Get images defined in the compose file
    custom_images=$(docker compose -f "$COMPOSE_FILE" config | \
        awk '/image:/ {print $2}' | sort | uniq)

    for image in $custom_images; do
        # Check if it's a local image (no registry/namespace prefix)
        if ! grep -q '/' <<< "$image"; then
            echo "🗑️  Removing local image: $image"
            docker rmi -f "$image" || true
        else
            echo "📦 Skipping pulled image: $image"
        fi
    done

    echo "✅ Cleanup of custom builds complete."
}

# Full cleanup function
cleanup_all() {
    echo ""
    echo "🧹 Full cleanup: containers, networks, volumes..."
    docker compose -f "$COMPOSE_FILE" down -v --remove-orphans || true
    echo "🧼 Removing all unused Docker resources..."
    docker system prune -af --volumes || true
    echo "✅ Full cleanup complete."
}

# Choose which cleanup to perform
trap '[[ $SKIP_CLEANUP != "true" ]] && [[ $CUSTOM_ONLY == "true" ]] && cleanup_custom_builds || cleanup_all' EXIT

echo "🔧 Building Docker Compose services..."
docker compose -f "$COMPOSE_FILE" build --no-cache

echo "🚀 Starting services in detached mode..."
docker compose -f "$COMPOSE_FILE" up -d

echo "⏳ Waiting 30 seconds..."
sleep 30

echo "📜 Showing logs after 30 seconds:"
docker compose -f "$COMPOSE_FILE" logs --no-color

# Loop for interactive test phase
while docker compose -f "$COMPOSE_FILE" ps --services --filter "status=running" | grep . > /dev/null; do
    echo ""
    echo "📦 Services still running. Choose:"
    echo "[L] Show logs again"
    echo "[C] Clean up (custom-built only) and stop"
    echo "[F] Full cleanup and stop"
    echo "[S] Skip cleanup (exit and leave everything running)"
    read -rp "Your choice [L/C/F/S]: " choice

    case "${choice^^}" in
        L)
            echo "📜 Showing logs..."
            docker compose -f "$COMPOSE_FILE" logs --no-color
            ;;
        C)
            echo "🛑 Custom-only cleanup selected."
            CUSTOM_ONLY=true
            exit 0
            ;;
        F)
            echo "🛑 Full cleanup selected."
            CUSTOM_ONLY=false
            exit 0
            ;;
        S)
            echo "⚠️ Skipping cleanup. Containers will stay running."
            SKIP_CLEANUP=true
            trap - EXIT  # Disable cleanup
            exit 0
            ;;
        *)
            echo "❓ Invalid choice. Please enter L, C, F, or S."
            ;;
    esac
done

echo "✅ All services have exited normally."
