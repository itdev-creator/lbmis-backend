#!/usr/bin/env bash
# Deploys one release on the server. Called by GitHub Actions through SSM:
#   deploy.sh <image-tag>
# Rollback: sudo /opt/lbmis/bin/deploy.sh "$(cat /opt/lbmis/previous_tag)"
set -euo pipefail

TAG="${1:?usage: deploy.sh <image-tag>}"
REGION="${AWS_REGION:-ap-southeast-2}"
export LBMIS_ENV="$(cat /opt/lbmis/env-name 2>/dev/null || echo staging)"
export ECR_APP_REPO="${ECR_APP_REPO:-lbmis-app}"
export ECR_WEB_REPO="${ECR_WEB_REPO:-lbmis-web}"
cd /opt/lbmis

ACCOUNT=$(aws sts get-caller-identity --query Account --output text)
export ECR_REGISTRY="${ACCOUNT}.dkr.ecr.${REGION}.amazonaws.com"
export IMAGE_TAG="$TAG"

echo "==> Deploying $TAG to $LBMIS_ENV"
aws ecr get-login-password --region "$REGION" | docker login --username AWS --password-stdin "$ECR_REGISTRY"
/opt/lbmis/bin/render-env.sh
docker compose pull

echo "==> Migrations"
docker compose run --rm app php artisan migrate --force

echo "==> Roles and permissions"
docker compose run --rm app php artisan lbmis:sync-permissions

echo "==> Starting containers"
docker compose up -d --remove-orphans

# lets manual "docker compose ..." commands on the server find the running images
printf 'ECR_REGISTRY=%s\nECR_APP_REPO=%s\nECR_WEB_REPO=%s\nIMAGE_TAG=%s\n' \
  "$ECR_REGISTRY" "$ECR_APP_REPO" "$ECR_WEB_REPO" "$IMAGE_TAG" > /opt/lbmis/.env

echo "==> Health check"
for i in $(seq 1 30); do
  if curl -fsS -o /dev/null http://127.0.0.1:8080/up; then
    [ -f current_tag ] && cp current_tag previous_tag
    echo "$TAG" > current_tag
    docker image prune -af --filter "until=168h" >/dev/null || true
    echo "==> Deploy $TAG OK"
    exit 0
  fi
  sleep 2
done

echo "==> Health check FAILED for $TAG"
docker compose logs --tail=50 app web
exit 1
