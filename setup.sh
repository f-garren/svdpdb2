#!/bin/bash

# NexusDB Food Distribution System - Setup Script for Ubuntu 24.04
# This script sets up the web server, database, and configures the application
# 
# This script should be run from within a cloned NexusDB repository directory.
# Usage: sudo ./setup.sh

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Print colored output
print_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    print_error "Please run as root (use sudo)"
    exit 1
fi

print_info "Starting NexusDB setup for Ubuntu 24.04..."

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="$SCRIPT_DIR"

# Verify we're in a valid NexusDB directory
if [ ! -f "$INSTALL_DIR/database_schema.sql" ]; then
    print_error "database_schema.sql not found. Please run this script from the NexusDB repository directory."
    exit 1
fi

print_info "Using repository directory: $INSTALL_DIR"

# Ask about HTTPS setup
echo ""
echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}HTTPS Configuration${NC}"
echo -e "${YELLOW}========================================${NC}"
echo ""
read -p "Do you want to set up HTTPS/SSL for NexusDB? (y/n): " SETUP_HTTPS
SETUP_HTTPS=$(echo "$SETUP_HTTPS" | tr '[:upper:]' '[:lower:]')

DOMAIN=""
SUBDOMAIN=""
FULL_DOMAIN=""

if [ "$SETUP_HTTPS" = "y" ] || [ "$SETUP_HTTPS" = "yes" ]; then
    echo ""
    read -p "Enter your domain name (e.g., example.com): " DOMAIN
    
    if [ -z "$DOMAIN" ]; then
        print_error "Domain name cannot be empty. Exiting."
        exit 1
    fi
    
    echo ""
    read -p "Enter subdomain (leave empty for www or no subdomain): " SUBDOMAIN
    
    if [ -z "$SUBDOMAIN" ]; then
        FULL_DOMAIN="$DOMAIN"
        print_info "Using domain: $FULL_DOMAIN"
    else
        FULL_DOMAIN="${SUBDOMAIN}.${DOMAIN}"
        print_info "Using full domain: $FULL_DOMAIN"
    fi
    
    # Verify domain resolution (if dig is available)
    if command -v dig &> /dev/null; then
        print_info "Verifying domain resolves to this server..."
        SERVER_IP=$(hostname -I | awk '{print $1}')
        DOMAIN_IP=$(dig +short "$FULL_DOMAIN" | tail -n1)
        
        if [ -n "$DOMAIN_IP" ] && [ "$DOMAIN_IP" != "$SERVER_IP" ]; then
            print_warning "Domain $FULL_DOMAIN resolves to $DOMAIN_IP, but this server's IP is $SERVER_IP"
            print_warning "Certbot may fail if DNS is not properly configured."
            read -p "Continue anyway? (y/n): " CONTINUE
            CONTINUE=$(echo "$CONTINUE" | tr '[:upper:]' '[:lower:]')
            if [ "$CONTINUE" != "y" ] && [ "$CONTINUE" != "yes" ]; then
                print_error "Setup cancelled."
                exit 1
            fi
        elif [ -z "$DOMAIN_IP" ]; then
            print_warning "Could not resolve domain $FULL_DOMAIN. DNS may not be configured yet."
            read -p "Continue anyway? (y/n): " CONTINUE
            CONTINUE=$(echo "$CONTINUE" | tr '[:upper:]' '[:lower:]')
            if [ "$CONTINUE" != "y" ] && [ "$CONTINUE" != "yes" ]; then
                print_error "Setup cancelled."
                exit 1
            fi
        else
            print_info "Domain DNS verified: $FULL_DOMAIN -> $DOMAIN_IP"
        fi
    else
        print_warning "dig command not available. Skipping DNS verification."
        print_warning "Make sure $FULL_DOMAIN points to this server before certbot runs."
    fi
fi

# Step 1: Update system
print_info "Updating system packages..."
apt-get update -qq
apt-get upgrade -y -qq

