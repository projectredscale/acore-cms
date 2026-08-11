<?php

namespace ACore\Manager\Auth\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ACore\Manager\Auth\Entity\AccountCoinsEntity
 *
 * @ORM\Entity(repositoryClass="ACore\Manager\Auth\Repository\AccountCoinsRepository")
 * @ORM\Table(name="account_coins")
 */
class AccountCoinsEntity {

    /**
     * @var int
     *
     * @ORM\Column(name="account_id", type="integer")
     * @ORM\Id
     */
    protected $account_id;

    /**
     * @var int
     *
     * @ORM\Column(name="balance", type="integer")
     */
    protected $balance;

    public function getAccountId() {
        return $this->account_id;
    }

    public function setAccountId($account_id) {
        $this->account_id = $account_id;
        return $this;
    }

    public function getBalance() {
        return $this->balance;
    }

    public function setBalance($balance) {
        $this->balance = $balance;
        return $this;
    }
}
