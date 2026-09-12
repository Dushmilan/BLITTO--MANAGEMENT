"""Packaging regression: scripts/ must be an importable distribution package.

CI installs the distribution and runs bare pytest (no CWD on sys.path), so
app/main.py's `from scripts.seed import seed_demo_data` requires scripts/ to
be a real package included in the build. Without it CI dies in collection:
ModuleNotFoundError: No module named 'scripts' (exit 4).
"""


def test_seed_demo_data_importable_via_scripts_package() -> None:
    from scripts.seed import seed_demo_data

    assert callable(seed_demo_data)


def test_persistence_helpers_importable_via_scripts_package() -> None:
    from scripts.persistence import load_dev_state, save_dev_state

    assert callable(load_dev_state)
    assert callable(save_dev_state)
