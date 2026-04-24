$utf8 = New-Object System.Text.UTF8Encoding $false
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$files = Get-ChildItem resp_G*.json | Sort-Object Name
foreach ($f in $files) {
  $raw = [System.IO.File]::ReadAllText($f.FullName, $utf8)
  try {
    $j = $raw | ConvertFrom-Json -ErrorAction Stop
  } catch {
    Write-Host "=== $($f.Name) (parse error)" -ForegroundColor Red
    Write-Host $raw.Substring(0, [Math]::Min(200, $raw.Length))
    continue
  }
  $id = $f.Name -replace 'resp_|\.json',''
  Write-Host ""
  Write-Host "=== $id  [source=$($j.meta.source), type=$($j.type), products=$($j.products.Count)]" -ForegroundColor Cyan
  if ($j.text) {
    $txt = $j.text
    if ($txt.Length -gt 200) { $txt = $txt.Substring(0,200) + "..." }
    Write-Host "  text: $txt"
  }
  foreach ($p in $j.products) {
    $title = $p.title
    if ($title.Length -gt 60) { $title = $title.Substring(0,60) }
    $cat = $p.category_path
    if ($cat -and $cat.Length -gt 30) { $cat = $cat.Substring(0,30) }
    Write-Host "   - $title  [$cat]"
  }
  # Check for problematic content
  $text = ($j.text + " " + (($j.products | ForEach-Object { $_.title + " " + $_.category_path }) -join " ")).ToLower()
  $bad = @()
  if ($text -match "сертиф") { $bad += "CERT" }
  if ($text -match "рання\s*пташ|новонародж|немовлят|малюкам\s*0") { $bad += "NEWBORN" }
  if ($text -match "фартух|нарукав|підвіск") { $bad += "NON_GIFT" }
  if ($bad.Count -gt 0) {
    Write-Host "   FLAGS: $($bad -join ', ')" -ForegroundColor Yellow
  }
}
