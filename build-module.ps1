<#
.SYNOPSIS
    Собирает zip-модуль MikoPBX из исходников.

.DESCRIPTION
    Кладёт module.json в КОРЕНЬ архива. Если модуль упакован в папку
    ModulePrometheus/, установка в MikoPBX падает с ошибкой
    "module.json not found in the module archive".

    Перед сборкой проверяет, что скрипты bin/*.sh сохранены в LF:
    CRLF в shell-скриптах ломает их на MikoPBX.

    После сборки архив перечитывается: проверяется, что module.json лежит
    в корне и что в именах записей нет обратных слэшей. Compress-Archive
    из PowerShell 5.1 пишет именно обратные слэши, поэтому собираем
    архив через System.IO.Compression.ZipFile.

.EXAMPLE
    .\build-module.ps1
    .\build-module.ps1 -OutputDir dist
#>
[CmdletBinding()]
param(
    [string]$OutputDir = "dist"
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
Push-Location $root

try {
    # --- версия из module.json ---
    $moduleJson = Join-Path $root "module.json"
    if (-not (Test-Path $moduleJson)) {
        throw "module.json не найден в $root"
    }
    $version = (Get-Content $moduleJson -Raw | ConvertFrom-Json).version
    if (-not $version) {
        throw "В module.json нет поля version"
    }

    # --- что попадает в архив ---
    $items = @(
        "App", "Lib", "Messages", "Models", "Setup", "public",
        "agi-bin", "bin", "db", "docs", "examples",
        "composer.json", "module.json",
        "README.md", "README.ru.md", "CHANGELOG.md", "LICENSE"
    )

    foreach ($i in $items) {
        if (-not (Test-Path $i)) {
            Write-Warning "Пропущено (нет такого пути): $i"
        }
    }
    $items = @($items | Where-Object { Test-Path $_ })

    # --- сборка во временной папке, чтобы структура была плоской ---
    $stage = Join-Path ([System.IO.Path]::GetTempPath()) ("mp-" + [guid]::NewGuid().ToString("N"))
    New-Item -ItemType Directory -Path $stage | Out-Null

    try {
        foreach ($i in $items) {
            Copy-Item -LiteralPath $i -Destination $stage -Recurse -Force
        }

        # --- проверки перед упаковкой ---
        if (-not (Test-Path (Join-Path $stage "module.json"))) {
            throw "module.json не оказался в корне архива"
        }

        Get-ChildItem -Path (Join-Path $stage "bin") -Filter *.sh -ErrorAction SilentlyContinue |
            ForEach-Object {
                $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
                for ($i = 0; $i -lt $bytes.Length; $i++) {
                    if ($bytes[$i] -eq 13) {
                        throw "$($_.Name): найден CRLF, shell-скрипты должны быть в LF"
                    }
                }
            }

        # --- архив ---
        $outDir = if ([System.IO.Path]::IsPathRooted($OutputDir)) {
            $OutputDir
        } else {
            Join-Path $root $OutputDir
        }
        New-Item -ItemType Directory -Path $outDir -Force | Out-Null

        $zipPath = Join-Path $outDir "ModulePrometheus-$version.zip"
        if (Test-Path $zipPath) {
            Remove-Item $zipPath -Force
        }

        # Записи добавляем вручную: ZipFile.CreateFromDirectory в Windows
        # PowerShell 5.1 (.NET Framework) пишет в имена записей обратные
        # слэши, а MikoPBX их распаковывает как обычные имена файлов.
        $archive = [System.IO.Compression.ZipFile]::Open($zipPath, "Create")
        try {
            Get-ChildItem -Path $stage -Recurse -File | ForEach-Object {
                $relative = $_.FullName.Substring($stage.Length + 1).Replace("\", "/")
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                    $archive, $_.FullName, $relative, [System.IO.Compression.CompressionLevel]::Optimal
                ) | Out-Null
            }
        }
        finally {
            $archive.Dispose()
        }

        # --- проверка собранного архива ---
        $archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
        try {
            $names = @($archive.Entries | ForEach-Object { $_.FullName })

            if ($names -notcontains "module.json") {
                throw "В архиве нет module.json в корне"
            }

            $bad = @($names | Where-Object { $_ -like "*\*" })
            if ($bad.Count -gt 0) {
                throw "В архиве есть записи с обратными слэшами: $($bad[0])"
            }
        }
        finally {
            $archive.Dispose()
        }

        Write-Host "Собрано: $zipPath" -ForegroundColor Green
        Write-Host ("Размер: {0:N0} байт" -f (Get-Item $zipPath).Length) -ForegroundColor Green
    }
    finally {
        Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
    }
}
finally {
    Pop-Location
}
