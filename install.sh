#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# SuperAgent Installer — Linux / macOS
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/forgeomni/superagent/main/install.sh | bash
#
# What it does:
#   1. Detects OS and architecture
#   2. Checks for PHP >= 8.1, installs if missing
#   3. Checks for Composer, installs if missing
#   4. Installs SuperAgent globally
#   5. Adds to PATH if needed
# ============================================================================

VERSION="0.8.5"
REQUIRED_PHP="8.1"
INSTALL_DIR="${SUPERAGENT_HOME:-$HOME/.superagent}"
BIN_DIR="${INSTALL_DIR}/bin"

# --- Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
RESET='\033[0m'

info()    { echo -e "${CYAN}${BOLD}==>${RESET} $1"; }
success() { echo -e "${GREEN}✓${RESET} $1"; }
warn()    { echo -e "${YELLOW}⚠${RESET} $1"; }
error()   { echo -e "${RED}✗${RESET} $1"; exit 1; }

# --- Detect OS ---
detect_os() {
    local os
    os="$(uname -s)"
    case "$os" in
        Linux*)  echo "linux" ;;
        Darwin*) echo "macos" ;;
        MINGW*|MSYS*|CYGWIN*) echo "windows" ;;
        *) error "Unsupported OS: $os" ;;
    esac
}

# --- Detect Linux distro ---
detect_distro() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        case "$ID" in
            ubuntu|debian|linuxmint|pop|elementary|zorin)
                echo "debian" ;;
            fedora|rhel|centos|rocky|alma|ol)
                echo "redhat" ;;
            arch|manjaro|endeavouros)
                echo "arch" ;;
            alpine)
                echo "alpine" ;;
            opensuse*|sles)
                echo "suse" ;;
            *)
                # Try ID_LIKE as fallback
                case "${ID_LIKE:-}" in
                    *debian*|*ubuntu*) echo "debian" ;;
                    *rhel*|*fedora*)   echo "redhat" ;;
                    *arch*)            echo "arch" ;;
                    *suse*)            echo "suse" ;;
                    *)                 echo "unknown" ;;
                esac
                ;;
        esac
    else
        echo "unknown"
    fi
}

# --- Check PHP version ---
check_php() {
    if ! command -v php &>/dev/null; then
        return 1
    fi

    local version
    version=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "0.0")
    local major minor
    major=$(echo "$version" | cut -d. -f1)
    minor=$(echo "$version" | cut -d. -f2)
    local req_major req_minor
    req_major=$(echo "$REQUIRED_PHP" | cut -d. -f1)
    req_minor=$(echo "$REQUIRED_PHP" | cut -d. -f2)

    if [ "$major" -gt "$req_major" ] || { [ "$major" -eq "$req_major" ] && [ "$minor" -ge "$req_minor" ]; }; then
        return 0
    fi

    return 1
}

# --- Install PHP ---
install_php() {
    local os="$1"
    info "Installing PHP >= ${REQUIRED_PHP}..."

    case "$os" in
        macos)
            if command -v brew &>/dev/null; then
                brew install php
            else
                info "Installing Homebrew first..."
                /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
                # Add brew to current session PATH
                if [ -f /opt/homebrew/bin/brew ]; then
                    eval "$(/opt/homebrew/bin/brew shellenv)"
                elif [ -f /usr/local/bin/brew ]; then
                    eval "$(/usr/local/bin/brew shellenv)"
                fi
                brew install php
            fi
            ;;
        linux)
            local distro
            distro=$(detect_distro)

            case "$distro" in
                debian)
                    # Add ondrej/php PPA for latest PHP on Ubuntu/Debian
                    if command -v add-apt-repository &>/dev/null; then
                        sudo add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
                    fi
                    sudo apt-get update -qq
                    sudo apt-get install -y -qq php8.3-cli php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip php8.3-sqlite3
                    ;;
                redhat)
                    if command -v dnf &>/dev/null; then
                        sudo dnf install -y php-cli php-curl php-mbstring php-xml php-zip php-pdo
                    else
                        sudo yum install -y php-cli php-curl php-mbstring php-xml php-zip
                    fi
                    ;;
                arch)
                    sudo pacman -Sy --noconfirm php
                    ;;
                alpine)
                    sudo apk add php83 php83-curl php83-mbstring php83-xml php83-zip php83-phar php83-openssl php83-sqlite3
                    # Alpine uses php83 binary name
                    sudo ln -sf /usr/bin/php83 /usr/bin/php 2>/dev/null || true
                    ;;
                suse)
                    sudo zypper install -y php8 php8-curl php8-mbstring php8-xml php8-zip
                    ;;
                *)
                    error "Could not detect your Linux distribution.\nPlease install PHP >= ${REQUIRED_PHP} manually, then re-run this script."
                    ;;
            esac
            ;;
        *)
            error "Automatic PHP installation is not supported on this OS.\nPlease install PHP >= ${REQUIRED_PHP} manually."
            ;;
    esac

    # Verify
    if ! check_php; then
        error "PHP installation failed or version too old. Please install PHP >= ${REQUIRED_PHP} manually."
    fi

    success "PHP $(php -r 'echo PHP_VERSION;') installed"
}

