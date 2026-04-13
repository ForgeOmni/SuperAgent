#Requires -Version 5.1
<#
.SYNOPSIS
    SuperAgent Installer for Windows

.DESCRIPTION
    One-command installer that:
    1. Checks for PHP >= 8.1, installs via winget/scoop/choco if missing
    2. Checks for Composer, installs if missing
    3. Installs SuperAgent globally
    4. Adds to system PATH

.EXAMPLE
    irm https://raw.githubusercontent.com/forgeomni/superagent/main/install.ps1 | iex

    Or:
    powershell -ExecutionPolicy Bypass -File install.ps1
#>

$ErrorActionPreference = "Stop"
$Version = "0.8.2"
$RequiredPHP = "8.1"
$InstallDir = if ($env:SUPERAGENT_HOME) { $env:SUPERAGENT_HOME } else { "$env:USERPROFILE\.superagent" }
$BinDir = "$InstallDir\bin"

# --- Helpers ---
function Write-Info($msg)    { Write-Host "==> " -ForegroundColor Cyan -NoNewline; Write-Host $msg }
function Write-Ok($msg)      { Write-Host "  ✓ " -ForegroundColor Green -NoNewline; Write-Host $msg }
function Write-Warn($msg)    { Write-Host "  ⚠ " -ForegroundColor Yellow -NoNewline; Write-Host $msg }
function Write-Err($msg)     { Write-Host "  ✗ " -ForegroundColor Red -NoNewline; Write-Host $msg; exit 1 }

# --- Check PHP ---
function Test-PHP {
    try {
        $phpPath = Get-Command php -ErrorAction SilentlyContinue
        if (-not $phpPath) { return $false }

        $version = & php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>$null
        if (-not $version) { return $false }

        $parts = $version.Split('.')
        $major = [int]$parts[0]
        $minor = [int]$parts[1]
        $reqParts = $RequiredPHP.Split('.')
        $reqMajor = [int]$reqParts[0]
        $reqMinor = [int]$reqParts[1]

        return ($major -gt $reqMajor) -or (($major -eq $reqMajor) -and ($minor -ge $reqMinor))
    } catch {
        return $false
    }
}

# --- Install PHP ---
function Install-PHP {
    Write-Info "Installing PHP >= $RequiredPHP..."

    # Try winget first (Windows 11+ / Windows 10 with App Installer)
    if (Get-Command winget -ErrorAction SilentlyContinue) {
        Write-Info "Using winget..."
        try {
            & winget install PHP.PHP.8.4 --accept-source-agreements --accept-package-agreements --silent 2>$null
            # winget installs PHP to Program Files, refresh PATH
            $env:Path = [System.Environment]::GetEnvironmentVariable("Path", "Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path", "User")

            if (Test-PHP) {
                Write-Ok "PHP installed via winget"
                return
            }
        } catch {
            Write-Warn "winget installation failed, trying alternatives..."
        }
    }

    # Try scoop
    if (Get-Command scoop -ErrorAction SilentlyContinue) {
        Write-Info "Using scoop..."
        & scoop install php 2>$null
        if (Test-PHP) {
            Write-Ok "PHP installed via scoop"
            return
        }
    }

    # Try chocolatey
    if (Get-Command choco -ErrorAction SilentlyContinue) {
        Write-Info "Using chocolatey..."
        & choco install php -y --no-progress 2>$null
        # Refresh PATH
        $env:Path = [System.Environment]::GetEnvironmentVariable("Path", "Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path", "User")
        if (Test-PHP) {
            Write-Ok "PHP installed via chocolatey"
            return
        }
    }

    # Manual download as last resort
    Write-Info "Downloading PHP directly..."
    $arch = if ([System.Environment]::Is64BitOperatingSystem) { "x64" } else { "x86" }
    $phpZipUrl = "https://windows.php.net/downloads/releases/latest/php-8.4-Win32-vs17-$arch-latest.zip"
    $phpDir = "$InstallDir\php"
    $phpZip = "$env:TEMP\php.zip"

    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -Uri $phpZipUrl -OutFile $phpZip -UseBasicParsing

        if (-not (Test-Path $phpDir)) { New-Item -ItemType Directory -Path $phpDir -Force | Out-Null }
        Expand-Archive -Path $phpZip -DestinationPath $phpDir -Force
        Remove-Item $phpZip -Force

        # Enable required extensions
        $phpIni = "$phpDir\php.ini"
        if (-not (Test-Path $phpIni)) {
            Copy-Item "$phpDir\php.ini-production" $phpIni
        }

        $iniContent = Get-Content $phpIni -Raw
        @("curl", "mbstring", "openssl", "pdo_sqlite", "zip", "fileinfo") | ForEach-Object {
            $iniContent = $iniContent -replace ";extension=$_", "extension=$_"
        }
        # Set extension dir
        $iniContent = $iniContent -replace ';?\s*extension_dir\s*=\s*"ext"', "extension_dir = `"$phpDir\ext`""
        Set-Content $phpIni $iniContent

        # Add PHP to current session PATH
        $env:Path = "$phpDir;$env:Path"

        # Add to user PATH permanently
        $userPath = [System.Environment]::GetEnvironmentVariable("Path", "User")
        if ($userPath -notlike "*$phpDir*") {
            [System.Environment]::SetEnvironmentVariable("Path", "$phpDir;$userPath", "User")
        }

        if (Test-PHP) {
            Write-Ok "PHP installed to $phpDir"
        } else {
            Write-Err "PHP installation failed. Please install PHP >= $RequiredPHP manually from https://windows.php.net"
        }
    } catch {
        Write-Err "Failed to download PHP: $_`nPlease install PHP >= $RequiredPHP manually from https://windows.php.net"
    }
}

