# Going Public — Pre-flight Checklist

This repo previously contained committed secrets that were sanitised in working tree.
**The git history still contains them until you run `git filter-repo` (Step 3).**

## 0. Rotate every secret that ever appeared in history

These values must be considered **compromised** the moment the repo goes public, even after
history rewrite (someone may have cloned it earlier):

| Secret | Where it lived | Rotate by |
|---|---|---|
| Laravel Cloud MySQL password (`5VzvEWz1uhaN7aGUhMuv`) | `.env.export`, `export-products-from-cloud.php` | Laravel Cloud Dashboard → Database → Reset Password |
| `DIAGNOSTIC_SECRET_KEY` (`diagnostic_secret_key_2025`) | code default + many docs/scripts | Generate new random 32+ chars; set in Laravel Cloud env vars |
| Widget token tenant 2 (`zIzYKx8o2RVdT1KYmJAv25FJO5GIbxZj`) | docs + test scripts | Admin panel → Tenants → tenant 2 → Regenerate token |

Generate a strong diagnostic key (PowerShell):
```powershell
[Convert]::ToBase64String((1..32 | %{Get-Random -Max 256}))
```

## 1. Move secrets to runtime stores (NEVER back into git)

We use **Laravel Cloud Environment Variables** as the source of truth for production.
Local development uses `.env` (gitignored).

Required env vars (production):
```
DB_PASSWORD=<rotated>
DIAGNOSTIC_SECRET_KEY=<rotated>
DIAGNOSTIC_ALLOWED_IPS=<your CI/admin IPs, optional>
OPENAI_API_KEY=<sk-proj-...>
ANTHROPIC_API_KEY=<sk-ant-...>   # if used
HOROSHOP_*                       # per tenant, see app config
WAYFORPAY_*                      # billing
TELEGRAM_BOT_TOKEN               # if used
APP_KEY=<base64:...>
```

## 2. Verify working tree is clean

```powershell
# Should print nothing (no real secrets remain in tracked files)
git grep -n -E "diagnostic_secret_key_2025|zIzYKx8o2RVdT1KYmJAv25FJO5GIbxZj|5VzvEWz1uhaN7aGUhMuv|tjqz74bgt2t1eexm"

# Optional: install gitleaks and scan working tree
# choco install gitleaks   (or download release binary)
gitleaks detect --no-banner --redact -c .gitleaks.toml --source .
```

## 3. Rewrite git history (one-shot, destructive)

```powershell
# 1) Back up the repo first
Copy-Item -Recurse -Path . -Destination ../laravel-backup-before-filter

# 2) Install git-filter-repo
pip install git-filter-repo

# 3) Replace all known secret strings in every commit
git filter-repo --replace-text .git-filter-replacements.txt

# 4) Drop the leaked files from history entirely
git filter-repo --path .env.export --path export-products-from-cloud.php --invert-paths --force

# 5) Sanity check
git grep -E "diagnostic_secret_key_2025|5VzvEWz1uhaN7aGUhMuv|zIzYKx8o2RVdT1KYmJAv25FJO5GIbxZj" $(git rev-list --all) ; if ($LASTEXITCODE -eq 0) { Write-Error "STILL FOUND" }

# 6) Re-add origin (filter-repo removes it) and force-push
git remote add origin https://github.com/stovburtm-web/laravel.git
git push --force --all origin
git push --force --tags origin
```

## 4. Tell GitHub to forget cached views of old commits

GitHub keeps unreachable commits cached. After force-pushing:

- Open a support ticket: https://support.github.com/contact (subject: "Remove cached views after force-push to remove secrets")
- OR delete the repository and recreate it (faster, but loses issues/PRs/stars)

## 5. Switch repo visibility to Public

GitHub Settings → General → Danger Zone → Change visibility → Public.

## 6. Enable defenses on the public repo

GitHub Settings:
- ☑ **Secret scanning** (free for public)
- ☑ **Push protection** (Settings → Code security → Secret scanning → Push protection)
- ☑ **Dependabot alerts**
- ☑ Branch protection on `main`: require PR review, require status checks

Local pre-commit (recommended):
```powershell
# Install pre-commit
pip install pre-commit
# We ship .pre-commit-config.yaml — install hooks
pre-commit install
```

## 7. Sanity-test the deployed app

After rotating secrets in Laravel Cloud:
```powershell
# Diagnostic should now require the new key
curl "https://aintento.laravel.cloud/api/diagnostic/db-stats?key=$env:NEW_DIAG_KEY"
# Old key should fail with 401
curl "https://aintento.laravel.cloud/api/diagnostic/db-stats?key=diagnostic_secret_key_2025"
```
