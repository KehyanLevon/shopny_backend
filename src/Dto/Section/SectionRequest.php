<?php

namespace App\Dto\Section;

use Symfony\Component\Validator\Constraints as Assert;

class SectionRequest
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

    public function getIsActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(mixed $value): void
    {
        $this->isActive = $value;
    }
}
