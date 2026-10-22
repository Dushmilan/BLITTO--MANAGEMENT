# ADR 0006: Blade, not Livewire, for the L6 UI layer

## Status
Accepted

## Context
The migration design (§5, §8) named "Blade + Livewire 4" as the stack, and
`livewire/livewire` 4.4.7 does declare `illuminate/* ^13.0`, so the dependency is
available. L6 then looked at what the screens actually need: tables with filters
and pagination, detail pages, and forms whose mutations already exist as tested
JSON endpoints. Every one of those is a server round-trip.

## Decision
The UI is Blade + Tailwind with the URL as state (filters, pagination and tabs
are query strings) and mutations as `POST → redirect → flash` against the
existing controllers. JavaScript is ~40 lines in `resources/js/app.js` for the
confirmation `<dialog>`s, the mobile drawer and toast dismissal. No Alpine, no
Livewire, no new runtime or npm dependency; `laravel/dusk` (dev-only) is added
for the browser smoke suite.

Livewire was rejected because it adds a PHP dependency and a second testing
paradigm (`Livewire::test`) to cover interactions the domain endpoints already
serve, and because its payoff — live-updating components — is not something any
screen in scope needs. Alpine was rejected because four interactions do not earn
a dependency when Vite already builds `app.js`.

## Consequences
Every page behaviour is assertable by a Pest HTTP test, and the browser suite is
small enough to run in its own CI job. If a future screen genuinely needs
live-updating data, the decision to revisit is this one — not a rewrite.
