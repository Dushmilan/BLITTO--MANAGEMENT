"""Seed demo data for local development.

Called from main.py lifespan on startup when no users exist yet.
Creates 2 inventor accounts and 6 patent applications at various lifecycle stages.
"""

from __future__ import annotations

import uuid
from datetime import datetime, timezone

from app.core.config import settings
from app.domain.common import ApplicationStatus, Role
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import RegisterRequest
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure
from app.modules.filing_workflow.local import LocalFilingWorkflowModule
from app.modules.filing_workflow.models import FilingRecord
from app.modules.prosecution.local import LocalProsecutionModule
from app.modules.prosecution.models import OfficeActionKind
from app.modules.notification.local import LocalNotificationModule


def seed_demo_data(
    auth: LocalAuthorizationModule,
    intake: LocalApplicationIntakeModule,
    prosecution: LocalProsecutionModule,
    notification: LocalNotificationModule | None = None,
    filing_workflow: LocalFilingWorkflowModule | None = None,
) -> None:
    """Populate in-memory stores with demo users and patent applications."""
    now = datetime.now(timezone.utc)

    inv1_email = settings.demo_inventor1_email
    inv1_password = settings.demo_inventor1_password
    inv1_name = settings.demo_inventor1_name
    inv2_email = settings.demo_inventor2_email
    inv2_password = settings.demo_inventor2_password
    inv2_name = settings.demo_inventor2_name

    if inv1_email and inv1_password:
        auth.register(
            RegisterRequest(
                email=inv1_email,
                role=Role.INVENTOR,
                password=inv1_password,
            )
        )
    if inv2_email and inv2_password:
        auth.register(
            RegisterRequest(
                email=inv2_email,
                role=Role.INVENTOR,
                password=inv2_password,
            )
        )

    _seed_app(
        intake, prosecution, filing_workflow,
        title="Solar Desalination Membrane",
        inventor_name=inv1_name,
        inventor_email=inv1_email,
        status=ApplicationStatus.DRAFT,
        now=now,
    )

    _seed_app(
        intake, prosecution, filing_workflow,
        title="AI-Powered Crop Disease Detection",
        inventor_name=inv1_name,
        inventor_email=inv1_email,
        status=ApplicationStatus.FILED,
        application_number="P/2025/0142",
        nipo_reference="NIPO-2025-0142",
        now=now,
    )

    _seed_app(
        intake, prosecution, filing_workflow,
        title="Biodegradable Medical Stents",
        inventor_name=inv2_name,
        inventor_email=inv2_email,
        status=ApplicationStatus.EXAMINATION,
        application_number="P/2024/0891",
        nipo_reference="NIPO-2024-0891",
        office_action=(
            OfficeActionKind.OBJECTION,
            "Objection: claims 3-5 lack inventive step over prior art US2023/0145671. "
            "Applicant has 90 days to respond.",
        ),
        now=now,
    )

    _seed_app(
        intake, prosecution, filing_workflow,
        title="Quantum Encryption Protocol",
        inventor_name=inv2_name,
        inventor_email=inv2_email,
        status=ApplicationStatus.GRANTED,
        application_number="P/2024/0312",
        nipo_reference="NIPO-2024-0312",
        office_action=(
            OfficeActionKind.ALLOWANCE,
            "All claims allowed. Patent granted under NIPO Act No. 15 of 2024.",
        ),
        now=now,
    )

    _seed_app(
        intake, prosecution, filing_workflow,
        title="Low-Cost Water Purification System",
        inventor_name=inv1_name,
        inventor_email=inv1_email,
        status=ApplicationStatus.ACKNOWLEDGED,
        application_number="P/2024/1205",
        nipo_reference="NIPO-2024-1205",
        office_action=(
            OfficeActionKind.REJECTION,
            "Rejection: claims 1-2 anticipated by prior art. Claims 6-8 allowed. "
            "Applicant may file divisional application.",
        ),
        now=now,
    )

    _seed_app(
        intake, prosecution, filing_workflow,
        title="Smart Agriculture IoT Sensor Network",
        inventor_name=inv2_name,
        inventor_email=inv2_email,
        status=ApplicationStatus.REJECTED,
        application_number="P/2024/0567",
        nipo_reference="NIPO-2024-0567",
        office_action=(
            OfficeActionKind.REJECTION,
            "Final rejection: all claims rejected under Section 35. No response filed within deadline.",
        ),
        now=now,
    )

    # Seed sample notifications for demo users
    if notification is not None:
        if inv1_email:
            notification.send_notification(
                inv1_email,
                "Welcome to BLITTO",
                "Your account has been created. You can now view and track your patent applications.",
            )
            notification.send_notification(
                inv1_email,
                "Patent Filed",
                "Your patent application AI-Powered Crop Disease Detection has been successfully filed with reference P/2025/0142.",
            )
        if inv2_email:
            notification.send_notification(
                inv2_email,
                "Welcome to BLITTO",
                "Your account has been created. You can now view and track your patent applications.",
            )
            notification.send_notification(
                inv2_email,
                "Patent Received",
                "Congratulations! Your patent application Quantum Encryption Protocol has been granted under NIPO-2024-0312.",
            )


