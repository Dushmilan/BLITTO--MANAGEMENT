<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\ApplicationIntake\Disclosure;
use App\Domain\Authorization\EmailPolicy;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller: validates input, delegates to the intake domain.
 * Domain rules live in App\Domain\ApplicationIntake.
 */
final class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationIntakeService $intake) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->createRules(), [
            'inventor_name.not_regex' => 'One main author only: single inventor_name, no lists',
            'inventor_email.not_regex' => 'One main author only: single inventor_email, no lists',
        ]);

        return response()->json($this->intake->createShell(
            Disclosure::fromArray($validated),
            $request->user(),
            $request->ip(),
        ));
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
            'inventors.*.inventor_email' => ['required', 'string', 'email', 'max:255'],
            'technology_area' => ['sometimes', 'string', 'max:255'],
            'summary' => ['sometimes', 'string'],
        ];
    }
}
