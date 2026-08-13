<?php

namespace App\Domain\HumanResources\Enums;

enum EmployeeAttachmentType: string
{
    case RejapCertificate = 'rejap_certificate';
    case CenviCertificate = 'cenvi_certificate';
    case ElectoralRegistryCertificate = 'electoral_registry_certificate';
    case ProfilePhoto = 'profile_photo';
    case OtherSupportingDocument = 'other_supporting_document';

    public function label(): string
    {
        return match ($this) {
            self::RejapCertificate => 'Certificado REJAP',
            self::CenviCertificate => 'Certificado CENVI',
            self::ElectoralRegistryCertificate => 'Certificado de padrón biométrico electoral',
            self::ProfilePhoto => 'Fotografía de perfil',
            self::OtherSupportingDocument => 'Otro documento de respaldo',
        };
    }
}
