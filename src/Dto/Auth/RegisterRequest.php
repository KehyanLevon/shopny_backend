<?php

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\StrongPassword;

class RegisterRequest
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Invalid email format.')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'Email must not be longer than {{ limit }} characters.'
    )]
    private ?string $email = null;

    #[Assert\NotBlank(message: 'Password is required.')]
    #[Assert\Length(
        min: 8,
        max: 255,
        minMessage: 'Password must be at least {{ limit }} characters long.',
        maxMessage: 'Password must not be longer than {{ limit }} characters.'
    )]
    #[StrongPassword]
    private ?string $password = null;

    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Name must be at least {{ limit }} characters long.',
        maxMessage: 'Name must not be longer than {{ limit }} characters.'
    )]
    private ?string $name = null;

    #[Assert\NotBlank(message: 'Surname is required.')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Surname must be at least {{ limit }} characters long.',
        maxMessage: 'Surname must not be longer than {{ limit }} characters.'
    )]
    private ?string $surname = null;

    public function setEmail(?string $email): void
    {
        $this->email = $email !== null ? trim($email) : null;
    }
    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }
    public function setName(?string $name): void
    {
        $this->name = $name !== null ? trim($name) : null;
    }
    public function setSurname(?string $surname): void
    {
        $this->surname = $surname !== null ? trim($surname) : null;
    }
    public function getEmail(): ?string
    {
        return $this->email;
    }
    public function getPassword(): ?string
    {
        return $this->password;
    }
    public function getName(): ?string
    {
        return $this->name;
    }
    public function getSurname(): ?string
    {
        return $this->surname;
    }
}
