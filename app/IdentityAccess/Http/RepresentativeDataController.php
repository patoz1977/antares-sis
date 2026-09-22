<?php

declare(strict_types=1);

namespace App\IdentityAccess\Http;

use App\Controllers\Controller;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextUnavailable;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentFamilySelectionRequired;
use App\Enrollment\Application\RepresentativePortal\GetRepresentativeEnrollmentPortalState;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Http\RepresentativeFamilySummaryProvider;
use App\IdentityAccess\Application\FamilyContext;

final class RepresentativeDataController extends Controller
{
    public function __construct(
        private readonly GetRepresentativeEnrollmentPortalState $getState,
        private readonly RepresentativeFamilySummaryProvider $familySummary,
    ) {
    }

    public function index(): string
    {
        try {
            $state = $this->getState->handle();
            $context = $state->context;
            $members = $this->familySummary->forContext(new FamilyContext(
                $context->userId,
                $context->representativePersonId,
                $context->representativeId,
                $context->familyId,
                $context->familyDisplayName,
            ));
            if ($members === null) {
                return $this->forbidden();
            }
        } catch (RepresentativeEnrollmentFamilySelectionRequired) {
            header('Location: /representative');
            http_response_code(302);

            return '';
        } catch (RepresentativeEnrollmentContextUnavailable|FamilyNotFound|InvalidPersistedFamilyResult) {
            return $this->forbidden();
        }

        http_response_code(200);

        return $this->view('representative-portal.data', [
            'title' => 'Actualización de datos',
            'state' => $state,
            'members' => $members,
        ]);
    }

    private function forbidden(): string
    {
        http_response_code(403);

        return $this->view('representative-portal.forbidden', [
            'title' => 'Actualización de datos no disponible',
        ]);
    }
}
