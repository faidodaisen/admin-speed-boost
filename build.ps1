$src   = 'C:\laragon\www\admin-speed-boost'
$stage = Join-Path $env:TEMP 'asb-build'
$slug  = 'wp-admin-speedboost'
$dist  = Join-Path $src 'dist'

# Version comes from the plugin header, so the zip can never disagree with the code.
$header = Get-Content (Join-Path $src "$slug.php") -Raw
$ver    = [regex]::Match($header, '(?m)^\s*\*\s*Version:\s*(.+?)\s*$').Groups[1].Value
if (-not $ver) { throw 'Could not read Version from plugin header' }

if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Path $stage -Force | Out-Null

$target = Join-Path $stage $slug
New-Item -ItemType Directory -Path $target -Force | Out-Null

# Ship only what the plugin needs at runtime.
$include = @('wp-admin-speedboost.php','uninstall.php','readme.txt')
foreach ($f in $include) { Copy-Item (Join-Path $src $f) $target }
foreach ($d in @('includes','modules','assets')) {
  Copy-Item (Join-Path $src $d) $target -Recurse
}

# Belt and braces: nothing dev-related should have slipped in.
$junk = Get-ChildItem $target -Recurse -Force |
  Where-Object { $_.Name -match '^(\.git|node_modules|dist)$' -or
                 $_.Name -match '^_' -or
                 $_.Extension -in @('.bat','.ps1','.log','.zip') }
if ($junk) { $junk | Remove-Item -Recurse -Force }

if (-not (Test-Path $dist)) { New-Item -ItemType Directory -Path $dist | Out-Null }
$zip = Join-Path $dist "$slug-$ver.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }

# Not Compress-Archive: on Windows PowerShell it writes backslash path separators,
# which the zip spec forbids and which breaks extraction on Linux hosts. Entries
# are added by hand so the archive always uses forward slashes.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$archive = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
try {
  Get-ChildItem $target -Recurse -File | Sort-Object FullName | ForEach-Object {
    $rel = $_.FullName.Substring($stage.Length).TrimStart('\').Replace('\', '/')
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
      $archive, $_.FullName, $rel, [System.IO.Compression.CompressionLevel]::Optimal
    ) | Out-Null
  }
} finally {
  $archive.Dispose()
}

Remove-Item $stage -Recurse -Force

# Verify separators before declaring success.
$check = [System.IO.Compression.ZipFile]::OpenRead($zip)
$bad   = @($check.Entries | Where-Object { $_.FullName -match '\\' }).Count
$count = $check.Entries.Count
$roots = @($check.Entries | ForEach-Object { ($_.FullName -split '/')[0] } | Sort-Object -Unique)
$check.Dispose()

if ($bad)             { throw "$bad entries use backslash separators" }
if ($roots.Count -ne 1 -or $roots[0] -ne $slug) { throw "Bad root folder: $($roots -join ', ')" }

"version: $ver"
"zip:     $zip"
"files:   $count, single root folder '$($roots[0])', separators OK"
"size:    {0:N0} KB" -f ((Get-Item $zip).Length / 1KB)