# Step 2: Install necessary packages
print_info "Installing required packages (Apache, PHP, MySQL)..."
apt-get install -y apache2 mysql-server php php-mysql php-pdo php-xml php-mbstring php-curl libapache2-mod-php unzip expect

# Install certbot if HTTPS is requested
if [ "$SETUP_HTTPS" = "y" ] || [ "$SETUP_HTTPS" = "yes" ]; then
    print_info "Installing certbot for SSL certificates..."
    apt-get install -y certbot python3-certbot-apache
fi

# Step 3: Enable Apache modules
print_info "Enabling Apache modules..."
a2enmod rewrite
if [ "$SETUP_HTTPS" = "y" ] || [ "$SETUP_HTTPS" = "yes" ]; then
    a2enmod ssl
    a2enmod headers
fi

# Detect and enable the correct PHP module version
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "")
if [ -n "$PHP_VERSION" ]; then
    print_info "Detected PHP version: $PHP_VERSION"
    # Try to enable the version-specific PHP module
    if a2enmod php${PHP_VERSION} 2>/dev/null; then
        print_info "Enabled PHP module php${PHP_VERSION}"
    else
        print_warning "Could not enable php${PHP_VERSION} module explicitly, but libapache2-mod-php should have enabled it automatically"
    fi
else
    print_warning "Could not detect PHP version, but libapache2-mod-php should have enabled PHP module automatically"
fi

# Restart Apache to apply module changes
print_info "Restarting Apache to apply module changes..."
systemctl restart apache2

# Step 4: Generate random MySQL root password
print_info "Generating secure MySQL root password..."
MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-25)
DB_NAME="nexusdb"
DB_USER="nexusdb_user"
DB_PASSWORD=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-25)

# Save passwords to file (with restricted permissions)
PASSWORD_FILE="/root/nexusdb_passwords.txt"
echo "=== NexusDB Database Passwords ===" > "$PASSWORD_FILE"
echo "Generated: $(date)" >> "$PASSWORD_FILE"
echo "" >> "$PASSWORD_FILE"
echo "MySQL Root Password: $MYSQL_ROOT_PASSWORD" >> "$PASSWORD_FILE"
echo "Database Name: $DB_NAME" >> "$PASSWORD_FILE"
echo "Database User: $DB_USER" >> "$PASSWORD_FILE"
echo "Database User Password: $DB_PASSWORD" >> "$PASSWORD_FILE"
echo "" >> "$PASSWORD_FILE"
echo "WARNING: Keep this file secure! Store it in a safe location." >> "$PASSWORD_FILE"
chmod 600 "$PASSWORD_FILE"

print_info "Passwords saved to $PASSWORD_FILE (chmod 600)"

# Step 5: Secure MySQL installation and set root password
print_info "Configuring MySQL..."
systemctl start mysql
systemctl enable mysql

