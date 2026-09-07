"""Institution-mail policy (single source of truth for email gating)."""

from __future__ import annotations


def normalize_email(email: str) -> str:
    return email.strip().lower()


def email_domain(email: str) -> str:
    parts = _split(email)
    return parts[1] if parts else ""


def _split(email: str) -> tuple[str, str] | None:
    """Split a normalized address, or None if malformed.

    Requires exactly one '@', a non-empty local part, and no whitespace.
    """
    addr = normalize_email(email)
    if addr.count("@") != 1:
        return None
    local, _, domain = addr.partition("@")
    if not local or not domain:
        return None
    if any(ch.isspace() for ch in addr):
        return None
    return local, domain


def is_institution_email(email: str, allowed_domains: list[str] | None = None) -> bool:
    """True if email belongs to one of the allowed institution domains.

    Subdomains match: mail@sci.pdn.ac.lk is accepted when pdn.ac.lk is allowed.
    """
    from app.core.config import settings

    domains = allowed_domains if allowed_domains is not None else settings.allowed_email_domain_list
    domains = [d.lower().lstrip("@") for d in domains]
    parts = _split(email)
    if parts is None:
        return False
    _, domain = parts
    return any(domain == d or domain.endswith("." + d) for d in domains)


INSTITUTION_EMAIL_ERROR = "Institution email required (allowed: {domains})"


def institution_error() -> str:
    from app.core.config import settings

    return INSTITUTION_EMAIL_ERROR.format(domains=", ".join(settings.allowed_email_domain_list))
