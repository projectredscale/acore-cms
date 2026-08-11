<?php

namespace ACore\Manager\Auth;

use ACore\Manager\AcoreConnector\AcoreManagerTrait;
use ACore\Manager\Auth\Entity\AccountAccessEntity;
use ACore\Manager\Auth\Entity\AccountBannedEntity;
use ACore\Manager\Auth\Entity\AccountEntity;
use ACore\Manager\Auth\Entity\AccountCoinsEntity;
use ACore\Manager\Auth\Entity\AccountCoinsLogEntity;

class AuthManager {

    use AcoreManagerTrait;

    public function __construct() {
        $this->entityPath = realpath(__DIR__ . "/../Entity/");
    }

    /**
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @return ACore\Manager\Auth\Repository\AccountRepository
     */
    public function getAccountRepo($em)
    {
        return $em->getRepository(AccountEntity::class);
    }

    /**
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @return ACore\Manager\Auth\Repository\AccountBannedRepository
     */
    public function getAccountBannedRepo($em)
    {
        return $em->getRepository(AccountBannedEntity::class);
    }

    /**
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @return ACore\Manager\Auth\Repository\AccountAccessEntity
     */
    public function getAccountAccessRepo($em)
    {
        return $em->getRepository(AccountAccessEntity::class);
    }

    /**
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @return ACore\Manager\Auth\Repository\AccountCoinsRepository
     */
    public function getAccountCoinsRepo($em)
    {
        return $em->getRepository(AccountCoinsEntity::class);
    }

    /**
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @return ACore\Manager\Auth\Repository\AccountCoinsLogRepository
     */
    public function getAccountCoinsLogRepo($em)
    {
        return $em->getRepository(AccountCoinsLogEntity::class);
    }
}
