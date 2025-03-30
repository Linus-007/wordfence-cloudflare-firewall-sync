#!/bin/bash
# File: bootstrap.sh

set -e

USERNAME=devuser_$(head /dev/urandom | tr -dc a-z0-9 | head -c6)
PASSWORD=$(head /dev/urandom | tr -dc a-z0-9 | head -c12)
EMAIL="${USERNAME}@localhost.local"

echo "⏳ Waiting for wordpress database..."
until wp db check --allow-root; do
  echo "⏳ Waiting for database..."
  sleep 5
done

echo "⏳ Waiting for wordpress core..."

# Install Wordpress if not already
if ! wp core is-installed --allow-root; then
  echo "🚀 Installing WordPress core..."
  wp core install \
    --url="http://localhost:8080" \
    --title="Local WordPress Dev" \
    --admin_user="$USERNAME" \
    --admin_password="$PASSWORD" \
    --admin_email="$EMAIL" \
    --allow-root \
    --skip-email
else
  echo "🧹 Cleaning up existing users..."

  for ID in $(wp user list --field=ID --allow-root); do
    wp user delete "$ID" --yes --allow-root
  done

  echo "👤 Creating new dev admin user..."
  wp user create "$USERNAME" "$EMAIL" --role=administrator --user_pass="$PASSWORD" --allow-root
fi

cat <<EOF > /var/www/html/autologin.json
{
  "username": "$USERNAME",
  "password": "$PASSWORD"
}
EOF

echo "🚀 Installing Wordfence Plugin"
wp plugin install wordfence --activate --allow-root

echo "🚀 Installing Wordfence Cloudflare Sync plugin..."
wp plugin install wordfence-cloudflare-sync --activate --allow-root

echo "🧹 Removing pre-installed plugins..."
wp plugin delete hello --allow-root
wp plugin delete akismet --allow-root

echo ""
echo "✅ Wordfence Cloudflare Sync plugin activated."
echo ""
echo "✅ WordPress ready."
echo ""
echo "✅ Created dev admin account:"
echo "   Username: $USERNAME"
echo "   Password: $PASSWORD"
echo ""
echo "   Auto-login URL:"
echo "   Visit http://localhost:8080/autologin.php to auto-login"
