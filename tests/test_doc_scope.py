"""TDD Loop: per-document download/delete must be scoped to the URL application."""

from __future__ import annotations


def test_cross_app_download_is_404(client, md_headers, disclosure_payload) -> None:
    r = client.post("/applications", json=disclosure_payload, headers=md_headers)
    assert r.status_code == 200, r.text
    app_a = r.json()["id"]
    payload_b = dict(disclosure_payload, title="Other app")
    app_b = client.post("/applications", json=payload_b, headers=md_headers).json()["id"]

    up = client.post(
        f"/applications/{app_a}/documents?filename=secret.pdf",
        headers=md_headers, content=b"SECRET-A",
    )
    assert up.status_code == 200, up.text
    doc_id = up.json()["id"]

    cross = client.get(
        f"/applications/{app_b}/documents/{doc_id}/download", headers=md_headers
    )
    assert cross.status_code == 404


def test_cross_app_delete_is_404(client, md_headers, disclosure_payload) -> None:
    app_a = client.post("/applications", json=disclosure_payload, headers=md_headers).json()["id"]
    payload_b = dict(disclosure_payload, title="Other app")
    app_b = client.post("/applications", json=payload_b, headers=md_headers).json()["id"]

    doc_id = client.post(
        f"/applications/{app_a}/documents?filename=secret.pdf",
        headers=md_headers, content=b"SECRET-A",
    ).json()["id"]

    cross = client.delete(
        f"/applications/{app_b}/documents/{doc_id}", headers=md_headers
    )
    assert cross.status_code == 404
    # Original still intact.
    own = client.get(
        f"/applications/{app_a}/documents/{doc_id}/download", headers=md_headers
    )
    assert own.status_code == 200
    assert own.content == b"SECRET-A"
