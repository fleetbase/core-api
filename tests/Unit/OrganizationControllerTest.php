<?php

namespace Illuminate\Foundation\Auth\Access {
    if (!trait_exists(AuthorizesRequests::class)) {
        trait AuthorizesRequests
        {
        }
    }
}

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(DispatchesJobs::class)) {
        trait DispatchesJobs
        {
        }
    }
}

namespace Illuminate\Foundation\Validation {
    if (!trait_exists(ValidatesRequests::class)) {
        trait ValidatesRequests
        {
        }
    }
}

namespace {
    use Fleetbase\Http\Controllers\Api\v1\OrganizationController;

    test('public organizations listing supports name query filtering', function () {
        $reflection = new ReflectionClass(OrganizationController::class);
        $source     = file_get_contents($reflection->getFileName());

        expect($source)->toContain("\$searchQuery = trim((string) \$request->input('query', ''));")
            ->and($source)->toContain("->when(\$searchQuery !== ''")
            ->and($source)->toContain("->where('companies.name', 'LIKE', '%' . \$searchQuery . '%')")
            ->and($source)->toContain("->whereHas('users')")
            ->and($source)->toContain("->select(['name', 'public_id'])");
    });
}
