"""The integration tests need DDEV up, so the default run leaves them out; `-m integration` runs them."""

from pathlib import Path

INTEGRATION_DIR = Path(__file__).parent / "integration"


def pytest_ignore_collect(collection_path, config):
    if collection_path == INTEGRATION_DIR and (config.getoption("-m") or "").strip() != "integration":
        return True
    return None
