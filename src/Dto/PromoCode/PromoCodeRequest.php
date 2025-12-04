<?php

namespace App\Dto\PromoCode;

use App\Enum\ProductStatus;
use App\Enum\PromoScopeType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PromoCodeRequest
{
    #[Assert\NotBlank(message: 'Code is required.')]
    #[Assert\Length(
        min: 2,
        max: 64,
        minMessage: 'Code must be at least {{ limit }} characters long.',
        maxMessage: 'Code must not be longer than {{ limit }} characters.'
    )]
    private ?string $code = null;

    #[Assert\Length(
        max: 5000,
        maxMessage: 'Description must not be longer than {{ limit }} characters.'
    )]
    private ?string $description = null;

    #[Assert\NotBlank(message: 'scopeType is required.')]
    #[Assert\Choice(
        choices: PromoScopeType::VALUES,
        message: 'scopeType must be one of: all, section, category, product.'
    )]
    private ?string $scopeType = null;

    #[Assert\NotBlank(message: 'discountPercent is required.')]
    #[Assert\Type(type: 'numeric', message: 'discountPercent must be a number.')]
    #[Assert\Range(
        notInRangeMessage: 'discountPercent must be between {{ min }} and {{ max }}.',
        min: 0,
        max: 100
    )]
    private mixed $discountPercent = null;

    #[Assert\Choice(
        choices: [true, false],
        message: 'isActive must be a boolean (true or false).'
    )]
    private mixed $isActive = null;

    #[Assert\Regex(
        pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
        message: 'startsAt must be a valid datetime in format YYYY-MM-DDTHH:MM.'
    )]
    private ?string $startsAt = null;

    #[Assert\Regex(
        pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
        message: 'expiresAt must be a valid datetime in format YYYY-MM-DDTHH:MM.'
    )]
    private ?string $expiresAt = null;

    #[Assert\Positive(message: 'sectionId must be a positive integer.')]
    private ?int $sectionId = null;

    #[Assert\Positive(message: 'categoryId must be a positive integer.')]
    private ?int $categoryId = null;

    #[Assert\Positive(message: 'productId must be a positive integer.')]
    private ?int $productId = null;

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code !== null ? trim($code) : null;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description !== null ? trim($description) : null;
    }

    public function getScopeType(): ?string
    {
        return $this->scopeType;
    }

    public function setScopeType(?string $scopeType): void
    {
        $this->scopeType = $scopeType !== null ? trim($scopeType) : null;
    }

    public function getDiscountPercent(): mixed
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(mixed $discountPercent): void
    {
        $this->discountPercent = $discountPercent;
    }

    public function getIsActive(): ?bool
    {
        return $this->isActive === null ? null : (bool) $this->isActive;
    }

    public function setIsActive(mixed $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getStartsAt(): ?string
    {
        return $this->startsAt;
    }

    public function setStartsAt(?string $startsAt): void
    {
        $this->startsAt = $startsAt !== null && $startsAt !== '' ? trim($startsAt) : null;
    }

    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?string $expiresAt): void
    {
        $this->expiresAt = $expiresAt !== null && $expiresAt !== '' ? trim($expiresAt) : null;
    }

    public function getSectionId(): ?int
    {
        return $this->sectionId;
    }

    public function setSectionId(?int $sectionId): void
    {
        $this->sectionId = $sectionId;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function setCategoryId(?int $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function setProductId(?int $productId): void
    {
        $this->productId = $productId;
    }

    #[Assert\Callback]
    public function validateRelations(ExecutionContextInterface $context): void
    {
        if ($this->scopeType === null) {
            return;
        }

        if ($this->scopeType === 'section' && $this->sectionId === null) {
            $context->buildViolation('sectionId is required when scopeType=section.')
                ->atPath('sectionId')
                ->addViolation();
        }

        if ($this->scopeType === 'category' && $this->categoryId === null) {
            $context->buildViolation('categoryId is required when scopeType=category.')
                ->atPath('categoryId')
                ->addViolation();
        }

        if ($this->scopeType === 'product' && $this->productId === null) {
            $context->buildViolation('productId is required when scopeType=product.')
                ->atPath('productId')
                ->addViolation();
        }

        if ($this->startsAt !== null && $this->expiresAt !== null) {
            try {
                $starts  = new \DateTimeImmutable($this->startsAt);
                $expires = new \DateTimeImmutable($this->expiresAt);
            } catch (\Exception) {
                return;
            }

            if ($expires < $starts) {
                $context->buildViolation('expiresAt must be greater than or equal to startsAt.')
                    ->atPath('expiresAt')
                    ->addViolation();
            }
        }
    }
}
