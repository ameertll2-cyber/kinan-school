$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

$php = Get-Command php.exe -ErrorAction SilentlyContinue
if (-not $php) {
    $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.NTS.8.4_*" -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
        Select-Object -First 1
}
if (-not $php) {
    throw 'PHP غير مثبت'
}
$phpExe = if ($php.Source) { $php.Source } else { $php.FullName }
$phpDir = Split-Path -Parent $phpExe

& $phpExe -d "extension_dir=$phpDir\ext" -d extension=pdo_sqlite -d extension=sqlite3 tests/check-db.php

$server = Start-Process -FilePath $phpExe -ArgumentList "-d", "extension_dir=$phpDir\ext", "-d", "extension=pdo_sqlite", "-d", "extension=sqlite3", "-d", "extension=sodium", "-d", "extension=openssl", "-S", "127.0.0.1:8080", "-t", "." -PassThru -WindowStyle Hidden
try {
    Start-Sleep -Seconds 2
    $base = 'http://127.0.0.1:8080'

    $adminSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest -UseBasicParsing -WebSession $adminSession "$base/?page=login"
    $csrf = [regex]::Match($login.Content, 'name="csrf" value="([^"]+)"').Groups[1].Value
    Invoke-WebRequest -UseBasicParsing -WebSession $adminSession -Method Post -Body @{
        csrf=$csrf; action='login'; username='admin'; password='123456'
    } "$base/?page=login" | Out-Null

    $pages = @(
        'dashboard','students','staff','classes','subjects','attendance','grades','accounting',
        'parents','curriculum','learning','correspondence','office','counseling','backup','security','password'
    )
    foreach ($page in $pages) {
        $response = Invoke-WebRequest -UseBasicParsing -WebSession $adminSession "$base/?page=$page"
        if ($response.StatusCode -ne 200) { throw "فشل صفحة $page" }
    }

    $exports = @('students','grades','attendance','word-students')
    foreach ($kind in $exports) {
        $response = Invoke-WebRequest -UseBasicParsing -WebSession $adminSession "$base/?office_export=$kind"
        if ($response.StatusCode -ne 200) { throw "فشل تصدير $kind" }
    }

    $parentSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest -UseBasicParsing -WebSession $parentSession "$base/?page=login"
    $csrf = [regex]::Match($login.Content, 'name="csrf" value="([^"]+)"').Groups[1].Value
    Invoke-WebRequest -UseBasicParsing -WebSession $parentSession -Method Post -Body @{
        csrf=$csrf; action='login'; username='parent'; password='123456'
    } "$base/?page=login" | Out-Null
    foreach ($page in @('parent_portal','notifications')) {
        $response = Invoke-WebRequest -UseBasicParsing -WebSession $parentSession "$base/?page=$page"
        if ($response.StatusCode -ne 200) { throw "فشل صفحة ولي الأمر $page" }
    }

    Write-Output 'نجح فحص التدخين لكل الصفحات والتصديرات والأدوار'
}
finally {
    Stop-Process -Id $server.Id -ErrorAction SilentlyContinue
}
