<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Loggable\Entity\MappedSuperclass;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Loggable\LogEntryInterface;
use Gedmo\Loggable\Loggable;

/**
 * @phpstan-template T of Loggable|object
 *
 * @phpstan-implements LogEntryInterface<T>
 *
 * @ORM\MappedSuperclass
 */
#[ORM\MappedSuperclass]
abstract class AbstractLogEntry implements LogEntryInterface
{
    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    protected int $id;

    /**
     * @phpstan-var self::ACTION_CREATE|self::ACTION_UPDATE|self::ACTION_REMOVE
     *
     * @ORM\Column(type="string", length=8)
     */
    #[ORM\Column(type: Types::STRING, length: 8)]
    protected string $action;

    /**
     * @ORM\Column(name="logged_at", type="datetime")
     */
    #[ORM\Column(name: 'logged_at', type: Types::DATETIME_MUTABLE)]
    protected \DateTime $loggedAt;

    /**
     * @ORM\Column(name="object_id", length=64, nullable=true)
     */
    #[ORM\Column(name: 'object_id', length: 64, nullable: true)]
    protected ?string $objectId;

    /**
     * @var string
     *
     * @phpstan-var class-string<T>
     *
     * @ORM\Column(name="object_class", type="string", length=191)
     */
    #[ORM\Column(name: 'object_class', type: Types::STRING, length: 191)]
    protected string $objectClass;

    /**
     * @ORM\Column(type="integer")
     */
    #[ORM\Column(type: Types::INTEGER)]
    protected int $version;

    /**
     * @var ?array<string, mixed>
     *
     * @ORM\Column(type="array", nullable=true)
     *
     * NOTE: The attribute uses the "array" name directly instead of the constant since it was removed in DBAL 4.0.
     */
    #[ORM\Column(type: 'array', nullable: true)]
    protected ?array $data;

    /**
     * @ORM\Column(length=191, nullable=true)
     */
    #[ORM\Column(length: 191, nullable: true)]
    protected ?string $username;

    /**
     * Get id
     *
     * @return int|null
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get action
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Set action
     */
    public function setAction($action)
    {
        $this->action = $action;
    }

    /**
     * Get object class
     */
    public function getObjectClass()
    {
        return $this->objectClass;
    }

    /**
     * Set object class
     */
    public function setObjectClass($objectClass)
    {
        $this->objectClass = $objectClass;
    }

    /**
     * Get object id
     */
    public function getObjectId()
    {
        return $this->objectId;
    }

    /**
     * Set object id
     *
     * @param string $objectId
     */
    public function setObjectId($objectId)
    {
        $this->objectId = $objectId;
    }

    /**
     * Get username
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Set username
     *
     * @param string $username
     */
    public function setUsername($username)
    {
        $this->username = $username;
    }

    /**
     * Get loggedAt
     */
    public function getLoggedAt()
    {
        return $this->loggedAt;
    }

    /**
     * Set loggedAt to "now"
     */
    public function setLoggedAt()
    {
        $this->loggedAt = new \DateTime();
    }

    /**
     * Get data
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Set data
     */
    public function setData($data)
    {
        $this->data = $data;
    }

    /**
     * Set current version
     *
     * @param int $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * Get current version
     */
    public function getVersion()
    {
        return $this->version;
    }
}