# --- Install Composer ---
install_composer() {
    if command -v composer &>/dev/null; then
        success "Composer already installed"
        return
    fi

    info "Installing Composer..."

    local expected_sig actual_sig
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    expected_sig="$(curl -fsSL https://composer.github.io/installer.sig)"
    actual_sig="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"

    if [ "$expected_sig" != "$actual_sig" ]; then
        rm -f /tmp/composer-setup.php
        error "Composer installer signature mismatch. Aborting."
    fi

    php /tmp/composer-setup.php --install-dir=/tmp --filename=composer --quiet
    rm -f /tmp/composer-setup.php

    # Move to a global location
    if [ -d /usr/local/bin ] && [ -w /usr/local/bin ]; then
        mv /tmp/composer /usr/local/bin/composer
    elif [ -d "$HOME/.local/bin" ]; then
        mv /tmp/composer "$HOME/.local/bin/composer"
    else
        mkdir -p "$HOME/.local/bin"
        mv /tmp/composer "$HOME/.local/bin/composer"
        export PATH="$HOME/.local/bin:$PATH"
    fi

    chmod +x "$(command -v composer)"
    success "Composer installed"
}

# --- Install SuperAgent ---
install_superagent() {
    info "Installing SuperAgent v${VERSION}..."

    mkdir -p "$INSTALL_DIR"

    # Set Composer home to our install dir to keep things tidy
    export COMPOSER_HOME="${INSTALL_DIR}/.composer"

    # Global install via Composer
    composer global require "forgeomni/superagent:*" --no-interaction --quiet 2>/dev/null || {
        # Fallback: clone and install from source
        warn "Composer registry not available, installing from source..."
        if [ -d "${INSTALL_DIR}/source" ]; then
            rm -rf "${INSTALL_DIR}/source"
        fi
        git clone --depth 1 https://github.com/forgeomni/superagent.git "${INSTALL_DIR}/source"
        cd "${INSTALL_DIR}/source"
        composer install --no-dev --no-interaction --quiet
        mkdir -p "$BIN_DIR"
        ln -sf "${INSTALL_DIR}/source/bin/superagent" "${BIN_DIR}/superagent"
        chmod +x "${BIN_DIR}/superagent"
        cd - >/dev/null
    }

    # If composer global install worked, link the binary
    local composer_bin
    composer_bin="$(composer global config bin-dir --absolute 2>/dev/null || echo "${COMPOSER_HOME}/vendor/bin")"
    if [ -f "${composer_bin}/superagent" ]; then
        mkdir -p "$BIN_DIR"
        ln -sf "${composer_bin}/superagent" "${BIN_DIR}/superagent"
        chmod +x "${BIN_DIR}/superagent"
    fi

    success "SuperAgent installed to ${INSTALL_DIR}"
}

# --- Configure PATH ---
configure_path() {
    if echo "$PATH" | tr ':' '\n' | grep -q "^${BIN_DIR}$"; then
        return  # Already in PATH
    fi

    info "Adding SuperAgent to PATH..."

    local shell_name shell_rc
    shell_name="$(basename "${SHELL:-/bin/bash}")"

    case "$shell_name" in
        zsh)  shell_rc="$HOME/.zshrc" ;;
        fish) shell_rc="$HOME/.config/fish/config.fish" ;;
        *)    shell_rc="$HOME/.bashrc" ;;
    esac

    local path_line
    if [ "$shell_name" = "fish" ]; then
        path_line="fish_add_path ${BIN_DIR}"
    else
        path_line="export PATH=\"${BIN_DIR}:\$PATH\""
    fi

    # Add to shell config if not already present
    if [ -f "$shell_rc" ] && grep -q "superagent" "$shell_rc" 2>/dev/null; then
        return  # Already configured
    fi

    {
        echo ""
        echo "# SuperAgent"
        echo "$path_line"
    } >> "$shell_rc"

    # Also export for current session
    export PATH="${BIN_DIR}:$PATH"

    success "Added to PATH in ${shell_rc}"
}

# --- Main ---
main() {
    echo ""
    echo -e "${BOLD}  SuperAgent Installer${RESET}"
    echo -e "${DIM}  AI Coding Assistant — v${VERSION}${RESET}"
    echo ""

    local os
    os=$(detect_os)
    info "Detected OS: ${os}"

    # Step 1: PHP
    if check_php; then
        success "PHP $(php -r 'echo PHP_VERSION;') found"
    else
        install_php "$os"
    fi

    # Step 2: Composer
    install_composer

    # Step 3: SuperAgent
    install_superagent

    # Step 4: PATH
    configure_path

    # Step 5: Verify
    echo ""
    if command -v superagent &>/dev/null || [ -x "${BIN_DIR}/superagent" ]; then
        success "Installation complete!"
    else
        success "Installation complete! Restart your terminal or run:"
        echo -e "  ${CYAN}export PATH=\"${BIN_DIR}:\$PATH\"${RESET}"
    fi

    echo ""
    echo -e "${BOLD}  Quick start:${RESET}"
    echo -e "    ${CYAN}superagent init${RESET}               Set up your API key"
    echo -e "    ${CYAN}superagent${RESET}                    Start interactive mode"
    echo -e "    ${CYAN}superagent \"fix the bug\"${RESET}      Run a one-shot task"
    echo ""
}

main "$@"
