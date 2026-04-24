$utf8 = New-Object System.Text.UTF8Encoding $false
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

function Test-Bavka($id, $msg, $sid) {
  $body = @{message=$msg; session_id=$sid; tenant_id=20} | ConvertTo-Json -Compress
  $path = "$PWD\req_$id.json"
  [System.IO.File]::WriteAllText($path, $body, $utf8)
  $resp = curl.exe -s -X POST "https://aintento.laravel.cloud/api/chat" -H "Content-Type: application/json; charset=utf-8" --data-binary "@$path" --max-time 90
  [System.IO.File]::WriteAllText("$PWD\resp_$id.json", $resp, $utf8)
  Remove-Item $path
}

$ts = [int][double]::Parse((Get-Date -UFormat %s))
$tests = @(
  @("G1", "щось на подарунок на рік"),
  @("G2", "подарунковий набір для малюка рік"),
  @("G3", "подарунок на рочок"),
  @("G4", "що подарувати на годик"),
  @("G5", "подарунок на 1 рік хлопчику"),
  @("G6", "подарунковий сертифікат"),
  @("G7", "іграшки для дитини 6 місяців")
)

foreach ($t in $tests) {
  $id = $t[0]
  $msg = $t[1]
  $sid = "test_${ts}_$id"
  Write-Host ("=== {0} : {1}" -f $id, $msg)
  Test-Bavka $id $msg $sid
}

Write-Host "Done." -ForegroundColor Green
