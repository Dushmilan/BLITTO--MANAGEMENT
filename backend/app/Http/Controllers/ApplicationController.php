<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\ApplicationIntake\Disclosure;
use App\Domain\Authorization\EmailPolicy;
use App\Domain\Authorization\Role;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Thin controller: validates input, delegates to the intake domain.
 * Domain rules live in App\Domain\ApplicationIntake.
 */
final class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationIntakeService $intake) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->intake->listVisibleTo($request->user()));
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $this->dropBlankInventorRows($request);

        $validated = $request->validate($this->createRules(), [
            'inventor_name.not_regex' => 'One main author only: single inventor_name, no lists',
            'inventor_email.not_regex' => 'One main author only: single inventor_email, no lists',
        ]);

        $application = $this->intake->createShell(
            Disclosure::fromArray($validated),
            $request->user(),
            $request->ip(),
        );

        if ($request->expectsJson()) {
            return response()->json($application);
        }

        return redirect()
            ->route('admin.patents.show', $application)
            ->with('status', 'Patent application created.');
    }

    public function update(Request $request, string $applicationId): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'technology_area' => ['sometimes', 'string', 'max:255'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No editable fields provided'], 422);
        }

        $application = $this->intake->updateFields(
            $this->intake->findOrFail($applicationId),
            $validated['title'] ?? null,
            $validated['technology_area'] ?? null,
            $request->user(),
            $request->ip(),
        );

        if ($request->expectsJson()) {
            return response()->json($application);
        }

        return back()->with('status', 'Patent details updated.');
    }

    public function history(Request $request, string $applicationId): JsonResponse
    {
        $application = $this->intake->findOrFail($applicationId);
        $viewer = $request->user();

        // Confidentiality (CONTEXT.md invariant 6): a user sees only their own.
        if (Role::from($viewer->role) === Role::USER && $application->inventor_email !== $viewer->email) {
            abort(403, 'Forbidden.');
        }

        return response()->json($this->intake->history($application));
    }

    /**
     * A form renders a fixed set of inventor rows; the empty ones are not input.
     * Dropping them keeps the single-inventor case valid for HTML and JSON alike.
     */
    private function dropBlankInventorRows(Request $request): void
    {
        if (! $request->has('inventors') || ! is_array($request->input('inventors'))) {
            return;
        }

        $rows = array_values(array_filter(
            $request->input('inventors'),
            static fn (mixed $row): bool => is_array($row)
                && (trim((string) ($row['inventor_name'] ?? '')) !== ''
                    || trim((string) ($row['inventor_email'] ?? '')) !== ''),
        ));

        if ($rows === []) {
            $request->request->remove('inventors');

            return;
        }

        $request->merge(['inventors' => $rows]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function createRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'inventor_name' => ['required_without:inventors', 'string', 'max:255', 'not_regex:/[,;]/'],
            'inventor_email' => [
                'required_without:inventors',
                'string',
                'max:255',
                'email',
                'not_regex:/[,;]/',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! EmailPolicy::isInstitution((string) $value)) {
                        $fail(EmailPolicy::error());
                    }
                },
            ],
            'inventors' => ['sometimes', 'array'],
            'inventors.*.inventor_name' => ['required', 'string', 'max:255'],
            'inventors.*.inventor_email' => [
                'required', 'string', 'email', 'max:255',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! EmailPolicy::isInstitution((string) $value)) {
                        $fail(EmailPolicy::error());
                    }
                },
            ],
            'technology_area' => ['sometimes', 'string', 'max:255'],
            'summary' => ['sometimes', 'string'],
        ];
    }
}
