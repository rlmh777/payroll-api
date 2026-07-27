# Deploy (GitHub Actions)

Push to:

- `dev` → Azure **dev/test** (`rg-payroll-test`)
- `main` → Azure **prod** (`rg-payroll-prod`)

Workflow: `.github/workflows/deploy.yml`

Configure GitHub Environment variables as documented in the monorepo `infra/azure/CI-CD.md`.