def _seed_app(
    intake: LocalApplicationIntakeModule,
    prosecution: LocalProsecutionModule,
    filing_workflow: LocalFilingWorkflowModule | None = None,
    *,
    title: str,
    inventor_name: str,
    inventor_email: str,
    status: ApplicationStatus,
    application_number: str | None = None,
    nipo_reference: str | None = None,
    office_action: tuple[OfficeActionKind, str] | None = None,
    now: datetime,
) -> None:
    disclosure = Disclosure(
        inventor_name=inventor_name,
        inventor_email=inventor_email,
        title=title,
        summary=f"Demo patent application: {title}",
    )
    app = intake.submit_disclosure(disclosure)
    app.application_number = application_number
    app.nipo_reference = nipo_reference

    status_order = [
        ApplicationStatus.DRAFT,
        ApplicationStatus.FILED,
        ApplicationStatus.ACKNOWLEDGED,
        ApplicationStatus.EXAMINATION,
        ApplicationStatus.GRANTED,
    ]
    if status == ApplicationStatus.REJECTED:
        target_path = [
            ApplicationStatus.FILED,
            ApplicationStatus.ACKNOWLEDGED,
            ApplicationStatus.EXAMINATION,
            ApplicationStatus.REJECTED,
        ]
    else:
        idx = status_order.index(status)
        target_path = status_order[1 : idx + 1]

    for s in target_path:
        intake.change_status(app.id, s, changed_by="admin@blitto.local")

    if office_action is not None:
        kind, body = office_action
        action = prosecution.receive_office_action(app.id, kind, body)

        if kind != OfficeActionKind.ALLOWANCE:
            prosecution.file_response(
                app.id,
                action.id,
                f"Response to {kind.value}: Applicant respectfully submits arguments "
                f"and amendments addressing the examiner's concerns.",
            )

    if filing_workflow is not None and status != ApplicationStatus.DRAFT:
        now_utc = datetime.now(timezone.utc)
        record = FilingRecord(
            id=str(uuid.uuid4()),
            application_id=app.id,
            filed_by="admin@blitto.local",
            filed_date=now_utc,
        )
        if status in (ApplicationStatus.ACKNOWLEDGED, ApplicationStatus.EXAMINATION, ApplicationStatus.GRANTED, ApplicationStatus.REJECTED):
            record.nipo_acknowledged_at = now_utc
            record.nipo_acknowledged_by = "admin@blitto.local"
        if status == ApplicationStatus.GRANTED:
            record.granted_at = now_utc
            record.granted_by = "admin@blitto.local"
            record.patent_number = application_number
        if status == ApplicationStatus.REJECTED:
            record.rejected_at = now_utc
            record.rejected_by = "admin@blitto.local"
        filing_workflow._records[app.id] = record
