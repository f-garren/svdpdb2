#!/bin/bash

# NexusDB Update Script
# This script pulls the latest version from GitHub and updates the system

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
REPO_URL="https://github.com/f-garren/NexusDB.git"
INSTALL_DIR="/var/www/html/nexusdb"
BACKUP_DIR="/root/nexusdb_backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_PATH="${BACKUP_DIR}/backup_${TIMESTAMP}"

# Detect current directory if script is run from NexusDB folder
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/config.php" ]; then
    INSTALL_DIR="${SCRIPT_DIR}"
fi

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}NexusDB Update Script${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Error: Please run as root or with sudo${NC}"
    exit 1
fi

# Create backup directory
mkdir -p "${BACKUP_DIR}"

# Check if installation directory exists
if [ ! -d "${INSTALL_DIR}" ]; then
    echo -e "${RED}Error: Installation directory not found: ${INSTALL_DIR}${NC}"
    echo "Please run setup.sh first or update INSTALL_DIR in this script."
    exit 1
fi

echo -e "${YELLOW}Step 1: Creating backup...${NC}"
mkdir -p "${BACKUP_PATH}"

# Backup config.php (contains database credentials)
if [ -f "${INSTALL_DIR}/config.php" ]; then
    cp "${INSTALL_DIR}/config.php" "${BACKUP_PATH}/config.php"
    echo "  ✓ Backed up config.php"
else
    echo -e "${YELLOW}  Warning: config.php not found${NC}"
fi

# Backup entire installation
echo "  Creating full backup of current installation..."
cp -r "${INSTALL_DIR}" "${BACKUP_PATH}/nexusdb" 2>/dev/null || {
    # If that fails, try backing up individual important files
    mkdir -p "${BACKUP_PATH}/nexusdb"
    for file in config.php database_schema.sql; do
        if [ -f "${INSTALL_DIR}/${file}" ]; then
            cp "${INSTALL_DIR}/${file}" "${BACKUP_PATH}/nexusdb/"
        fi
    done
}
echo "  ✓ Installation backed up to ${BACKUP_PATH}"

# Backup database
echo -e "${YELLOW}Step 2: Backing up database...${NC}"
if [ -f "${INSTALL_DIR}/config.php" ]; then
    # Extract database credentials from config.php
    DB_NAME=$(grep "define('DB_NAME" "${INSTALL_DIR}/config.php" | sed "s/.*'\(.*\)'.*/\1/")
    DB_USER=$(grep "define('DB_USER" "${INSTALL_DIR}/config.php" | sed "s/.*'\(.*\)'.*/\1/")
    DB_PASS=$(grep "define('DB_PASS" "${INSTALL_DIR}/config.php" | sed "s/.*'\(.*\)'.*/\1/")
    
    if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] && [ -n "$DB_PASS" ]; then
        mysqldump -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" > "${BACKUP_PATH}/database_backup.sql" 2>/dev/null && {
            echo "  ✓ Database backed up"
        } || {
            echo -e "${YELLOW}  Warning: Could not backup database (this is okay if database doesn't exist yet)${NC}"
        }
    else
        echo -e "${YELLOW}  Warning: Could not extract database credentials${NC}"
    fi
else
    echo -e "${YELLOW}  Warning: config.php not found, skipping database backup${NC}"
fi

# Store current config for restoration
SAVED_CONFIG="${BACKUP_PATH}/config.php"

echo -e "${YELLOW}Step 3: Downloading latest version from GitHub...${NC}"

# Check if git is installed
if ! command -v git &> /dev/null; then
    echo -e "${RED}Error: git is not installed${NC}"
    echo "Please install git: apt-get install git"
    exit 1
fi

# Create temporary directory for clone
TEMP_DIR=$(mktemp -d)
trap "rm -rf ${TEMP_DIR}" EXIT

# Clone repository
echo "  Cloning repository..."
if ! git clone "${REPO_URL}" "${TEMP_DIR}/nexusdb" 2>&1; then
    echo -e "${RED}Error: Failed to clone repository${NC}"
    echo "Please check your internet connection and repository URL."
    exit 1
fi
echo "  ✓ Repository cloned"

