<?php

// Classes/Domain/Model/Immo.php
declare(strict_types=1);

namespace Mbx\DevquestSite\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

final class Immo extends AbstractEntity
{
    protected float $coldRent = 0.0;
    protected float $warmRent = 0.0;
    protected float $area = 0.0;
    protected int $rooms = 0;
    protected string $street = '';
    protected string $postalCode = '';
    protected string $city = '';

    public function __toString(): string
    {
        return implode('; ', [
            $this->street,
            $this->postalCode,
            $this->city,
            $this->coldRent,
            $this->warmRent,
            $this->area,
            $this->rooms,
        ]);
    }

    public function getColdRent(): float
    {
        return $this->coldRent;
    }

    public function setColdRent(float $coldRent): self
    {
        $this->coldRent = $coldRent;

        return $this;
    }

    public function getWarmRent(): float
    {
        return $this->warmRent;
    }

    public function setWarmRent(float $warmRent): self
    {
        $this->warmRent = $warmRent;

        return $this;
    }

    public function getArea(): float
    {
        return $this->area;
    }

    public function setArea(float $area): self
    {
        $this->area = $area;

        return $this;
    }

    public function getRooms(): int
    {
        return $this->rooms;
    }

    public function setRooms(int $rooms): self
    {
        $this->rooms = $rooms;

        return $this;
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function setStreet(string $street): self
    {
        $this->street = $street;

        return $this;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): self
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): self
    {
        $this->city = $city;

        return $this;
    }
}
