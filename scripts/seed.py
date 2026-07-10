"""Seed demo data for local development.

Called from main.py lifespan on startup when no users exist yet.
Creates 2 inventor accounts and 6 patent applications at various lifecycle stages.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone

from app.core.config import settings
from app.domain.common import ApplicationStatus, Role
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import RegisterRequest
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure
from app.modules.docketing.local import LocalDocketingModule
from app.modules.docketing.models import DeadlineType
from app.modules.prosecution.local import LocalProsecutionModule
from app.modules.prosecution.models import OfficeActionKind


def seed_demo_data(
    auth: LocalAuthorizationModule,
    intake: LocalApplicationIntakeModule,
    docketing: LocalDocketingModule,
    prosecution: LocalProsecutionModule,
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
        intake, docketing, prosecution,
        title="Solar Desalination Membrane",
        inventor_name=inv1_name,
        inventor_email=inv1_email,
        status=ApplicationStatus.DRAFT,
        now=now,
    )

    _seed_app(
        intake, docketing, prosecution,
        title="AI-Powered Crop Disease Detection",
        inventor_name=inv1_name,
        inventor_email=inv1_email,
        status=ApplicationStatus.FILED,
        application_number="P/2025/0142",
        nipo_reference="NIPO-2025-0142",
        filing_deadline=now + timedelta(days=60),
        now=now,
    )

    _seed_app(
        intake, docketing, prosecution,
        title="Biodegradable Medical Stents",
        inventor_name=inv2_name,
        inventor_email=inv2_email,
        status=ApplicationStatus.EXAMINATION,
        application_number="P/2024/0891",
        nipo_reference="NIPO-2024-0891",
        filing_deadline=now - timedelta(days=30),
        office_action=(
            OfficeActionKind.OBJECTION,
            "Objection: claims 3-5 lack inventive step over prior art US2023/0145671. "
            "Applicant has 90 days to respond.",
        ),
        now=now,
    )

    _seed_app(
        intake, docketing, prosecution,
        title="Quantum Encryption Protocol",
        inventor_name=inv2_name,
        inventor_email=inv2_email,
        status=ApplicationStatus.GRANTED,
        application_number="P/2024/0312",
        nipo_reference="NIPO-2024-0312",
        filing_deadline=now - timedelta(days=120),
        office_action=(
            OfficeActionKind.ALLOWANCE,
            "All claims allowed. Patent granted under NIPO Act No. 15 of 2024.",
        ),
        deadlines_met=True,
        now=now,
    )

    _seed_app(
        intake, docketing, prosecution,
        title="Low-Cost Water Purification System",
        inventor_name=inv1_name,
        inventor_email=inv1_email,
        status=ApplicationStatus.PUBLISHED,
        application_number="P/2024/1205",
        nipo_reference="NIPO-2024-1205",
        filing_deadline=now - timedelta(days=60),
        office_action=(
            OfficeActionKind.REJECTION,
            "Rejection: claims 1-2 anticipated by prior art. Claims 6-8 allowed. "
            "Applicant may file divisional application.",
        ),
        now=now,
    )

    _seed_app(
        intake, docketing, prosecution,
        title="Smart Agriculture IoT Sensor Network",
        inventor_name=inv2_name,
        inventor_email=inv2_email,
        status=ApplicationStatus.REJECTED,
        application_number="P/2024/0567",
        nipo_reference="NIPO-2024-0567",
        filing_deadline=now - timedelta(days=90),
        office_action=(
            OfficeActionKind.REJECTION,
            "Final rejection: all claims rejected under Section 35. No response filed within deadline.",
        ),
        missed_deadline=True,
        now=now,
    )


def _seed_app(
    intake: LocalApplicationIntakeModule,
    docketing: LocalDocketingModule,
    prosecution: LocalProsecutionModule,
    *,
    title: str,
    inventor_name: str,
    inventor_email: str,
    status: ApplicationStatus,
    application_number: str | None = None,
    nipo_reference: str | None = None,
    filing_deadline: datetime | None = None,
    office_action: tuple[OfficeActionKind, str] | None = None,
    deadlines_met: bool = False,
    missed_deadline: bool = False,
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
        ApplicationStatus.PUBLISHED,
        ApplicationStatus.EXAMINATION,
        ApplicationStatus.GRANTED,
    ]
    if status == ApplicationStatus.REJECTED:
        target_path = [
            ApplicationStatus.FILED,
            ApplicationStatus.PUBLISHED,
            ApplicationStatus.EXAMINATION,
            ApplicationStatus.REJECTED,
        ]
    else:
        idx = status_order.index(status)
        target_path = status_order[1 : idx + 1]

    for s in target_path:
        intake.change_status(app.id, s, changed_by="admin@blitto.local")

    if filing_deadline is not None:
        deadline = docketing.add_deadline(
            app.id, DeadlineType.FILING, filing_deadline
        )
        if deadlines_met:
            docketing.mark_met(deadline.id)
        elif missed_deadline:
            docketing.mark_missed(deadline.id)

    if office_action is not None:
        kind, body = office_action
        action = prosecution.receive_office_action(app.id, kind, body)

        if kind != OfficeActionKind.ALLOWANCE and not missed_deadline:
            prosecution.file_response(
                app.id,
                action.id,
                f"Response to {kind.value}: Applicant respectfully submits arguments "
                f"and amendments addressing the examiner's concerns.",
            )
            response_deadlines = [
                d
                for d in docketing.list_deadlines(app.id)
                if d.type == DeadlineType.RESPONSE
            ]
            if response_deadlines:
                docketing.mark_met(response_deadlines[-1].id)
