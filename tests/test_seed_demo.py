"""TDD Loop: seed_demo_data must create apps with real inventor emails."""

from __future__ import annotations


def test_seed_demo_data_uses_real_inventor_emails(monkeypatch) -> None:
    from app.core.config import settings
    from app.modules.application_intake.local import LocalApplicationIntakeModule
    from app.modules.audit.local import LocalAuditModule
    from app.modules.authorization.local import LocalAuthorizationModule
    from app.modules.docketing.local import LocalDocketingModule
    from app.modules.document_vault.local import LocalDocumentVaultModule
    from app.modules.filing_workflow.local import LocalFilingWorkflowModule
    from app.modules.notification.local import LocalNotificationModule
    from app.modules.prosecution.local import LocalProsecutionModule
    from scripts.seed import seed_demo_data

    monkeypatch.setattr(settings, "demo_inventor1_email", "seed-a@pdn.ac.lk")
    monkeypatch.setattr(settings, "demo_inventor1_password", "pw")
    monkeypatch.setattr(settings, "demo_inventor1_name", "Seed A")
    monkeypatch.setattr(settings, "demo_inventor2_email", "")
    monkeypatch.setattr(settings, "demo_inventor2_password", "")
    monkeypatch.setattr(settings, "demo_inventor2_name", "")

    auth = LocalAuthorizationModule()
    intake = LocalApplicationIntakeModule()
    docketing = LocalDocketingModule()
    vault = LocalDocumentVaultModule()
    pros = LocalProsecutionModule(docketing=docketing, document_vault=vault)
    notif = LocalNotificationModule()
    audit = LocalAuditModule()
    filing = LocalFilingWorkflowModule(
        application_intake=intake, notification=notif, audit=audit
    )
    seed_demo_data(auth, intake, pros, notif, filing)

    apps = intake.list_applications()
    assert apps, "seed should create applications"
    assert all(a.inventor_email == "seed-a@pdn.ac.lk" for a in apps), [
        (a.title, a.inventor_email) for a in apps
    ]
