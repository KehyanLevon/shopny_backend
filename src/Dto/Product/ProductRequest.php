<?php

namespace App\Dto\Product;

use App\Enum\ProductStatus;
use Symfony\Component\Validator\Constraints as Assert;

class ProductRequest
{
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Title must be at least {{ limit }} characters long.',
        maxMessage: 'Title must not be longer than {{ limit }} characters.'
    )]
    private ?string $title = null;

    #[Assert\Length(
        max: 5000,
        maxMessage: 'Description must not be longer than {{ limit }} characters.'
    )]
    private ?string $description = null;

    #[Assert\NotBlank(message: 'Price is required.')]
    #[Assert\Type(type: 'numeric', message: 'Price must be numeric.')]
    #[Assert\GreaterThan(value: 0, message: 'Price must be greater than zero.')]
    private mixed $price = null;

    #[Assert\Type(type: 'numeric', message: 'Discount price must be numeric.')]
    #[Assert\GreaterThanOrEqual(value: 0, message: 'Discount price cannot be negative.')]
    private mixed $discountPrice = null;

    #[Assert\NotNull(message: 'categoryId is required.')]
    #[Assert\Positive(message: 'categoryId must be a positive integer.')]
    private ?int $categoryId = null;

    #[Assert\Choice(
        choices: ProductStatus::VALUES,
        message: 'Invalid status value. Allowed: active, draft, out_of_stock.'
    )]
    private ?string $status = null;

    #[Assert\Choice(
        choices: [true, false],
        message: 'isActive must be a boolean (true or false).'
    )]
    private mixed $isActive = null;

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title !== null ? trim($title) : null;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description !== null ? trim($description) : null;
    }

    public function getPrice(): ?string
    {
        return $this->price !== null ? (string) $this->price : null;
    }

    public function setPrice(mixed $price): void
    {
        if ($price === null || $price === '') {
            $this->price = null;
            return;
        }

        $this->price = (string) $price;
    }

    public function getDiscountPrice(): ?string
    {
        return $this->discountPrice !== null ? (string) $this->discountPrice : null;
    }

    public function setDiscountPrice(mixed $discountPrice): void
    {
        if ($discountPrice === null || $discountPrice === '') {
            $this->discountPrice = null;
            return;
        }

        $this->discountPrice = (string) $discountPrice;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function setCategoryId(mixed $categoryId): void
    {
        if ($categoryId === null || $categoryId === '') {
            $this->categoryId = null;
            return;
        }

        if (is_numeric($categoryId)) {
            $this->categoryId = (int) $categoryId;
        } else {
            $this->categoryId = 0;
        }
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): void
    {
        $this->status = $status !== null ? trim($status) : null;
    }

    public function getIsActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(mixed $isActive): void
    {
        $this->isActive = $isActive;
    }
}
