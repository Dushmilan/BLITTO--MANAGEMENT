.PHONY: install run graph test check format

install:
	pip install -e ".[dev]"
	pre-commit install

run:
	uvicorn app.main:app --reload --port 8000

graph: ## Regenerate the architecture knowledge graph (graphify)
	python scripts/run_graphify.py

caveman-fix: ## Apply caveman-review fixes to staged changes (opencode)
	python scripts/caveman_fix.py --apply

test:
	pytest

test-cov: ## Run suite with coverage gate (fail under 80%)
	pytest --cov=app --cov-report=term-missing -q

test-e2e: ## Playwright journeys vs live server (no browsers needed)
	pytest tests/journeys -q

check: test graph

format:  ## Byte-compile all modules (cross-platform)
	python -m compileall -q app
