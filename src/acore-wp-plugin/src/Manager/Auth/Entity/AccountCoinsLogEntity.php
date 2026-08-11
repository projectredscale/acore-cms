<?php

namespace ACore\Manager\Auth\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ACore\Manager\Auth\Entity\AccountCoinsLogEntity
 *
 * @ORM\Entity(repositoryClass="ACore\Manager\Auth\Repository\AccountCoinsLogRepository")
 * @ORM\Table(name="account_coins_log")
 */
class AccountCoinsLogEntity {

    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var int
     *
     * @ORM\Column(name="account_id", type="integer")
     */
    protected $account_id;

    /**
     * @var int
     *
     * @ORM\Column(name="amount", type="integer")
     */
    protected $amount;

    /**
     * @var string
     *
     * @ORM\Column(name="reason", type="string")
     */
    protected $reason;

    /**
     * @var string
     *
     * @ORM\Column(name="order_id", type="string", nullable=true)
     */
    protected $order_id;

    /**
     * @var DateTime
     *
     * @ORM\Column(name="created_at", type="datetime", columnDefinition="TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP")
     */
    protected $created_at;

    public function getId() {
        return $this->id;
    }

    public function getAccountId() {
        return $this->account_id;
    }

    public function setAccountId($account_id) {
        $this->account_id = $account_id;
        return $this;
    }

    public function getAmount() {
        return $this->amount;
    }

    public function setAmount($amount) {
        $this->amount = $amount;
        return $this;
    }

    public function getReason() {
        return $this->reason;
    }

    public function setReason($reason) {
        $this->reason = $reason;
        return $this;
    }

    public function getOrderId() {
        return $this->order_id;
    }

    public function setOrderId($order_id) {
        $this->order_id = $order_id;
        return $this;
    }

    public function getCreatedAt() {
        return $this->created_at;
    }

    public function setCreatedAt($created_at) {
        $this->created_at = $created_at;
        return $this;
    }
}
