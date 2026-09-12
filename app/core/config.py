"""Application configuration (local-first, no external services)."""

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="BLITTO_", env_file=".env", extra="ignore")

    service_name: str = "blitto-patent-management"
    environment: str = "local"
    # better-auth production adapter (JWT/JWKS verification). Unused by local stub.
    better_auth_jwks_url: str = ""
    better_auth_issuer: str = ""
    better_auth_audience: str = ""
    # Secret for the local-stub auth adapter (better-auth handles secrets in prod).
    auth_secret: str = "change-me-in-production-32byte-minimum!"
    # Institution-mail gate: only these domains may register/log in.
    # Env: BLITTO_ALLOWED_EMAIL_DOMAINS="pdn.ac.lk,sci.pdn.ac.lk" (comma-separated).
    allowed_email_domains: str = "pdn.ac.lk"

    @property
    def allowed_email_domain_list(self) -> list[str]:
        return [
            d.strip().lower().lstrip("@")
            for d in self.allowed_email_domains.split(",")
            if d.strip()
        ]
    # Optional first-admin bootstrap so the system is usable out-of-box.
    bootstrap_admin_email: str = ""
    bootstrap_admin_password: str = ""
    # Demo inventor accounts for local development.
    demo_inventor1_email: str = ""
    demo_inventor1_password: str = ""
    demo_inventor1_name: str = ""
    demo_inventor2_email: str = ""
    demo_inventor2_password: str = ""
    demo_inventor2_name: str = ""
    # How often (in hours) to check for unacknowledged filings and notify admin.
    nipo_follow_up_interval_hours: int = 24
    # Vault master key (provided via env/secret manager; never persisted by the app).
    vault_master_key: str = ""
    document_store: str = "local"          # "local" | "pki"
    vault_cert_dir: str = "certs"
    vault_storage_dir: str = "encrypted_docs"
    vault_ttl_hours: int = 12


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
