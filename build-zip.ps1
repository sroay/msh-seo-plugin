$pluginDir = "C:\Users\techn\Desktop\Apps and Projects\Kompense Marketing\wordpress-plugin\msh-seo"
$dest = "C:\Users\techn\Desktop\Apps and Projects\Kompense Marketing\wordpress-plugin\msh-seo-v29.zip"
$tempBase = "C:\Users\techn\Desktop\Apps and Projects\Kompense Marketing\wordpress-plugin\zip-staging"
$tempDir = "$tempBase\msh-seo"

# Clean up
if (Test-Path $dest) { Remove-Item $dest -Force }
if (Test-Path $tempBase) { Remove-Item -Recurse -Force $tempBase }

# Create structure - msh-seo folder inside staging
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

# Copy only what the plugin needs (no node_modules, no src)
Copy-Item "$pluginDir\msh-seo.php" $tempDir
Copy-Item "$pluginDir\readme.txt" $tempDir
Copy-Item "$pluginDir\package.json" $tempDir
Copy-Item -Recurse "$pluginDir\includes" "$tempDir\includes"
Copy-Item -Recurse "$pluginDir\build" "$tempDir\build"
Copy-Item -Recurse "$pluginDir\assets" "$tempDir\assets"

# Create ZIP from contents of staging dir (so ZIP root is msh-seo/)
Compress-Archive -Path "$tempBase\*" -DestinationPath $dest

# Cleanup
Remove-Item -Recurse -Force $tempBase

$item = Get-Item $dest
Write-Host "Created: $($item.Name) ($([math]::Round($item.Length / 1024))KB)"