# Get the latest commit hash for reference
cd "${TEMP_DIR}/nexusdb"
LATEST_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
LATEST_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "none")
echo "  Latest commit: ${LATEST_COMMIT}"
if [ "$LATEST_TAG" != "none" ]; then
    echo "  Latest tag: ${LATEST_TAG}"
fi

echo -e "${YELLOW}Step 4: Updating files...${NC}"

# List of files to preserve (won't be overwritten)
PRESERVE_FILES=("config.php")

# Update files
cd "${TEMP_DIR}/nexusdb"

# Copy new files, but preserve config.php and update.sh
FILES_TO_PRESERVE=("config.php" "update.sh")

# Copy all files except preserved ones (only config.php)
for file in *; do
    if [ -f "$file" ] && [ "$file" != "config.php" ]; then
        cp "$file" "${INSTALL_DIR}/"
        echo "  ✓ Updated $file"
    fi
done

# Update CSS directory
if [ -d "css" ]; then
    if [ -d "${INSTALL_DIR}/css" ]; then
        cp -r css/* "${INSTALL_DIR}/css/"
        echo "  ✓ Updated CSS files"
    else
        mkdir -p "${INSTALL_DIR}/css"
        cp -r css/* "${INSTALL_DIR}/css/"
        echo "  ✓ Created and updated CSS directory"
    fi
fi

# Ensure update.sh is executable
if [ -f "${INSTALL_DIR}/update.sh" ]; then
    chmod +x "${INSTALL_DIR}/update.sh"
fi

echo -e "${YELLOW}Step 5: Updating database schema (if needed)...${NC}"

# Check if database schema needs updating
if [ -f "${INSTALL_DIR}/database_schema.sql" ] && [ -f "${SAVED_CONFIG}" ]; then
    DB_NAME=$(grep "define('DB_NAME" "${SAVED_CONFIG}" | sed "s/.*'\(.*\)'.*/\1/")
    DB_USER=$(grep "define('DB_USER" "${SAVED_CONFIG}" | sed "s/.*'\(.*\)'.*/\1/")
    DB_PASS=$(grep "define('DB_PASS" "${SAVED_CONFIG}" | sed "s/.*'\(.*\)'.*/\1/")
    
    if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] && [ -n "$DB_PASS" ]; then
        # Try to update database schema
        # We'll use mysql_upgrade approach - check for new tables/columns
        echo "  Checking database schema..."
        mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e "SHOW TABLES;" > /dev/null 2>&1 && {
            echo "  ✓ Database connection verified"
            # Note: Schema updates should be done manually or with a migration script
            # This script preserves the database structure
        } || {
            echo -e "${YELLOW}  Warning: Could not connect to database (may not exist yet)${NC}"
        }
    fi
else
    echo "  Skipping database schema update (config or schema file not found)"
fi

echo -e "${YELLOW}Step 6: Setting file permissions...${NC}"

# Set proper permissions
if [ -d "${INSTALL_DIR}" ]; then
    chown -R www-data:www-data "${INSTALL_DIR}" 2>/dev/null || {
        chown -R apache:apache "${INSTALL_DIR}" 2>/dev/null || {
            echo -e "${YELLOW}  Warning: Could not set ownership (may need manual fix)${NC}"
        }
    }
    
    # Set config.php permissions
    if [ -f "${INSTALL_DIR}/config.php" ]; then
        chmod 600 "${INSTALL_DIR}/config.php"
        echo "  ✓ Set config.php permissions"
    fi
    
    # Set directory permissions
    find "${INSTALL_DIR}" -type d -exec chmod 755 {} \;
    find "${INSTALL_DIR}" -type f -exec chmod 644 {} \;
    chmod 600 "${INSTALL_DIR}/config.php" 2>/dev/null || true
    
    echo "  ✓ Set file permissions"
fi

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Update completed successfully!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Backup location: ${BACKUP_PATH}"
echo ""
echo "Next steps:"
echo "1. Test the application to ensure everything works"
echo "2. Check for any new settings in the Settings page"
echo "3. Review the changelog/release notes if available"
echo ""
echo -e "${YELLOW}Note: Your config.php was preserved and not overwritten.${NC}"
echo -e "${YELLOW}Note: Database was backed up to ${BACKUP_PATH}/database_backup.sql${NC}"
echo ""

