<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Http\Controllers\Api\Administration\OperationalResetController;
use App\Http\Controllers\Api\Authentication\AuthenticationController;
use App\Http\Controllers\Api\Authorization\ObserverOfficeScopeController;
use App\Http\Controllers\Api\Authorization\RolePermissionController;
use App\Http\Controllers\Api\DocumentManagement\DocumentController;
use App\Http\Controllers\Api\DocumentManagement\DocumentedExpedientEntryController;
use App\Http\Controllers\Api\DocumentManagement\ExpedientAccessController;
use App\Http\Controllers\Api\DocumentManagement\ExpedientController;
use App\Http\Controllers\Api\DocumentManagement\ExpedientInternalAssignmentController;
use App\Http\Controllers\Api\DocumentManagement\ExpedientLifecycleController;
use App\Http\Controllers\Api\DocumentManagement\ExpedientMovementController;
use App\Http\Controllers\Api\DocumentManagement\ExpedientTypeController;
use App\Http\Controllers\Api\DocumentManagement\OfficeDocumentAccessController;
use App\Http\Controllers\Api\DocumentManagement\OfficeDocumentSequenceController;
use App\Http\Controllers\Api\HumanResources\HumanResourcesController;
use App\Http\Controllers\Api\Legislatures\LegislatureController;
use App\Http\Controllers\Api\Organization\OfficeController;
use App\Http\Controllers\Api\Users\UserController;
use App\Http\Controllers\Api\Warehouse\MaterialRequestController;
use App\Http\Controllers\Api\Warehouse\WarehouseCatalogController;
use App\Http\Controllers\Api\Warehouse\WarehouseReceiptController;
use Illuminate\Support\Facades\Route;

// El inicio de sesión es la única operación pública y limita intentos para mitigar fuerza bruta.
Route::post('auth/login', [AuthenticationController::class, 'login'])->middleware(['throttle:6,1', 'no.store']);

