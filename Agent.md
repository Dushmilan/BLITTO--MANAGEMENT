# NOTE (FastAPI reimplementation)
# This repo is being reimplemented as a FastAPI + Python backend (no DB, all local).
# The 7-module + seams design below is preserved in Python under `app/`:
#   - each module = `app/modules/<name>/interface.py` (Protocol) + `local/` in-memory adapter
#   - authorization adds a `better_auth/` production adapter (JWT/JWKS) per ADR-0002
#   - seams: `app/adapters/document_storage/`, `app/adapters/email/`
#   - architecture is managed with graphify (ADR-0001): `make graph`, pre-commit hook
# Java package names below are the original design intent; Python equivalents are used.

project:
  name: "BLITTO Patent Management System"
  domain: "patent-management"
  vocabulary_file: "CONTEXT.md"
  architecture_file: "docs/architecture/ARCHITECTURE.md"

modules:
  - name: "applicationIntake"
    interface: "com.blitto.patent.modules.applicationIntake.ApplicationIntakeModule"
    seam_category: "in-process"
    priority: 3
    
  - name: "documentVault"
    interface: "com.blitto.patent.modules.documentVault.DocumentVaultModule"
    seam_category: "ports-and-adapters"
    priority: 1
    
  - name: "prosecution"
    interface: "com.blitto.patent.modules.prosecution.ProsecutionModule"
    seam_category: "in-process"
    priority: 5
    depends_on: ["documentVault"]
    
  - name: "authorization"
    interface: "com.blitto.patent.modules.authorization.AuthorizationModule"
    seam_category: "local-substitutable"
    priority: 4
    
  - name: "notification"
    interface: "com.blitto.patent.modules.notification.NotificationModule"
    seam_category: "true-external"
    priority: 6
    
  - name: "portfolioAnalytics"
    interface: "com.blitto.patent.modules.portfolioAnalytics.PortfolioAnalyticsModule"
    seam_category: "local-substitutable"
    priority: 7

seams:
  database:
    category: "local-substitutable"
    interface: "app/adapters/database/DatabaseAdapter (not yet needed - no DB)"
    production: "in-memory store inside each module's local/ adapter"
    test: "in-memory store inside each module's local/ adapter"

  documentStorage:
    category: "ports-and-adapters"
    interface: "app/adapters/document_storage/interface.py:DocumentStoreAdapter"
    production: "S3DocumentStoreAdapter (future)"
    test: "app/adapters/document_storage/local/LocalDocumentStore (current)"

  email:
    category: "true-external"
    interface: "app/adapters/email/interface.py:EmailProviderAdapter"
    production: "SendGridEmailAdapter (future)"
    test: "app/adapters/email/local/LocalEmailProvider (current)"

vocabularies:
  domain: "CONTEXT.md"
  architecture: "LANGUAGE.md"
  adrs: "docs/adr/"

task_patterns:
  add_feature:
    - "Define interface method in module"
    - "Add to in-memory test adapter first"
    - "Write test at module interface"
    - "Implement production adapter"
    - "Run full test suite"

review_checklist:
  - "Deletion test passes?"
  - "Two adapters exist for every seam?"
  - "Tests at module interface only?"
  - "Domain vocabulary from CONTEXT.md used?"
  - "No 'service', 'component', 'boundary' terms?"

priority_order:
  - "documentVault"
  - "applicationIntake"
  - "authorization"
  - "prosecution"
  - "notification"
  - "portfolioAnalytics"