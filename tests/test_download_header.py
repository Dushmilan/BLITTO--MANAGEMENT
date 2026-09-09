"""TDD Loop: per-document download must sanitize Content-Disposition filename."""

from __future__ import annotations


def test_per_document_download_sanitizes_filename(client, md_headers, disclosure_payload) -> None:
    app_id = client.post("/applications", json=disclosure_payload, headers=md_headers).json()["id"]
    doc_id = client.post(
        f"/applications/{app_id}/documents",
        params={"filename": 'quote"x.pdf'},
        headers=md_headers, content=b"x",
    ).json()["id"]
    dl = client.get(
        f"/applications/{app_id}/documents/{doc_id}/download", headers=md_headers
    )
    assert dl.status_code == 200
    cd = dl.headers.get("content-disposition", "")
    assert cd.count('"') == 2, cd  # only the wrapping pair
    assert "\r" not in cd and "\n" not in cd
