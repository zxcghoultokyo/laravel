# Secrets folder

This folder is **git-ignored**. Store all local credentials here.

## Usage in test scripts

```bash
# Copy the example and fill in your values:
cp secrets.example.sh secrets.sh
chmod +x secrets.sh
source secrets.sh
```

## Files

| File | Purpose |
|------|---------|
| `secrets.sh` | Shell env vars for test scripts |
| `horoshop.env` | Horoshop API credentials |

## DO NOT commit real credentials to git.
All files in this folder (except `*.example.*` and `README.md`) are gitignored.
