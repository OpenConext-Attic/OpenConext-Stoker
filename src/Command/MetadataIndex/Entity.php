<?php

namespace OpenConext\Stoker\Command\MetadataIndex;

class Entity
{
    const TYPE_SP = 'sp';
    const TYPE_IDP = 'idp';

    protected static $TYPES = array(self::TYPE_IDP, self::TYPE_SP);

    public $entityId;
    public $types = array();
    public $displayNameNl;
    public $displayNameEn;

    public function __construct($entityId, $types, $displayNameEn, $displayNameNl)
    {
        $this->displayNameEn = $displayNameEn;
        $this->displayNameNl = $displayNameNl;
        $this->entityId = $entityId;
        $this->types = $types;
    }
}