# --- Install Composer ---
function Install-Composer {
    if (Get-Command composer -ErrorAction SilentlyContinue) {
        Write-Ok "Composer already installed"
        return
    }

    Write-Info "Installing Composer..."

    $composerSetup = "$env:TEMP\composer-setup.php"
    $composerBat = "$BinDir\composer.bat"
    $composerPhar = "$InstallDir\composer.phar"

    try {
        # Download installer
        & php -r "copy('https://getcomposer.org/installer', '$($composerSetup.Replace('\','\\'))');"

        # Verify signature
        $expectedSig = (Invoke-WebRequest -Uri "https://composer.github.io/installer.sig" -UseBasicParsing).Content.Trim()
        $actualSig = & php -r "echo hash_file('sha384', '$($composerSetup.Replace('\','\\'))');"

        if ($expectedSig -ne $actualSig) {
            Remove-Item $composerSetup -Force -ErrorAction SilentlyContinue
            Write-Err "Composer installer signature mismatch."
        }

        # Run installer
        & php $composerSetup --install-dir="$InstallDir" --filename=composer.phar --quiet
        Remove-Item $composerSetup -Force -ErrorAction SilentlyContinue

        # Create batch wrapper
        if (-not (Test-Path $BinDir)) { New-Item -ItemType Directory -Path $BinDir -Force | Out-Null }
        Set-Content $composerBat "@echo off`nphp `"$composerPhar`" %*"

        # Add to PATH for current session
        $env:Path = "$BinDir;$env:Path"

        Write-Ok "Composer installed"
    } catch {
        Write-Err "Failed to install Composer: $_"
    }
}

# --- Install SuperAgent ---
function Install-SuperAgent {
    Write-Info "Installing SuperAgent v$Version..."

    if (-not (Test-Path $InstallDir)) { New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null }
    if (-not (Test-Path $BinDir)) { New-Item -ItemType Directory -Path $BinDir -Force | Out-Null }

    $env:COMPOSER_HOME = "$InstallDir\.composer"

    try {
        & composer global require "forgeomni/superagent:*" --no-interaction --quiet 2>$null

        # Find where composer put the binary
        $composerBinDir = & composer global config bin-dir --absolute 2>$null
        if (-not $composerBinDir) { $composerBinDir = "$env:COMPOSER_HOME\vendor\bin" }

        # Create superagent.bat in our bin dir
        $superagentBat = "$BinDir\superagent.bat"
        if (Test-Path "$composerBinDir\superagent") {
            Set-Content $superagentBat "@echo off`nphp `"$composerBinDir\superagent`" %*"
        } elseif (Test-Path "$composerBinDir\superagent.bat") {
            Copy-Item "$composerBinDir\superagent.bat" $superagentBat -Force
        } else {
            Write-Warn "Composer global install path not found, trying source install..."
            Install-SuperAgentFromSource
            return
        }

        Write-Ok "SuperAgent installed"
    } catch {
        Write-Warn "Composer registry unavailable, installing from source..."
        Install-SuperAgentFromSource
    }
}

function Install-SuperAgentFromSource {
    $sourceDir = "$InstallDir\source"

    if (Test-Path $sourceDir) { Remove-Item $sourceDir -Recurse -Force }

    & git clone --depth 1 https://github.com/forgeomni/superagent.git $sourceDir 2>$null
    Set-Location $sourceDir
    & composer install --no-dev --no-interaction --quiet
    Set-Location $env:USERPROFILE

    $superagentBat = "$BinDir\superagent.bat"
    Set-Content $superagentBat "@echo off`nphp `"$sourceDir\bin\superagent`" %*"

    Write-Ok "SuperAgent installed from source"
}

# --- Configure PATH ---
function Set-SuperAgentPath {
    $userPath = [System.Environment]::GetEnvironmentVariable("Path", "User")

    if ($userPath -like "*$BinDir*") {
        return  # Already in PATH
    }

    Write-Info "Adding SuperAgent to PATH..."

    [System.Environment]::SetEnvironmentVariable("Path", "$BinDir;$userPath", "User")
    $env:Path = "$BinDir;$env:Path"

    Write-Ok "Added $BinDir to user PATH"
}

# --- Main ---
function Main {
    Write-Host ""
    Write-Host "  SuperAgent Installer" -ForegroundColor White
    Write-Host "  AI Coding Assistant — v$Version" -ForegroundColor DarkGray
    Write-Host ""

    # Step 1: PHP
    if (Test-PHP) {
        $phpVer = & php -r "echo PHP_VERSION;"
        Write-Ok "PHP $phpVer found"
    } else {
        Install-PHP
    }

    # Step 2: Composer
    Install-Composer

    # Step 3: SuperAgent
    Install-SuperAgent

    # Step 4: PATH
    Set-SuperAgentPath

    # Step 5: Done
    Write-Host ""
    Write-Ok "Installation complete!"
    Write-Host ""
    Write-Host "  Quick start:" -ForegroundColor White
    Write-Host "    superagent init" -ForegroundColor Cyan -NoNewline; Write-Host "               Set up your API key"
    Write-Host "    superagent" -ForegroundColor Cyan -NoNewline; Write-Host "                    Start interactive mode"
    Write-Host "    superagent `"fix the bug`"" -ForegroundColor Cyan -NoNewline; Write-Host "      Run a one-shot task"
    Write-Host ""
    Write-Host "  Restart your terminal for PATH changes to take effect." -ForegroundColor DarkGray
    Write-Host ""
}

Main