# Set MySQL root password using mysql_secure_installation automation
print_info "Setting MySQL root password..."
SECURE_MYSQL=$(expect -c "
set timeout 10
spawn mysql_secure_installation
expect \"Press y|Y for Yes, any other key for No:\"
send \"n\r\"
expect \"New password:\"
send \"$MYSQL_ROOT_PASSWORD\r\"
expect \"Re-enter new password:\"
send \"$MYSQL_ROOT_PASSWORD\r\"
expect \"Remove anonymous users? (Press y|Y for Yes, any other key for No) :\"
send \"y\r\"
expect \"Disallow root login remotely? (Press y|Y for Yes, any other key for No) :\"
send \"y\r\"
expect \"Remove test database and access to it? (Press y|Y for Yes, any other key for No) :\"
send \"y\r\"
expect \"Reload privilege tables now? (Press y|Y for Yes, any other key for No) :\"
send \"y\r\"
expect eof
")

echo "$SECURE_MYSQL" > /dev/null

# Step 6: Create database and user
print_info "Creating database and user..."
mysql -u root -p"$MYSQL_ROOT_PASSWORD" <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

# Step 7: Import database schema
print_info "Importing database schema..."
SCHEMA_FILE="$INSTALL_DIR/database_schema.sql"

if [ -f "$SCHEMA_FILE" ]; then
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_NAME" < "$SCHEMA_FILE"
    print_info "Database schema imported successfully"
else
    print_error "database_schema.sql not found in $INSTALL_DIR"
    exit 1
fi

# Step 8: Copy files to web root
WEB_ROOT="/var/www/html"
APP_DIR="$WEB_ROOT/nexusdb"

print_info "Copying application files to web root..."
if [ -d "$APP_DIR" ]; then
    print_warning "Web directory $APP_DIR already exists. Removing old files..."
    rm -rf "$APP_DIR"
fi

mkdir -p "$APP_DIR"
# Copy all files except .git directory and setup.sh
rsync -a --exclude='.git' --exclude='setup.sh' "$INSTALL_DIR/" "$APP_DIR/" || {
    # Fallback to cp if rsync not available
    cp -r "$INSTALL_DIR"/* "$APP_DIR/" 2>/dev/null
    rm -rf "$APP_DIR/.git" "$APP_DIR/setup.sh" 2>/dev/null
}

# Step 9: Update config.php with database credentials
print_info "Updating config.php with database credentials..."
CONFIG_FILE="$APP_DIR/config.php"

if [ -f "$CONFIG_FILE" ]; then
    # Backup original config
    cp "$CONFIG_FILE" "$CONFIG_FILE.backup"
    
    # Update database credentials
    sed -i "s/define('DB_NAME', '[^']*');/define('DB_NAME', '$DB_NAME');/" "$CONFIG_FILE"
    sed -i "s/define('DB_USER', '[^']*');/define('DB_USER', '$DB_USER');/" "$CONFIG_FILE"
    sed -i "s/define('DB_PASS', '[^']*');/define('DB_PASS', '$DB_PASSWORD');/" "$CONFIG_FILE"
    
    print_info "Config file updated successfully"
else
    print_error "config.php not found in $APP_DIR"
    exit 1
fi

# Step 9.5: Initialize admin account (after config is updated)
print_info "Initializing admin account..."
cd "$APP_DIR"
php init_admin.php 2>&1 || print_warning "Could not initialize admin account (you may need to run init_admin.php manually)"
cd "$INSTALL_DIR"

# Step 10: Set proper permissions
print_info "Setting file permissions..."
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chmod 600 "$CONFIG_FILE"

# Step 10.5: Configure Apache Virtual Host and HTTPS
if [ "$SETUP_HTTPS" = "y" ] || [ "$SETUP_HTTPS" = "yes" ]; then
    print_info "Configuring Apache Virtual Host for HTTPS..."
    
    # Create virtual host configuration file
    VHOST_FILE="/etc/apache2/sites-available/nexusdb.conf"
    
    cat > "$VHOST_FILE" <<EOF
<VirtualHost *:80>
    ServerName $FULL_DOMAIN
    DocumentRoot $APP_DIR
    
    <Directory $APP_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/nexusdb_error.log
    CustomLog \${APACHE_LOG_DIR}/nexusdb_access.log combined
</VirtualHost>
EOF
    
    # Enable the site
    a2ensite nexusdb.conf
    a2dissite 000-default.conf 2>/dev/null || true
    
    # Restart Apache to apply configuration
    systemctl restart apache2
    
    # Request email for certbot
    echo ""
    read -p "Enter email address for Let's Encrypt notifications (required for certificate): " CERTBOT_EMAIL
    
    if [ -z "$CERTBOT_EMAIL" ]; then
        print_error "Email address is required for Let's Encrypt certificates."
        print_warning "Continuing with HTTP setup. You can set up HTTPS later with: certbot --apache -d $FULL_DOMAIN"
        SETUP_HTTPS="failed"
    else
        # Verify email format (basic check)
        if [[ ! "$CERTBOT_EMAIL" =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
            print_warning "Email format may be invalid. Continuing anyway..."
        fi
        
        # Request SSL certificate with certbot
        print_info "Requesting SSL certificate from Let's Encrypt..."
        print_warning "Make sure port 80 and 443 are open in your firewall."
        print_warning "Domain $FULL_DOMAIN must resolve to this server's IP address."
        echo ""
        sleep 2
        
        # Run certbot with email
        certbot --apache -d "$FULL_DOMAIN" --email "$CERTBOT_EMAIL" --agree-tos --non-interactive --redirect || {
            print_error "Certbot failed. This may be due to:"
            print_error "1. Domain DNS not pointing to this server"
            print_error "2. Firewall blocking ports 80/443"
            print_error "3. Apache configuration issues"
            print_error "4. Rate limiting from Let's Encrypt"
            echo ""
            print_warning "You can manually run: certbot --apache -d $FULL_DOMAIN"
            print_warning "Continuing with HTTP setup..."
            SETUP_HTTPS="failed"
        }
    fi
    
    if [ "$SETUP_HTTPS" != "failed" ]; then
        print_info "SSL certificate installed successfully!"
        
        # Test certificate renewal
        print_info "Testing certificate renewal..."
        certbot renew --dry-run > /dev/null 2>&1 && {
            print_info "Certificate auto-renewal configured successfully"
        } || {
            print_warning "Certificate auto-renewal test failed (this may be normal)"
        }
    fi
else
    # Configure basic Apache Virtual Host for HTTP
    print_info "Configuring Apache Virtual Host for HTTP..."
    
    VHOST_FILE="/etc/apache2/sites-available/nexusdb.conf"
    
    cat > "$VHOST_FILE" <<EOF
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot $APP_DIR
    
    <Directory $APP_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/nexusdb_error.log
    CustomLog \${APACHE_LOG_DIR}/nexusdb_access.log combined
</VirtualHost>
EOF
    
    # Enable the site
    a2ensite nexusdb.conf
    a2dissite 000-default.conf 2>/dev/null || true
fi

# Step 11: Restart services
print_info "Restarting services..."
systemctl restart apache2
systemctl restart mysql

# Step 12: Display information
print_info "Setup completed successfully!"
echo ""
echo "=========================================="
echo "NexusDB Setup Summary"
echo "=========================================="
echo "Database Name: $DB_NAME"
echo "Database User: $DB_USER"
echo "Web Directory: $APP_DIR"
echo ""
echo "IMPORTANT: Passwords saved to: $PASSWORD_FILE"
echo ""
echo "Access your application at:"
if [ "$SETUP_HTTPS" = "y" ] || [ "$SETUP_HTTPS" = "yes" ]; then
    if [ "$SETUP_HTTPS" != "failed" ]; then
        echo "  https://$FULL_DOMAIN"
        echo "  (HTTP requests will be automatically redirected to HTTPS)"
    else
        echo "  http://$FULL_DOMAIN (HTTPS setup failed, using HTTP)"
    fi
else
    echo "  http://localhost/nexusdb/"
    echo "  or"
    echo "  http://$(hostname -I | awk '{print $1}')/nexusdb/"
fi
echo ""
if [ "$SETUP_HTTPS" != "y" ] && [ "$SETUP_HTTPS" != "yes" ]; then
    print_warning "Make sure to secure your server and consider setting up HTTPS!"
fi
if [ "$SETUP_HTTPS" = "y" ] || [ "$SETUP_HTTPS" = "yes" ]; then
    if [ "$SETUP_HTTPS" != "failed" ]; then
        print_info "HTTPS is configured and enabled!"
        print_info "Certificate will auto-renew via certbot."
    fi
fi
echo "=========================================="

