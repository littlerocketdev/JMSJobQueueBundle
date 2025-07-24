<?php

namespace JMS\JobQueueBundle\Entity\Type;

use Symfony\Component\TypeInfo\Type\ObjectType;

class SafeObjectType extends ObjectType
{
    public function getSQLDeclaration(array $fieldDeclaration, \Doctrine\DBAL\Platforms\AbstractPlatform $platform)
    {
        return $platform->getBlobTypeDeclarationSQL($fieldDeclaration);
    }

    public function getName(): string
    {
        return 'jms_job_safe_object';
    }

    public function requiresSQLCommentHint(\Doctrine\DBAL\Platforms\AbstractPlatform $platform): bool
    {
        return true;
    }
}