// Toda operación posterior exige un token Sanctum perteneciente a una cuenta activa.
Route::middleware(['auth:sanctum', 'active.user', 'no.store'])->group(function (): void {
    // Estas rutas deben seguir disponibles con clave temporal para permitir cambiarla o cerrar sesión.
    Route::get('auth/me', [AuthenticationController::class, 'me']);
    Route::post('auth/logout', [AuthenticationController::class, 'logout']);
    Route::post('auth/password', [AuthenticationController::class, 'changePassword']);

    // El resto del sistema queda bloqueado hasta que el usuario reemplace su contraseña temporal.
    Route::middleware('password.changed')->group(function (): void {
        // Administración de identidades, estado de acceso y roles históricos.
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::patch('users/{user}', [UserController::class, 'update']);
        Route::post('users/{user}/activate', [UserController::class, 'activate']);
        Route::post('users/{user}/inactivate', [UserController::class, 'inactivate']);
        Route::post('users/{user}/emergency-password-reset', [UserController::class, 'resetPassword']);
        Route::post('users/{user}/roles', [UserController::class, 'assignRole']);
        Route::delete('users/{user}/roles/{role}', [UserController::class, 'removeRole'])
            ->whereIn('role', array_column(RoleCode::cases(), 'value'));
        Route::get('authorization/roles', [RolePermissionController::class, 'index']);
        Route::put('authorization/roles/{role}/permissions', [RolePermissionController::class, 'update']);
        Route::get('users/{user}/observer-office-scope', [ObserverOfficeScopeController::class, 'show']);
        Route::put('users/{user}/observer-office-scope', [ObserverOfficeScopeController::class, 'update']);

        // Operación excepcional para limpiar datos beta; la Policy la restringe a superadministración.
        Route::get('administration/operational-reset/summary', [OperationalResetController::class, 'summary']);
        Route::post('administration/operational-reset', [OperationalResetController::class, 'store']);

        // Kardex, contratos, cargos, adjuntos e importación masiva de RR. HH.
        Route::get('human-resources/bootstrap', [HumanResourcesController::class, 'bootstrap']);
        Route::get('human-resources/employees', [HumanResourcesController::class, 'index']);
        Route::get('human-resources/employee-import-template', [HumanResourcesController::class, 'importTemplate']);
        Route::post('human-resources/employees/import', [HumanResourcesController::class, 'import']);
        Route::post('human-resources/employees', [HumanResourcesController::class, 'store']);
        Route::get('human-resources/employees/{employee}', [HumanResourcesController::class, 'show']);
        Route::patch('human-resources/employees/{employee}', [HumanResourcesController::class, 'update']);
        Route::post('human-resources/employees/{employee}/profile-photo', [HumanResourcesController::class, 'storeProfilePhoto']);
        Route::post('human-resources/employees/{employee}/attachments', [HumanResourcesController::class, 'storeAttachment']);
        Route::get('human-resources/offices/{office}/positions', [HumanResourcesController::class, 'positions']);
        Route::post('human-resources/positions', [HumanResourcesController::class, 'storePosition']);
        Route::patch('human-resources/positions/{officePosition}', [HumanResourcesController::class, 'updatePosition']);
        Route::post('human-resources/contracts/{contract}/extend', [HumanResourcesController::class, 'extend']);
        Route::post('human-resources/contracts/{contract}/finish', [HumanResourcesController::class, 'finish']);
        Route::get('human-resources/attachments/{attachment}/download', [HumanResourcesController::class, 'downloadAttachment']);

        // Organigrama y pertenencias históricas de usuarios a oficinas.
        Route::get('offices', [OfficeController::class, 'index']);
        Route::get('offices/directory', [OfficeController::class, 'directory']);
        Route::post('offices', [OfficeController::class, 'store']);
        Route::get('offices/{office}', [OfficeController::class, 'show']);
        Route::patch('offices/{office}', [OfficeController::class, 'update']);
        Route::post('offices/{office}/activate', [OfficeController::class, 'activate']);
        Route::post('offices/{office}/inactivate', [OfficeController::class, 'inactivate']);
        Route::get('offices/{office}/memberships', [OfficeController::class, 'memberships']);
        Route::post('offices/{office}/memberships', [OfficeController::class, 'assignMembership']);
        Route::post('offices/{office}/memberships/{membership}/close', [OfficeController::class, 'closeMembership']);
        Route::get('document-management/offices/{office}/access-setting', [OfficeDocumentAccessController::class, 'show']);
        Route::put('document-management/offices/{office}/access-setting', [OfficeDocumentAccessController::class, 'update']);

        // Catálogos que normalizan la clasificación de expedientes y documentos.
        Route::get('expedient-types', [ExpedientTypeController::class, 'index']);
        Route::post('expedient-types', [ExpedientTypeController::class, 'store']);
        Route::patch('expedient-types/{expedientType}', [ExpedientTypeController::class, 'update']);
        Route::post('expedient-types/{expedientType}/activate', [ExpedientTypeController::class, 'activate']);
        Route::post('expedient-types/{expedientType}/inactivate', [ExpedientTypeController::class, 'inactivate']);
        Route::get('document-types', [ExpedientTypeController::class, 'documentTypes']);
        Route::get('confidentiality-levels', [ExpedientTypeController::class, 'confidentialityLevels']);
        // Núcleo documental: bandejas, altas, tenencia, ciclo de vida y acceso extraordinario.
        Route::get('expedients', [ExpedientController::class, 'index']);
        Route::post('expedient-entries', [DocumentedExpedientEntryController::class, 'store']);
        Route::post('expedients', [ExpedientController::class, 'store']);
        Route::get('expedients/{expedient}', [ExpedientController::class, 'show']);
        Route::get('expedients/{expedient}/access-grants', [ExpedientAccessController::class, 'index']);
        Route::post('expedients/{expedient}/access-grants', [ExpedientAccessController::class, 'store']);
        Route::post('expedients/{expedient}/access-grants/{grant}/close', [ExpedientAccessController::class, 'close']);
        Route::get('expedients/{expedient}/movements', [ExpedientMovementController::class, 'index']);
        Route::post('expedients/{expedient}/movements', [ExpedientMovementController::class, 'store']);
        Route::post('expedients/{expedient}/movement-recipients/{recipient}/status', [ExpedientMovementController::class, 'updateRecipientStatus']);
        Route::get('expedients/{expedient}/movement-recipients/{recipient}/internal-assignments', [ExpedientInternalAssignmentController::class, 'show']);
        Route::put('expedients/{expedient}/movement-recipients/{recipient}/internal-assignments', [ExpedientInternalAssignmentController::class, 'update']);
        Route::post('expedients/{expedient}/archive', [ExpedientLifecycleController::class, 'archive']);
        Route::post('expedients/{expedient}/close', [ExpedientLifecycleController::class, 'close']);
        Route::post('expedients/{expedient}/void', [ExpedientLifecycleController::class, 'void']);
        Route::post('expedients/{expedient}/reopening-requests', [ExpedientLifecycleController::class, 'requestReopening']);
        Route::get('expedients/{expedient}/reopening-requests', [ExpedientLifecycleController::class, 'reopeningRequests']);
        Route::post('expedients/{expedient}/reopening-requests/{reopeningRequest}/approve', [ExpedientLifecycleController::class, 'approveReopening']);
        Route::post('expedients/{expedient}/reopening-requests/{reopeningRequest}/reject', [ExpedientLifecycleController::class, 'rejectReopening']);
        // Documentos, versiones, adjuntos y su relación muchos-a-muchos con derivaciones.
        Route::get('expedients/{expedient}/documents', [DocumentController::class, 'index']);
        Route::post('expedients/{expedient}/documents', [DocumentController::class, 'store']);
        Route::get('expedients/{expedient}/documents/{document}', [DocumentController::class, 'show']);
        Route::patch('expedients/{expedient}/documents/{document}', [DocumentController::class, 'update']);
        Route::post('expedients/{expedient}/documents/{document}/issue', [DocumentController::class, 'issue']);
        Route::post('expedients/{expedient}/documents/{document}/corrections', [DocumentController::class, 'createCorrection']);
        Route::post('expedients/{expedient}/documents/{document}/attachments', [DocumentController::class, 'attach']);
        Route::get('expedients/{expedient}/documents/{document}/attachments/{attachment}/download', [DocumentController::class, 'downloadAttachment']);
        Route::get('expedients/{expedient}/documents/{document}/revisions', [DocumentController::class, 'revisions']);
        Route::post('expedients/{expedient}/documents/{document}/movement-links', [DocumentController::class, 'linkToMovement']);
        Route::get('legislatures/{legislature}/offices/{office}/document-sequence', [OfficeDocumentSequenceController::class, 'show']);
        Route::post('legislatures/{legislature}/offices/{office}/document-sequence', [OfficeDocumentSequenceController::class, 'store']);

        // Períodos legislativos y composición histórica de la Directiva.
        Route::get('legislatures', [LegislatureController::class, 'index']);
        Route::post('legislatures', [LegislatureController::class, 'store']);
        Route::get('legislatures/{legislature}', [LegislatureController::class, 'show']);
        Route::patch('legislatures/{legislature}', [LegislatureController::class, 'update']);
        Route::post('legislatures/{legislature}/activate', [LegislatureController::class, 'activate']);
        Route::post('legislatures/{legislature}/inactivate', [LegislatureController::class, 'inactivate']);
        Route::post('legislatures/{legislature}/board-assignments', [LegislatureController::class, 'replaceBoardMember']);

        // Almacenes reutiliza el expediente como trazabilidad y conserva separado el kardex físico.
        Route::get('warehouse/bootstrap', [WarehouseCatalogController::class, 'bootstrap']);
        Route::post('warehouse/categories', [WarehouseCatalogController::class, 'storeCategory']);
        Route::patch('warehouse/categories/{warehouseCategory}', [WarehouseCatalogController::class, 'updateCategory']);
        Route::post('warehouse/items', [WarehouseCatalogController::class, 'storeItem']);
        Route::patch('warehouse/items/{warehouseItem}', [WarehouseCatalogController::class, 'updateItem']);
        Route::get('warehouse/items/{warehouseItem}/movements', [WarehouseReceiptController::class, 'stockMovements']);
        Route::post('warehouse/items/{warehouseItem}/adjustments', [WarehouseReceiptController::class, 'adjust']);
        Route::get('warehouse/receipts', [WarehouseReceiptController::class, 'index']);
        Route::post('warehouse/receipts', [WarehouseReceiptController::class, 'store']);
        Route::get('warehouse/receipts/{warehouseReceipt}', [WarehouseReceiptController::class, 'show']);
        Route::get('warehouse/receipts/{warehouseReceipt}/attachments/{attachment}/download', [WarehouseReceiptController::class, 'downloadAttachment']);
        Route::get('warehouse/material-requests', [MaterialRequestController::class, 'index']);
        Route::post('warehouse/material-requests', [MaterialRequestController::class, 'store']);
        Route::get('warehouse/material-requests/{materialRequest}', [MaterialRequestController::class, 'show']);
        Route::patch('warehouse/material-requests/{materialRequest}', [MaterialRequestController::class, 'update']);
        Route::post('warehouse/material-requests/{materialRequest}/submit', [MaterialRequestController::class, 'submit']);
        Route::post('warehouse/material-requests/{materialRequest}/decisions', [MaterialRequestController::class, 'decide']);
        Route::post('warehouse/material-requests/{materialRequest}/revisions', [MaterialRequestController::class, 'revise']);
        Route::post('warehouse/material-requests/{materialRequest}/delivery', [MaterialRequestController::class, 'decideDelivery']);
        Route::get('warehouse/material-requests/{materialRequest}/eligible-receivers', [MaterialRequestController::class, 'eligibleReceivers']);
        Route::post('warehouse/material-requests/{materialRequest}/receiver-authorization', [MaterialRequestController::class, 'authorizeReceiver']);
        Route::post('warehouse/material-requests/{materialRequest}/confirm-receipt', [MaterialRequestController::class, 'confirmReceipt']);
        Route::get('warehouse/material-requests/{materialRequest}/act', [MaterialRequestController::class, 'act']);
    });
});
