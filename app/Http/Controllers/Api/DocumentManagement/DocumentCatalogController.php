<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentManagement\ConfidentialityLevelResource;
use App\Http\Resources\DocumentManagement\DocumentTypeResource;
use App\Models\ConfidentialityLevel;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Expone los catálogos necesarios para registrar y clasificar documentos. */
class DocumentCatalogController extends Controller
{
    public function documentTypes(Request $request): AnonymousResourceCollection
    {
        $this->authorizeCatalogAccess($request->user());

        return DocumentTypeResource::collection(DocumentType::query()->active()->orderBy('name')->paginate());
    }

    public function confidentialityLevels(Request $request): AnonymousResourceCollection
    {
        $this->authorizeCatalogAccess($request->user());

        return ConfidentialityLevelResource::collection(
            ConfidentialityLevel::query()->active()->orderBy('sort_order')->paginate(),
        );
    }

    private function authorizeCatalogAccess(User $user): void
    {
        abort_unless(
            $user->hasPermission(PermissionCode::ExpedientsView)
                || $user->hasPermission(PermissionCode::ExpedientsCreate)
                || $user->hasPermission(PermissionCode::ExpedientsProcess),
            403,
        );
    }
}
